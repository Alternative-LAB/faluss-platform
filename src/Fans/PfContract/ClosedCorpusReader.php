<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\PfContract;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;

/** Bounded private recipe orchestration. No background hook, public route or freshness TTL. */
final class ClosedCorpusReader
{
    private const RETRYABLE = ['pf_transport_unknown','pf_local_corpus_commit_unknown','pf_local_corpus_busy','pf_local_corpus_checkpoint_moved'];

    public function __construct(private readonly ClosedCorpusInbox $inbox, private readonly ClosedCorpusClient $client) {}

    /** Explicit recheck, not a freshness TTL or scheduled network loop.
     * @return array<string,string> */
    public function refresh(string $readId, int $steps = 4): array
    {
        if ($steps < 1 || $steps > 16) { throw new ModelViolation('pf_local_corpus_budget'); }
        try { $progress = $this->inbox->prepareRefresh($readId); }
        catch (ModelViolation $error) {
            if (!in_array($error->reason,self::RETRYABLE,true)) { throw $error; }
            return ['state' => 'pending','read_id' => $readId,'completed_steps' => '0','reason' => $error->reason];
        }
        return $this->advance($progress['read_id'],$steps);
    }

    /** The caller durably retains its ID; uncertain results resume that ID rather than a new job.
     * @return array<string,string> */
    public function advance(string $readId, int $steps = 4): array
    {
        if ($steps < 1 || $steps > 16) { throw new ModelViolation('pf_local_corpus_budget'); }
        try { $progress = $this->inbox->prepare($readId); }
        catch (ModelViolation $error) {
            if (!in_array($error->reason,self::RETRYABLE,true)) { throw $error; }
            return ['state' => 'pending','read_id' => $readId,'completed_steps' => '0','reason' => $error->reason];
        }
        $completed = 0;
        while (!in_array($progress['phase'],['current','refused'],true) && $completed < $steps) {
            try { $progress = $this->client->collect($this->inbox,$progress); $completed++; }
            catch (ModelViolation $error) {
                if (!in_array($error->reason,self::RETRYABLE,true)) { throw $error; }
                return ['state' => 'pending','read_id' => $progress['read_id'],'completed_steps' => (string) $completed,'reason' => $error->reason];
            }
        }
        $result = ['state' => $progress['phase'] === 'refused' ? 'unavailable' : 'pending',
            'read_id' => $progress['read_id'],'completed_steps' => (string) $completed];
        if ($progress['phase'] === 'current') {
            try { $current = $this->inbox->current(); }
            catch (ModelViolation $error) {
                if (!in_array($error->reason,self::RETRYABLE,true)) { throw $error; }
                return ['state' => 'pending','read_id' => $progress['read_id'],'completed_steps' => (string) $completed,'reason' => $error->reason];
            }
            if ($current !== null && CanonicalJson::encode($current['manifest']) === CanonicalJson::encode($progress['manifest'])) {
                $result['state'] = 'verified'; $result['verified_at'] = $current['verified_at'];
            } else { $result['state'] = 'historical'; }
        }
        return $result;
    }
}
