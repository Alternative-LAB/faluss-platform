<?php

declare(strict_types=1);

use Faluss\Platform\TokenEngine\PurchasedPf\ClosedCorpusTransaction;
use Faluss\Platform\TokenEngine\PurchasedPf\ClosedLedgerWriter;
use Faluss\Platform\TokenEngine\PurchasedPf\ClosedRankingCorpusSource;
use Faluss\Platform\TokenEngine\PurchasedPf\ClosedReservationDatabase;
use Faluss\Platform\TokenEngine\PurchasedPf\ClosedReservationStore;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy;

try {
    if (isset($input['clock_value'])) {
        if (preg_match('/^[1-9][0-9]{9}\.[0-9]{6}$/D',$input['clock_value']) !== 1) { throw new RuntimeException('Invalid fixture clock.'); }
        $wpdb->query('SET timestamp=' . $input['clock_value']);
    }
    $owner = new ClosedRankingCorpusSource($wpdb,['fixture.purchase'],'recipe-hub-k1');
    $peer = new PeerPolicy($input['peer'] ?? 'fixture.fans',$input['audience'] ?? 'fixture.hub',$input['permissions'] ?? ['pf.ranking.corpus'],[]);
    $result = match ($input['action']) {
        'b3o-read' => $owner->read($peer,$input['origin'],$input['policy'] ?? '1.0.0'),
        'b3o-outside' => $owner->readInOwnerTransaction($peer,$input['origin'],$input['policy'] ?? '1.0.0'),
        'b3o-guard' => (new ClosedCorpusTransaction($wpdb))->run(function () use ($input,$wpdb): array {
            $connection = new ClosedReservationDatabase($wpdb); $member = $input['member']; $attempts = [];
            foreach (['subject' => fn () => $connection->assertHeldSubject($member),
                'credit' => fn () => (new ClosedLedgerWriter($connection))->appendCredit($member,$input['lot'],'1','1.0.0'),
                'debit' => fn () => (new ClosedLedgerWriter($connection))->appendDebit($member,$input['attribution'],'1','1.0.0'),
                'nested' => fn () => $connection->write($member,fn (): array => []),
                'correction' => fn () => (new \Faluss\Platform\TokenEngine\PurchasedPf\ClosedCorrectionLedger($connection))->apply($member,$input['lot'],'0','0','0','1.0.0')]
                as $name => $attempt) {
                try { $attempt(); $attempts[$name] = 'unexpected_success'; }
                catch (ModelViolation $error) { $attempts[$name] = $error->reason; }
            }
            $connection->assertReadableSubject($member);
            return ['attempts' => $attempts,'subject_acquired' => (string) $connection->scalar($wpdb->prepare('SELECT COALESCE(IS_USED_LOCK(%s)=CONNECTION_ID(),0)',ClosedReservationDatabase::subjectLock($member)))];
        }),
        'b3o-global-only' => (function () use ($input,$wpdb): array {
            $connection = new ClosedReservationDatabase($wpdb); $lock = ClosedCorpusTransaction::globalLock($wpdb);
            $connection->scalar($wpdb->prepare('SELECT GET_LOCK(%s,5)',$lock)); $wpdb->query('START TRANSACTION');
            try { $connection->assertReadableSubject($input['member']); return ['unexpected_success' => true]; }
            finally { $wpdb->query('ROLLBACK'); $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
        })(),
        default => throw new RuntimeException('Unknown isolated corpus source action.'),
    };
} catch (ModelViolation $error) { $result = ['error' => $error->reason]; }
if (!empty($input['observe']) && $wpdb instanceof HubPfRecipeDatabase) { $result['global_lock_ms'] = sprintf('%.3f',$wpdb->globalHeldMs); }
echo wp_json_encode($result,JSON_THROW_ON_ERROR);
