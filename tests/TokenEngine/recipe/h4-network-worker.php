<?php

declare(strict_types=1);

use Faluss\Platform\Fans\PfContract\ClosedSnapshotClient;
use Faluss\Platform\Fans\PfContract\ClosedSnapshotInbox;
use Faluss\Platform\Fans\PfContract\ClosedSnapshotInboxSchema;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\SignedEnvelope;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\SnapshotEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\SnapshotTransport;

global $wpdb, $table_prefix;
SnapshotEnvironment::assertIsolated($wpdb, FALUSS_PLATFORM_ROLE);
if (!defined('WP_CLI') || !WP_CLI) { throw new RuntimeException('Recipe CLI only.'); }
require_once WP_PLUGIN_DIR . '/faluss-platform/src/Federation/Legacy/includes/class-faluss-federation-crypto.php';
$root = dirname(rtrim(ABSPATH, '/'));
$input = json_decode(file_get_contents($args[0]), true, 32, JSON_THROW_ON_ERROR);
if (!empty($input['fault'])) {
    /** Private failure injection around a real Fans checkpoint COMMIT. */
    $wpdb = new class(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST) extends wpdb {
        public string $fault = '';
        public string $marker = '';
        public function query($query)
        {
            if ($this->fault === 'page-insert' && preg_match('/^INSERT INTO `[^`]+fans_pf_h4_pages` /', $query) === 1) {
                $this->fault = ''; return false;
            }
            if (strtoupper(trim($query)) !== 'COMMIT' || $this->fault === '') { return parent::query($query); }
            $fault = $this->fault; $this->fault = '';
            if ($fault === 'before-commit') { $this->pause(); }
            $result = parent::query($query);
            if ($fault === 'after-commit') { $this->pause(); }
            return $fault === 'lost-commit-ack' ? false : $result;
        }
        private function pause(): void
        {
            file_put_contents($this->marker, 'ready');
            $until = microtime(true) + 25;
            while (microtime(true) < $until) { usleep(10000); }
            throw new RuntimeException('Fixture controller did not terminate worker.');
        }
    };
    $wpdb->set_prefix($table_prefix);
    $wpdb->fault = $input['fault'];
    $wpdb->marker = $input['marker'];
}
try {
    if (in_array($input['action'], ['install', 'ready'], true)) {
        if ($input['action'] === 'install') { ClosedSnapshotInboxSchema::installForRecipe($wpdb); }
        $result = ['ready' => ClosedSnapshotInboxSchema::ready($wpdb)];
    } elseif ($input['action'] === 'sessions') {
        $previous = json_decode(file_get_contents($root . '/h3-sessions.json'), true, 32, JSON_THROW_ON_ERROR);
        foreach ($previous['sessions'] as $name => $session) {
            wp_set_current_user($session['id']);
            $token = WP_Session_Tokens::get_instance($session['id'])->create(time() + 3600);
            $expiry = $name === 'expired' ? time() - 10 : time() + 3600;
            $cookie = wp_generate_auth_cookie($session['id'], $expiry, 'logged_in', $token);
            $_COOKIE[LOGGED_IN_COOKIE] = $cookie;
            $previous['sessions'][$name]['cookie'] = LOGGED_IN_COOKIE . '=' . $cookie;
            $previous['sessions'][$name]['nonce'] = wp_create_nonce('wp_rest');
        }
        foreach (['fan', 'creator'] as $name) {
            $id = wp_insert_user(['user_login' => 'h4_fixture_' . $name, 'user_pass' => wp_generate_password(40),
                'user_email' => 'h4_' . $name . '@example.invalid', 'role' => 'subscriber']);
            if (is_wp_error($id)) { throw new RuntimeException('Fixture account unavailable.'); }
            $previous['identities'][$name] = wp_generate_uuid4();
            $wpdb->insert(\Faluss\Platform\Fans\Sso\FansSsoSchema::tables()['links'], ['wp_user_id' => $id,
                'faluss_id' => $previous['identities'][$name], 'created_at' => gmdate('Y-m-d H:i:s'), 'last_proved_at' => gmdate('Y-m-d H:i:s')]);
            wp_set_current_user($id);
            $token = WP_Session_Tokens::get_instance($id)->create(time() + 3600);
            $cookie = wp_generate_auth_cookie($id, time() + 3600, 'logged_in', $token);
            $_COOKIE[LOGGED_IN_COOKIE] = $cookie;
            $previous['sessions'][$name] = ['id' => $id, 'cookie' => LOGGED_IN_COOKIE . '=' . $cookie, 'nonce' => wp_create_nonce('wp_rest')];
        }
        file_put_contents($root . '/h4-sessions.json', wp_json_encode($previous));
        chmod($root . '/h4-sessions.json', 0600);
        $result = ['ready' => true];
    } elseif ($input['action'] === 'sign') {
        $signed = SnapshotTransport::sealRequest($input['fields'], $input['key'], 'recipe-fans-k1', time() + 60);
        $outer = CanonicalJson::object($signed['wire'], 65536);
        $payload = CanonicalJson::object(SignedEnvelope::decode($outer['payload_base64url'], 49152), 49152);
        $context = CanonicalJson::object(SignedEnvelope::decode($payload['context']['payload_base64url']));
        $context = array_replace($context, $input['context'] ?? []);
        $payload['context'] = SignedEnvelope::seal(SignedEnvelope::SNAPSHOT_CONTEXT, CanonicalJson::encode($context), $input['context_key'] ?? 'recipe-fans-k1');
        $payload = array_replace($payload, $input['request'] ?? []);
        $result = ['nonce' => $signed['nonce'], 'wire' => CanonicalJson::encode(SignedEnvelope::seal(
            SignedEnvelope::SNAPSHOT_REQUEST, CanonicalJson::encode($payload), $input['request_key'] ?? 'recipe-fans-k1'))];
    } elseif ($input['action'] === 'alter-response') {
        $outer = CanonicalJson::object($input['wire'], 360000);
        $payload = CanonicalJson::object(SignedEnvelope::decode($outer['payload_base64url'], 262144), 262144);
        $payload = array_replace($payload, $input['response'] ?? []);
        if (isset($input['manifest'])) {
            $payload['result']['manifest'] = array_replace($payload['result']['manifest'], $input['manifest']);
        }
        if (isset($input['page'])) { $payload['result'] = array_replace($payload['result'], $input['page']); }
        $result = ['wire' => CanonicalJson::encode(SignedEnvelope::seal(SignedEnvelope::SNAPSHOT_RESPONSE,
            CanonicalJson::encode($payload), $input['response_key'] ?? file_get_contents($root . '/hub-key-id')))];
    } else {
        $epoch = file_get_contents($root . '/snapshot-epoch');
        $store = new ClosedSnapshotInbox($wpdb, $epoch);
        if ($input['action'] === 'prepare') { $result = $store->prepare($input['member'], $input['read_id']); }
        elseif ($input['action'] === 'current') { $result = $store->current($input['member']); }
        elseif ($input['action'] === 'accept') {
            $policy = json_decode(file_get_contents($root . '/fans-trust.json'), true, 32, JSON_THROW_ON_ERROR);
            $client = new ClosedSnapshotClient($wpdb, new PeerPolicy($policy['node'], $policy['audience'], $policy['permissions'], $policy['keys']),
                'recipe-fans-k1', file_get_contents($root . '/snapshot-endpoint'), $epoch);
            $result = $client->accept($input['wire'], $input['fields'], $input['nonce'], $input['digest']);
        } else { throw new RuntimeException('Unknown H4 fixture action.'); }
    }
} catch (ModelViolation $error) { $result = ['error' => $error->reason]; }
echo wp_json_encode($result, JSON_THROW_ON_ERROR);
