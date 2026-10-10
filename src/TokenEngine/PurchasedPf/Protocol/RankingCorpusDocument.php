<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf\Protocol;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Exhaustive private owner inventory. Never infer completeness from known Fans members. */
final class RankingCorpusDocument
{
    public const CONTRACT = 'hub.purchased-pf.ranking-corpus/1.0.0';
    public const SCOPE = 'all_confirmed_ranked_0.3_including_zero';
    public const PAGE_SIZE = 100;

    /** @param array<array-key,mixed> $fact */
    public static function fact(array $fact): void
    {
        ModelValues::exactKeys($fact,['attribution_id','consumption_id','ledger_entry_uuid','ledger_fact_sha256',
            'member_faluss_id','creator_faluss_id','client_authority','purchased_pf','cancelled_pf','suspended_pf','net_pf',
            'confirmed_at','consumption_order','ordering_epoch','ranking_context','context_sha256','allocations']);
        foreach (['attribution_id','consumption_id','ledger_entry_uuid','member_faluss_id','creator_faluss_id','ordering_epoch'] as $name) { ModelValues::uuid($fact[$name]); }
        RankingValues::digest($fact['ledger_fact_sha256']); RankingValues::utc($fact['confirmed_at']);
        ModelValues::integer($fact['consumption_order'],true);
        if ($fact['client_authority'] !== 'fixture.fans' || !is_array($fact['ranking_context'])) { throw new ModelViolation('pf_corpus_context_mismatch'); }
        $context = RankingContext::validate($fact['ranking_context']);
        RankedIntent::fromArray(['attribution_id' => $fact['attribution_id'],'member_faluss_id' => $fact['member_faluss_id'],
            'creator_faluss_id' => $fact['creator_faluss_id'],'client_authority' => $fact['client_authority'],
            'purchased_pf' => $fact['purchased_pf'],'policy_version' => $context['policy_version'],
            'ranking_context' => $context,'context_sha256' => $fact['context_sha256']]);
        RankingContext::atConfirmation($context,$fact['confirmed_at']);
        $original = (int) ModelValues::integer($fact['purchased_pf'],true);
        $cancelled = (int) ModelValues::integer($fact['cancelled_pf']); $suspended = (int) ModelValues::integer($fact['suspended_pf']);
        $net = (int) ModelValues::integer($fact['net_pf']);
        if ($cancelled > $original || $suspended > $original - $cancelled || $net !== $original - $cancelled - $suspended) { throw new ModelViolation('pf_corpus_invalid_net'); }
        if (!is_array($fact['allocations']) || !array_is_list($fact['allocations']) || count($fact['allocations']) < 1 || count($fact['allocations']) > 32) { throw new ModelViolation('pf_corpus_invalid_allocations'); }
        $sums = ['original_pf' => 0,'cancelled_pf' => 0,'suspended_pf' => 0,'net_pf' => 0]; $previous = '';
        foreach ($fact['allocations'] as $allocation) {
            if (!is_array($allocation)) { throw new ModelViolation('pf_corpus_invalid_allocations'); }
            self::allocation($allocation);
            if ($allocation['lot_id'] <= $previous) { throw new ModelViolation('pf_corpus_invalid_allocations'); }
            $previous = $allocation['lot_id'];
            foreach ($sums as $name => $sum) {
                $value = (int) $allocation[$name];
                if ($sum > ModelValues::MAX_INTEGER - $value) { throw new ModelViolation('pf_corpus_overflow'); }
                $sums[$name] += $value;
            }
        }
        if ($sums !== ['original_pf' => $original,'cancelled_pf' => $cancelled,'suspended_pf' => $suspended,'net_pf' => $net]) { throw new ModelViolation('pf_corpus_invalid_net'); }
    }

    /** No purchase reference, available balance, PC, money, name or media.
     * @param array<array-key,mixed> $allocation */
    public static function allocation(array $allocation): void
    {
        ModelValues::exactKeys($allocation,['lot_id','source_revision','source_state','source_evidence_id','source_evidence_sha256',
            'original_pf','cancelled_pf','suspended_pf','net_pf']);
        ModelValues::uuid($allocation['lot_id']); ModelValues::uuid($allocation['source_evidence_id']);
        ModelValues::integer($allocation['source_revision'],true); RankingValues::digest($allocation['source_evidence_sha256']);
        $state = $allocation['source_state'];
        if (!in_array($state,['confirmed','partially_cancelled','disputed','cancelled'],true)) { throw new ModelViolation('pf_corpus_invalid_source'); }
        $original = (int) ModelValues::integer($allocation['original_pf'],true);
        $cancelled = (int) ModelValues::integer($allocation['cancelled_pf']); $suspended = (int) ModelValues::integer($allocation['suspended_pf']);
        $net = (int) ModelValues::integer($allocation['net_pf']);
        if ($cancelled > $original || $suspended > $original - $cancelled || $net !== $original - $cancelled - $suspended
            || ($state === 'disputed' && $net !== 0) || ($state === 'cancelled' && $cancelled !== $original)
            || ($state === 'confirmed' && $cancelled !== 0) || ($state !== 'disputed' && $suspended !== 0)) { throw new ModelViolation('pf_corpus_invalid_net'); }
    }

    /** @param array<array-key,array<string,mixed>> $facts
     * @return array<string,string> */
    public static function summary(array $facts): array
    {
        if (!array_is_list($facts)) { throw new ModelViolation('pf_corpus_incomplete'); }
        $totals = ['original_pf' => 0,'cancelled_pf' => 0,'suspended_pf' => 0,'net_pf' => 0];
        $seen = []; $sources = []; $previousOrder = 0; $previousTime = ''; $epoch = null; $origin = null; $policy = null;
        foreach ($facts as $fact) {
            self::fact($fact);
            foreach (['attribution_id','consumption_id','ledger_entry_uuid'] as $name) {
                $key = $name . ':' . $fact[$name];
                if (isset($seen[$key])) { throw new ModelViolation('pf_corpus_duplicate_fact'); }
                $seen[$key] = true;
            }
            $order = (int) $fact['consumption_order'];
            if ($order <= $previousOrder || $fact['confirmed_at'] < $previousTime) { throw new ModelViolation('pf_corpus_invalid_order'); }
            $previousOrder = $order; $previousTime = $fact['confirmed_at'];
            $epoch ??= $fact['ordering_epoch']; $origin ??= $fact['ranking_context']['origin_id']; $policy ??= $fact['ranking_context']['policy_version'];
            if ($epoch !== $fact['ordering_epoch'] || $origin !== $fact['ranking_context']['origin_id'] || $policy !== $fact['ranking_context']['policy_version']) { throw new ModelViolation('pf_corpus_context_mismatch'); }
            foreach ($totals as $name => $total) {
                $value = (int) $fact[$name === 'original_pf' ? 'purchased_pf' : $name];
                if ($total > ModelValues::MAX_INTEGER - $value) { throw new ModelViolation('pf_corpus_overflow'); }
                $totals[$name] += $value;
            }
            foreach ($fact['allocations'] as $allocation) {
                $id = $allocation['lot_id']; $vector = hash('sha256',CanonicalJson::encode(['member_faluss_id' => $fact['member_faluss_id']] + array_intersect_key($allocation,array_flip(['source_revision','source_state','source_evidence_id','source_evidence_sha256']))));
                if (isset($sources[$id]) && $sources[$id] !== $vector) { throw new ModelViolation('pf_corpus_inconsistent_source'); }
                $sources[$id] = $vector;
            }
        }
        return ['fact_count' => (string) count($facts),'page_count' => (string) max(1,(int) ceil(count($facts) / self::PAGE_SIZE))]
            + array_map(static fn (int $number): string => (string) $number,$totals);
    }

    /** @param array<string,mixed> $manifest */
    public static function manifest(array $manifest, string $origin, string $policy): void
    {
        ModelValues::exactKeys($manifest,['contract','corpus_id','issuer','audience','origin_id','policy_version','ordering_epoch',
            'epoch','revision','last_order','created_at','scope','fact_count','page_count','original_pf','cancelled_pf','suspended_pf','net_pf','full_sha256']);
        if ($manifest['contract'] !== self::CONTRACT || $manifest['scope'] !== self::SCOPE || $manifest['issuer'] !== 'fixture.hub'
            || $manifest['audience'] !== 'fixture.fans' || $manifest['origin_id'] !== ModelValues::uuid($origin)
            || $manifest['policy_version'] !== ModelValues::version($policy)) { throw new ModelViolation('pf_corpus_context_mismatch'); }
        foreach (['corpus_id','ordering_epoch','epoch'] as $name) { ModelValues::uuid($manifest[$name]); }
        foreach (['revision','page_count'] as $name) { ModelValues::integer($manifest[$name],true); }
        foreach (['last_order','fact_count','original_pf','cancelled_pf','suspended_pf','net_pf'] as $name) { ModelValues::integer($manifest[$name]); }
        RankingValues::utc($manifest['created_at']); RankingValues::digest($manifest['full_sha256']);
        if ($manifest['page_count'] !== (string) max(1,(int) ceil((int) $manifest['fact_count'] / self::PAGE_SIZE))
            || (int) $manifest['fact_count'] > (int) $manifest['last_order']
            || (int) $manifest['cancelled_pf'] > (int) $manifest['original_pf']
            || (int) $manifest['suspended_pf'] > (int) $manifest['original_pf'] - (int) $manifest['cancelled_pf']
            || (int) $manifest['net_pf'] !== (int) $manifest['original_pf'] - (int) $manifest['cancelled_pf'] - (int) $manifest['suspended_pf']) { throw new ModelViolation('pf_corpus_incomplete'); }
    }

    /** @param array<string,mixed> $manifest
     * @param array<array-key,array<string,mixed>> $facts */
    public static function complete(array $manifest, array $facts): void
    {
        self::manifest($manifest,ModelValues::uuid($manifest['origin_id'] ?? null),ModelValues::version($manifest['policy_version'] ?? null));
        foreach (self::summary($facts) as $name => $value) { if ($manifest[$name] !== $value) { throw new ModelViolation('pf_corpus_incomplete'); } }
        foreach ($facts as $fact) { self::context($manifest,$fact); }
        if (!hash_equals($manifest['full_sha256'],hash('sha256',CanonicalJson::encode($facts)))) { throw new ModelViolation('pf_corpus_digest_mismatch'); }
    }

    /** @param array<string,mixed> $page
     * @param array<string,mixed> $manifest */
    public static function page(array $page, array $manifest): void
    {
        self::manifest($manifest,ModelValues::uuid($manifest['origin_id'] ?? null),ModelValues::version($manifest['policy_version'] ?? null));
        ModelValues::exactKeys($page,['manifest','page_index','cursor','next_cursor','facts']);
        $index = (int) ModelValues::integer($page['page_index']); $pages = (int) $manifest['page_count'];
        ModelValues::uuid($page['cursor']); if ($page['next_cursor'] !== null) { ModelValues::uuid($page['next_cursor']); }
        if ($page['manifest'] !== $manifest || $index >= $pages || ($page['next_cursor'] === null) !== ($index === $pages - 1)
            || !is_array($page['facts']) || !array_is_list($page['facts'])
            || count($page['facts']) !== min(self::PAGE_SIZE,(int) $manifest['fact_count'] - $index * self::PAGE_SIZE)) { throw new ModelViolation('pf_corpus_incomplete'); }
        self::summary($page['facts']);
        foreach ($page['facts'] as $fact) { self::context($manifest,$fact); }
    }

    /** @param array<string,mixed> $manifest
     * @param array<string,mixed> $fact */
    private static function context(array $manifest, array $fact): void
    {
        if ($fact['ordering_epoch'] !== $manifest['ordering_epoch'] || $fact['ranking_context']['origin_id'] !== $manifest['origin_id']
            || $fact['ranking_context']['policy_version'] !== $manifest['policy_version']
            || (int) $fact['consumption_order'] > (int) $manifest['last_order'] || $fact['confirmed_at'] > $manifest['created_at']) { throw new ModelViolation('pf_corpus_context_mismatch'); }
    }
}
