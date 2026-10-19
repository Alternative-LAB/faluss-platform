<?php

declare(strict_types=1);

// Application test configuration after the existing physical gate; no runner/environment provisioning.
\Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment::assertIsolated($wpdb,'fans');
foreach (['FALUSS_PLATFORM_FANS_MESSAGING','FALUSS_FANS_MESSAGING_POLICY_ATTESTED','FALUSS_PLATFORM_FANS_FOLLOWERS'] as $feature) {
    if (defined($feature) && constant($feature) !== true) { throw new RuntimeException('Conflicting private application fixture'); }
    if (!defined($feature)) { define($feature,true); }
}

final class PurchasedThanksFixtureDatabase extends wpdb
{
    public string $fault = '';
    private bool $messageInserted = false;
    public function query($query)
    {
        $matches = ($this->fault === 'thanks-insert' && preg_match('/^INSERT INTO `[^`]+fans_pf_thanks_bindings` /',$query) === 1)
            || ($this->fault === 'event-insert' && preg_match('/^INSERT INTO `[^`]+faluss_fans_dm_automatic_events` /',$query) === 1)
            || ($this->fault === 'message-outcome' && preg_match("/^UPDATE `[^`]+faluss_fans_dm_automatic_events` SET state='sent'/",$query) === 1);
        if ($matches) { $this->fault = ''; return false; }
        if (($this->fault === 'commit-unknown' || ($this->fault === 'delivery-commit-unknown' && $this->messageInserted)) && strtoupper(trim($query)) === 'COMMIT') {
            $this->fault = ''; $result = parent::query($query);
            if ($result === false) { throw new RuntimeException('Private fixture COMMIT failed'); }
            return false;
        }
        $result = parent::query($query);
        if ($result !== false && preg_match('/^INSERT INTO `[^`]+faluss_fans_dm_messages` /',$query) === 1) { $this->messageInserted = true; }
        return $result;
    }
}
if (isset($input['fault'])) {
    $prefix = $wpdb->prefix;
    $wpdb = new PurchasedThanksFixtureDatabase(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);
    $wpdb->set_prefix($prefix); $wpdb->fault = $input['fault'];
    \Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment::assertIsolated($wpdb,'fans');
}
