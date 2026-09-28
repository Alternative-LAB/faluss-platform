<?php

declare(strict_types=1);

namespace Faluss\Platform\Progression;

use InvalidArgumentException;

/** Offline fixtures only: no authentication, PF consumption, storage or runtime integration. */
final class HofSimulationV3
{
    private const FIELDS = [
        'version', 'reference', 'revision', 'member', 'creator', 'source',
        'economic_class', 'purchase_attestation', 'consumption_receipt',
        'pf', 'cancelled_pf', 'status',
    ];

    /**
     * Projections explicitly select canonical facts; no session eligibility policy is inferred.
     *
     * @param array<mixed> $facts
     * @param array<mixed> $projections
     * @param array<mixed> $derivedEvents
     * @return array{simulation:true,policy:string,creator_points:array<string,array<string,int>>,pc_delta:0,fixture_receipt_count:int,revisions:array<string,int>}
     */
    public static function project(array $facts, array $projections, array $derivedEvents = []): array
    {
        foreach ([$facts, $projections, $derivedEvents] as $batch) {
            if (!array_is_list($batch) || count($batch) > 1000) {
                throw new InvalidArgumentException('Invalid fixture batch.');
            }
        }
        $histories = [];
        foreach ($facts as $fact) {
            $row = self::fact($fact);
            $key = $row['reference'];
            $revision = $row['revision'];
            if (isset($histories[$key][$revision]) && $histories[$key][$revision] !== $row) {
                throw new InvalidArgumentException('Conflicting fact revision.');
            }
            $histories[$key][$revision] = $row;
        }
        $latest = $receipts = $revisions = [];
        foreach ($histories as $key => $history) {
            ksort($history);
            $previous = null;
            foreach ($history as $row) {
                if ($previous !== null) {
                    foreach (self::FIELDS as $field) {
                        if (!in_array($field, ['revision', 'cancelled_pf', 'status'], true)
                            && $row[$field] !== $previous[$field]
                        ) {
                            throw new InvalidArgumentException('Changed immutable fact.');
                        }
                    }
                    if ($row['cancelled_pf'] < $previous['cancelled_pf']) {
                        throw new InvalidArgumentException('Decreased cumulative correction.');
                    }
                }
                $previous = $row;
            }
            $row = end($history);
            if ($row['consumption_receipt'] !== null) {
                if (isset($receipts[$row['consumption_receipt']])) {
                    throw new InvalidArgumentException('Reused consumption receipt.');
                }
                $receipts[$row['consumption_receipt']] = true;
            }
            $latest[$key] = $row;
            $revisions[$key] = $row['revision'];
        }

        // Derived notifications reference facts but never become scoring facts or PC rewards.
        $events = [];
        foreach ($derivedEvents as $event) {
            if (!is_array($event)) {
                throw new InvalidArgumentException('Invalid derived event.');
            }
            self::shape($event, ['reference', 'fact_reference', 'kind']);
            if (!self::uuid($event['reference']) || !is_string($event['fact_reference'])
                || !isset($latest[$event['fact_reference']])
                || !in_array($event['kind'], ['badge_notice', 'ranking_notice', 'refund_notice'], true)
            ) {
                throw new InvalidArgumentException('Invalid derived event.');
            }
            $canonical = [$event['fact_reference'], $event['kind']];
            if (isset($events[$event['reference']]) && $events[$event['reference']] !== $canonical) {
                throw new InvalidArgumentException('Conflicting derived event.');
            }
            $events[$event['reference']] = $canonical;
        }

        $scores = $definitions = [];
        foreach ($projections as $projection) {
            if (!is_array($projection)) {
                throw new InvalidArgumentException('Invalid projection.');
            }
            self::shape($projection, ['reference', 'session', 'dimension', 'closed', 'facts']);
            foreach (['reference', 'session', 'dimension'] as $field) {
                if (!self::uuid($projection[$field])) {
                    throw new InvalidArgumentException('Invalid projection identity.');
                }
            }
            if (!is_bool($projection['closed']) || !is_array($projection['facts'])
                || !array_is_list($projection['facts']) || count($projection['facts']) > 1000
            ) {
                throw new InvalidArgumentException('Invalid projection selection.');
            }
            $selection = [];
            foreach ($projection['facts'] as $reference) {
                if (!is_string($reference) || !isset($latest[$reference])) {
                    throw new InvalidArgumentException('Unknown selected fact.');
                }
                $selection[$reference] = true;
            }
            ksort($selection);
            $definition = [$projection['session'], $projection['dimension'], $projection['closed'], $selection];
            $key = $projection['reference'];
            if (isset($definitions[$key]) && $definitions[$key] !== $definition) {
                throw new InvalidArgumentException('Conflicting projection.');
            }
            $definitions[$key] = $definition;
            $scores[$key] = [];
            foreach ($selection as $reference => $_) {
                $row = $latest[$reference];
                if ($row['source'] !== 'purchased_pf_allocation') {
                    continue;
                }
                $creator = $row['creator'];
                $points = $row['status'] === 'confirmed' ? $row['pf'] - $row['cancelled_pf'] : 0;
                $scores[$key][$creator] = ($scores[$key][$creator] ?? 0) + $points;
            }
            ksort($scores[$key]);
        }
        ksort($scores);
        ksort($revisions);
        return [
            'simulation' => true, 'policy' => 'fans.hof-simulation/3.0.0',
            'creator_points' => $scores, 'pc_delta' => 0,
            'fixture_receipt_count' => count($receipts), 'revisions' => $revisions,
        ];
    }

    /** @return array{version:string,reference:string,revision:int,member:string,creator:?string,source:string,economic_class:string,purchase_attestation:string,consumption_receipt:?string,pf:int,cancelled_pf:int,status:string} */
    private static function fact(mixed $fact): array
    {
        if (!is_array($fact)) {
            throw new InvalidArgumentException('Invalid fact.');
        }
        self::shape($fact, self::FIELDS);
        if ($fact['version'] !== '3.0.0' || $fact['economic_class'] !== 'funded'
            || !in_array($fact['source'], ['purchased_pf_pack', 'purchased_pf_allocation'], true)
            || !in_array($fact['status'], ['pending', 'failed', 'confirmed', 'disputed', 'refunded'], true)
        ) {
            throw new InvalidArgumentException('Ineligible fact.');
        }
        foreach (['reference', 'member', 'purchase_attestation'] as $field) {
            if (!self::uuid($fact[$field])) {
                throw new InvalidArgumentException('Missing fixture identity or attestation.');
            }
        }
        foreach (['revision', 'pf', 'cancelled_pf'] as $field) {
            if (!is_int($fact[$field]) || $fact[$field] < 0 || $fact[$field] > 1000000) {
                throw new InvalidArgumentException('Invalid integer quantity.');
            }
        }
        if ($fact['revision'] < 1 || $fact['pf'] < 1 || $fact['cancelled_pf'] > $fact['pf']
            || ($fact['status'] === 'refunded' && $fact['cancelled_pf'] !== $fact['pf'])
        ) {
            throw new InvalidArgumentException('Invalid correction.');
        }
        if ($fact['source'] === 'purchased_pf_allocation') {
            if (!self::uuid($fact['creator']) || !self::uuid($fact['consumption_receipt'])
                || $fact['creator'] === $fact['member']
            ) {
                throw new InvalidArgumentException('Invalid allocation or self-allocation.');
            }
        } elseif ($fact['creator'] !== null || $fact['consumption_receipt'] !== null) {
            throw new InvalidArgumentException('Pack cannot allocate PF.');
        }
        return [
            'version' => $fact['version'], 'reference' => $fact['reference'], 'revision' => $fact['revision'],
            'member' => $fact['member'], 'creator' => $fact['creator'], 'source' => $fact['source'],
            'economic_class' => $fact['economic_class'], 'purchase_attestation' => $fact['purchase_attestation'],
            'consumption_receipt' => $fact['consumption_receipt'], 'pf' => $fact['pf'],
            'cancelled_pf' => $fact['cancelled_pf'], 'status' => $fact['status'],
        ];
    }

    /**
     * @param array<mixed> $row
     * @param list<string> $fields
     */
    private static function shape(array $row, array $fields): void
    {
        if (count($row) !== count($fields) || array_diff($fields, array_keys($row)) !== []) {
            throw new InvalidArgumentException('Invalid closed fixture shape.');
        }
    }

    private static function uuid(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) === 1;
    }
}
