<?php

declare(strict_types=1);

use Faluss\Platform\Fans\PfProjection\ClosedProjectionEnvironment;
use Faluss\Platform\Fans\PfProjection\ClosedProjectionSchema;
use Faluss\Platform\Fans\PfProjection\ClosedProjectionStore;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

// Test-only CLI controller; never registered by the plugin or copied as an HTTP adapter.
if (!defined('WP_CLI') || !WP_CLI) { throw new RuntimeException('Recipe CLI only.'); }
global $wpdb, $table_prefix;
$input = json_decode(file_get_contents($args[0]), true, 32, JSON_THROW_ON_ERROR);
$root = dirname(rtrim(ABSPATH, '/'));
try {
    ClosedProjectionEnvironment::assertIsolated($wpdb);
    if (!empty($input['fault'])) {
        /** Controlled failures and lock barriers on the real MariaDB connection. */
        $wpdb = new class(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST) extends wpdb {
            public string $fault = '';
            public string $marker = '';
            public function query($query)
            {
                if ($this->fault === 'generation-insert' && preg_match('/^INSERT INTO `[^`]+fans_pf_f1a_generation` /', $query) === 1) {
                    $this->fault = ''; return false;
                }
                if ($this->fault === 'fence-pause' && preg_match('/^SELECT \* FROM `[^`]+fans_pf_h4_current` .*FOR UPDATE$/', $query) === 1) {
                    $this->fault = '';
                    $result = parent::query($query);
                    $this->pause(true);
                    return $result;
                }
                if (strtoupper(trim($query)) !== 'COMMIT' || $this->fault === '') { return parent::query($query); }
                $fault = $this->fault; $this->fault = '';
                if ($fault === 'before-commit') { $this->pause(false); }
                $result = parent::query($query);
                if ($fault === 'after-commit') { $this->pause(false); }
                return $fault === 'lost-commit-ack' ? false : $result;
            }
            private function pause(bool $releasable): void
            {
                file_put_contents($this->marker, 'ready');
                $until = microtime(true) + 25;
                while (microtime(true) < $until) {
                    if ($releasable && is_file($this->marker . '.go')) { return; }
                    usleep(10000);
                }
                throw new RuntimeException('Fixture controller did not release worker.');
            }
        };
        $wpdb->set_prefix($table_prefix);
        $wpdb->fault = $input['fault'];
        $wpdb->marker = $input['marker'];
    }
    if (in_array($input['action'], ['install', 'ready'], true)) {
        if ($input['action'] === 'install') { ClosedProjectionSchema::installForRecipe($wpdb); }
        $result = ['ready' => ClosedProjectionSchema::ready($wpdb)];
    } else {
        $epoch = $input['epoch'] ?? file_get_contents($root . '/snapshot-epoch');
        $store = new ClosedProjectionStore($wpdb, $epoch);
        $result = match ($input['action']) {
            'rebuild' => $store->rebuild(),
            'read' => $store->read(),
            default => throw new RuntimeException('Unknown F1a fixture action.'),
        };
    }
} catch (ModelViolation $error) { $result = ['error' => $error->reason]; }
echo wp_json_encode($result, JSON_THROW_ON_ERROR);
