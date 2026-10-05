<?php

declare(strict_types=1);

use Faluss\Platform\TokenEngine\PurchasedPf\AttributionIntent;
use Faluss\Platform\TokenEngine\PurchasedPf\ClosedModelSchema;
use Faluss\Platform\TokenEngine\PurchasedPf\ClosedModelStore;
use Faluss\Platform\TokenEngine\PurchasedPf\ClosedReservationSchema;
use Faluss\Platform\TokenEngine\PurchasedPf\ClosedReservationStore;
use Faluss\Platform\TokenEngine\PurchasedPf\ClosedConsumptionSchema;
use Faluss\Platform\TokenEngine\PurchasedPf\ClosedConsumptionStore;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\PurchaseEvidence;

// Executed only by wp-cli inside the fresh, private database created by run.py.
if (!defined('FALUSS_HUB_PF_RECIPE_ONLY') || FALUSS_HUB_PF_RECIPE_ONLY !== true
    || !defined('FALUSS_PLATFORM_ROLE') || FALUSS_PLATFORM_ROLE !== 'hub'
    || !Token_Engine_Schema::is_ready()
) {
    throw new RuntimeException('Disposable Hub fixture required.');
}

/** Injects acknowledgement/process failures around a real MariaDB COMMIT. */
final class HubPfRecipeDatabase extends wpdb
{
    public string $fault = '';
    public string $marker = '';

    public function query($query)
    {
        if ($this->fault === 'h1-metadata-error' && str_starts_with($query, 'SHOW TABLE STATUS LIKE')) {
            $this->fault = '';
            $result = parent::query($query);
            $this->last_error = 'fixture_metadata_error';
            return $result;
        }
        if ($this->fault === 'h1-key-insert' && preg_match('/^INSERT INTO `[^`]+token_engine_pf_h1_keys` /', $query) === 1) {
            $this->fault = '';
            return false;
        }
        if ($this->fault === 'h2-key-insert' && preg_match('/^INSERT INTO `[^`]+token_engine_pf_h2_keys` /', $query) === 1) {
            $this->fault = '';
            return false;
        }
        if (($this->fault === 'h2c-journal-insert' && preg_match('/^INSERT INTO `[^`]+token_engine_pf_h2c_journal` /', $query) === 1)
            || ($this->fault === 'h2c-record-insert' && preg_match('/^INSERT INTO `[^`]+token_engine_pf_h2c_consumptions` /', $query) === 1)
            || ($this->fault === 'h3-receipt-insert' && preg_match('/^INSERT INTO `[^`]+token_engine_pf_h3_receipts` /', $query) === 1)
            || ($this->fault === 'h4-fragment-insert' && preg_match('/^INSERT INTO `[^`]+token_engine_pf_h4_fragments` /', $query) === 1)
        ) {
            $this->fault = '';
            return false;
        }
        if ($this->fault === 'h2c-transition-expiry' && str_contains($query, "SET state='confirmed'")) {
            $this->fault = '';
            // Advance only this test connection's MariaDB clock before the guarded transition.
            parent::query('SET timestamp=UNIX_TIMESTAMP()+121');
        }
        if (strtoupper(trim($query)) !== 'COMMIT' || $this->fault === '') {
            return parent::query($query);
        }
        $fault = $this->fault;
        $this->fault = '';
        if ($fault === 'before-commit') {
            $this->pauseForTermination();
        }
        $result = parent::query($query);
        if ($result === false) {
            throw new RuntimeException('Real fixture COMMIT failed.');
        }
        if ($fault === 'after-commit') {
            $this->pauseForTermination();
        }
        // The real COMMIT succeeded; the service is deliberately told otherwise.
        return false;
    }

    private function pauseForTermination(): void
    {
        file_put_contents($this->marker, 'ready');
        $deadline = microtime(true) + 25;
        while (microtime(true) < $deadline) {
            usleep(10000);
        }
        throw new RuntimeException('Fixture controller did not terminate worker.');
    }
}

$input = json_decode(file_get_contents($args[0]), true, 512, JSON_THROW_ON_ERROR);
global $wpdb, $table_prefix;
if (!empty($input['barrier'])) {
    file_put_contents($input['barrier'] . '.' . $input['worker'] . '.ready', 'ready');
    $deadline = microtime(true) + 25;
    while (!is_file($input['barrier'] . '.go')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Fixture barrier timed out.');
        }
        usleep(10000);
    }
}
if (!empty($input['fault'])) {
    $wpdb = new HubPfRecipeDatabase(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
    $wpdb->set_prefix($table_prefix);
    $wpdb->fault = $input['fault'];
    $wpdb->marker = $input['marker'];
}

if (str_starts_with($input['action'], 'h4-')) {
    try {
        if ($input['action'] === 'h4-install') {
            Faluss\Platform\TokenEngine\PurchasedPf\ClosedCorrectionSchema::installForRecipe($wpdb);
            $result = ['ready' => true];
        } elseif ($input['action'] === 'h4-ready') {
            $result = ['ready' => Faluss\Platform\TokenEngine\PurchasedPf\ClosedCorrectionSchema::ready($wpdb)];
        } elseif ($input['action'] === 'h4-seed-many') {
            $member = $input['member'];
            $results = [];
            // Private test connection clock avoids fabricating production quota exceptions.
            $clock = time();
            for ($index = 0; $index < $input['count']; $index++) {
                $wpdb->query('SET timestamp=' . ($clock + $index * 70));
                $intent = AttributionIntent::fromArray(['attribution_id' => wp_generate_uuid4(), 'client_authority' => 'fixture.fans',
                    'member_faluss_id' => $member, 'creator_faluss_id' => wp_generate_uuid4(), 'purchased_pf' => '1', 'policy_version' => '1.0.0']);
                (new ClosedReservationStore($wpdb, ['fixture.fans'], ['fixture.purchase']))->reserve($intent, bin2hex(random_bytes(32)));
                (new ClosedConsumptionStore($wpdb, ['fixture.fans'], ['fixture.purchase']))->confirm($intent, bin2hex(random_bytes(32)));
                $results[] = $intent->values['attribution_id'];
            }
            $result = ['attributions' => $results];
        } else {
            $store = new Faluss\Platform\TokenEngine\PurchasedPf\ClosedCorrectionStore($wpdb, $input['evidence_authorities'] ?? ['fixture.purchase']);
            $result = match ($input['action']) {
                'h4-begin' => $store->begin(PurchaseEvidence::fromArray($input['payload']), $input['key']),
                'h4-resume' => $store->resume($input['member'], $input['plan_id'], $input['fragment']),
                'h4-lookup' => $store->lookup($input['member'], $input['key']),
                default => throw new RuntimeException('Unknown H4 fixture action.'),
            };
        }
    } catch (ModelViolation $error) {
        $result = ['error' => $error->reason];
    }
    echo wp_json_encode($result, JSON_THROW_ON_ERROR);
    return;
}

if (str_starts_with($input['action'], 'h3-')) {
    try {
        require_once dirname(__DIR__, 3) . '/src/Federation/Legacy/includes/class-faluss-federation-crypto.php';
        if ($input['action'] === 'h3-install') {
            Faluss\Platform\TokenEngine\PurchasedPf\ClosedProtocolSchema::installForRecipe($wpdb);
            $result = ['ready' => true];
        } elseif ($input['action'] === 'h3-ready') {
            $result = ['ready' => Faluss\Platform\TokenEngine\PurchasedPf\ClosedProtocolSchema::ready($wpdb)];
        } elseif ($input['action'] === 'h3-public') {
            $pair = sodium_crypto_sign_seed_keypair(Faluss_Federation_Crypto::base64url_decode(FALUSS_FEDERATION_PRIVATE_SEED, 32));
            $result = ['public_key' => Faluss_Federation_Crypto::base64url_encode(sodium_crypto_sign_publickey($pair))];
            sodium_memzero($pair);
        } elseif ($input['action'] === 'h3-verify') {
            $peer = new Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy('fixture.hub', 'fixture.fans', [],
                ['recipe-hub-k1' => ['public_key' => $input['public_key'], 'state' => 'active',
                    'from' => '2026-01-01T00:00:00Z', 'until' => '2027-01-01T00:00:00Z']]);
            $receipt = Faluss\Platform\TokenEngine\PurchasedPf\Protocol\SignedEnvelope::open(
                Faluss\Platform\TokenEngine\PurchasedPf\Protocol\SignedEnvelope::RECEIPT, $input['receipt'], $peer, time());
            Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PrivateReceipt::validate($receipt, 'fixture.hub', 'fixture.fans',
                AttributionIntent::fromArray($input['payload']));
            $result = ['verified' => true];
        } else {
            $protocol = new Faluss\Platform\TokenEngine\PurchasedPf\ClosedProtocolStore($wpdb, 'fixture.hub', 'recipe-hub-k1');
            if ($input['action'] === 'h3-nonce') {
                $protocol->acceptNonce('fixture.fans', $input['nonce'], $input['digest'], $input['member']);
                $result = ['accepted' => true];
            } else {
                $intent = AttributionIntent::fromArray($input['payload']);
                $consumptions = new ClosedConsumptionStore($wpdb, ['fixture.fans'], ['fixture.purchase'], $protocol->record(...));
                $result = $input['action'] === 'h3-confirm' ? $consumptions->confirm($intent, $input['key'])
                    : $consumptions->lookup($intent, $input['operation'] ?? 'confirm', $input['key']);
                if ($result['state'] === 'confirmed') {
                    $result['receipt'] = $protocol->receipt($intent, $result['consumption']);
                }
            }
        }
    } catch (ModelViolation $error) {
        $result = ['error' => $error->reason];
    }
    echo wp_json_encode($result, JSON_THROW_ON_ERROR);
    return;
}

if (str_starts_with($input['action'], 'h1-')) {
    try {
        if ($input['action'] === 'h1-install') {
            ClosedModelSchema::installForRecipe($wpdb);
            $result = ['ready' => ClosedModelSchema::ready($wpdb)];
        } elseif ($input['action'] === 'h1-ready') {
            $result = ['ready' => ClosedModelSchema::ready($wpdb)];
        } else {
            // Explicit, synthetic allowlists in the test worker; no registry/producer.
            $model = new ClosedModelStore($wpdb, $input['evidence_authorities'] ?? ['fixture.purchase'],
                $input['client_authorities'] ?? ['fixture.fans', 'fixture.other']);
            $result = match ($input['action']) {
                'h1-evidence' => $model->recordEvidence(PurchaseEvidence::fromArray($input['payload']), $input['key']),
                'h1-intent' => $model->recordIntent(AttributionIntent::fromArray($input['payload']), $input['key']),
                'h1-lookup' => $model->lookup($input['scope'], $input['operation'], $input['key']),
                'h1-authority' => ['constructed' => true],
                default => throw new RuntimeException('Unknown H1 fixture action.'),
            };
        }
    } catch (ModelViolation $error) {
        $result = ['error' => $error->reason];
    }
    echo wp_json_encode($result, JSON_THROW_ON_ERROR);
    return;
}

if (str_starts_with($input['action'], 'h2-')) {
    try {
        if ($input['action'] === 'h2-install') {
            ClosedReservationSchema::installForRecipe($wpdb);
            $result = ['ready' => ClosedReservationSchema::ready($wpdb)];
        } elseif ($input['action'] === 'h2-ready') {
            $result = ['ready' => ClosedReservationSchema::ready($wpdb)];
        } else {
            $store = new ClosedReservationStore($wpdb, $input['client_authorities'] ?? ['fixture.fans', 'fixture.other'],
                $input['evidence_authorities'] ?? ['fixture.purchase']);
            $result = match ($input['action']) {
                'h2-admit' => $store->admitLotForRecipe($input['lot_id'], $input['member'], $input['key']),
                'h2-reserve' => $store->reserve(AttributionIntent::fromArray($input['payload']), $input['key']),
                'h2-release' => $store->release(AttributionIntent::fromArray($input['payload']), $input['key']),
                'h2-lookup' => $store->lookup(AttributionIntent::fromArray($input['payload']), $input['operation'], $input['key']),
                'h2-authority' => ['constructed' => true],
                default => throw new RuntimeException('Unknown H2 fixture action.'),
            };
        }
    } catch (ModelViolation $error) {
        $result = ['error' => $error->reason];
    }
    echo wp_json_encode($result, JSON_THROW_ON_ERROR);
    return;
}

if (str_starts_with($input['action'], 'h2c-')) {
    try {
        if ($input['action'] === 'h2c-install') {
            ClosedConsumptionSchema::installForRecipe($wpdb);
            $result = ['ready' => ClosedConsumptionSchema::ready($wpdb)];
        } elseif ($input['action'] === 'h2c-ready') {
            $result = ['ready' => ClosedConsumptionSchema::ready($wpdb)];
        } else {
            $store = new ClosedConsumptionStore($wpdb, $input['client_authorities'] ?? ['fixture.fans', 'fixture.other'],
                $input['evidence_authorities'] ?? ['fixture.purchase']);
            $result = match ($input['action']) {
                'h2c-confirm' => $store->confirm(AttributionIntent::fromArray($input['payload']), $input['key']),
                'h2c-lookup' => $store->lookup(AttributionIntent::fromArray($input['payload']), $input['operation'], $input['key']),
                default => throw new RuntimeException('Unknown H2c fixture action.'),
            };
        }
    } catch (ModelViolation $error) {
        $result = ['error' => $error->reason];
    }
    echo wp_json_encode($result, JSON_THROW_ON_ERROR);
    return;
}

$hubProof = ['owner' => 'faluss-hub', 'identity_active' => true];
$meProof = ['owner' => 'faluss-me', 'identity_active' => true, 'published_card' => true, 'reserved_handle' => true];
$subject = $input['subject'] ?? '';
// Expected recipe-only guard rejection must not log a private SQL statement.
if ($input['action'] === 'compensate' && defined('FALUSS_PF_H4_RECIPE')) {
    $wpdb->suppress_errors(true);
}
$result = match ($input['action']) {
    'hub' => Token_Engine_Points_Service::claim_hub_daily($subject, $input['proof'] ?? $hubProof),
    'me' => Token_Engine_Points_Service::claim_me_profile_daily($subject, $input['proof'] ?? $meProof),
    'status' => Token_Engine_Points_Service::daily_status($subject, 'faluss-hub', 'hub.daily_accrual', $hubProof),
    'balances' => Token_Engine_Points_Service::balances_by_class($subject),
    'compensate' => Token_Engine_Points_Service::compensate_entry(
        $input['original'], 'faluss-hub', $input['event'], '1.0.0', $input['reason'] ?? null
    ),
    'future' => Token_Engine_Points_Service::validate_future_entry([
        'entry_uuid' => wp_generate_uuid4(), 'faluss_id' => $subject, 'amount_pf' => 20,
        'direction' => 'credit', 'economic_class' => 'funded', 'category' => $input['category'],
        'category_version' => '1.0.0', 'source_owner' => 'faluss-hub',
        'source_event_reference' => 'fixture.future.reference', 'idempotency_key' => 'fixture.future.idempotency',
        'policy_version' => '1.0.0', 'occurred_at' => gmdate('Y-m-d H:i:s'),
    ]),
    'inspect' => [
        'schema' => get_option(Token_Engine_Schema::OPTION),
        'pf_engine' => $wpdb->get_row('SHOW TABLE STATUS LIKE "' . Token_Engine_Schema::pf_ledger_table() . '"', ARRAY_A)['Engine'],
        'pf_count' => (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . Token_Engine_Schema::pf_ledger_table() . '`'),
        'generic_count' => (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . Token_Engine_Schema::ledger_table() . '`'),
        'facade' => array_map(static fn (ReflectionMethod $method): string => $method->name,
            (new ReflectionClass(Faluss\Platform\TokenEngine\TokenEngineContract::class))->getMethods(ReflectionMethod::IS_PUBLIC)),
    ],
    default => throw new RuntimeException('Unknown fixture action.'),
};
if (is_wp_error($result)) {
    $result = ['error' => $result->get_error_code()];
}
echo wp_json_encode($result, JSON_THROW_ON_ERROR);
