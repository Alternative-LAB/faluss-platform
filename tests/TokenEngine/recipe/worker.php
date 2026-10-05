<?php

declare(strict_types=1);

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

$hubProof = ['owner' => 'faluss-hub', 'identity_active' => true];
$meProof = ['owner' => 'faluss-me', 'identity_active' => true, 'published_card' => true, 'reserved_handle' => true];
$subject = $input['subject'] ?? '';
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
