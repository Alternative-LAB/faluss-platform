<?php

declare(strict_types=1);

namespace Faluss\Platform\Progression;

use InvalidArgumentException;

/** Offline policy simulator only. Never accepts live events or grants rights. */
final class SupportSimulation
{
    private const FIELDS = [
        'version', 'owner', 'reference', 'revision', 'member', 'creator',
        'category', 'source', 'amount_cents', 'refunded_cents', 'status', 'occurred_at',
    ];

    /**
     * Full cumulative snapshots, not deltas. Caller must supply fictitious facts.
     * No public member projection, persistent score, PF or EUR ledger is created.
     *
     * @param array<mixed> $facts
     * @return array{simulation:true,policy:string,member_contributions:array<string,int>,creator_scores:array<string,int>}
     */
    public static function project(array $facts): array
    {
        if (!array_is_list($facts) || count($facts) > 10000) {
            throw new InvalidArgumentException('Invalid simulation batch.');
        }
        $latest = [];
        $revisions = [];
        foreach ($facts as $fact) {
            $row = self::validate($fact);
            $key = $row['owner'] . ':' . $row['reference'];
            $revision = $row['revision'];
            if (isset($revisions[$key][$revision]) && $revisions[$key][$revision] !== $row) {
                throw new InvalidArgumentException('Conflicting fact revision.');
            }
            $revisions[$key][$revision] = $row;
            if (isset($latest[$key])) {
                foreach (['member', 'creator', 'category', 'source', 'amount_cents', 'occurred_at'] as $immutable) {
                    if ($latest[$key][$immutable] !== $row[$immutable]) {
                        throw new InvalidArgumentException('Changed immutable support fact.');
                    }
                }
                if ($latest[$key]['revision'] >= $revision) {
                    continue;
                }
            }
            $latest[$key] = $row;
        }
        $members = [];
        $creators = [];
        foreach ($latest as $row) {
            $net = $row['status'] === 'confirmed'
                && $row['category'] === 'hosted_allowed_content'
                && in_array($row['source'], ['eur_support', 'funded_support'], true)
                ? $row['amount_cents'] - $row['refunded_cents']
                : 0;
            $members[$row['member']] = ($members[$row['member']] ?? 0) + $net;
            $creators[$row['creator']] = ($creators[$row['creator']] ?? 0) + $net;
        }
        ksort($members);
        ksort($creators);

        return [
            'simulation' => true,
            'policy' => 'fans.support-simulation/1.0.0',
            'member_contributions' => $members,
            'creator_scores' => $creators,
        ];
    }

    /** @return array{version:string,owner:string,reference:string,revision:int,member:string,creator:string,category:string,source:string,amount_cents:int,refunded_cents:int,status:string,occurred_at:int} */
    private static function validate(mixed $fact): array
    {
        if (!is_array($fact) || count($fact) !== count(self::FIELDS)
            || array_diff(self::FIELDS, array_keys($fact)) !== []
            || ($fact['version'] ?? null) !== '1.0.0'
            || ($fact['owner'] ?? null) !== 'faluss-fans'
        ) {
            throw new InvalidArgumentException('Invalid support contract.');
        }
        foreach (['reference', 'member', 'creator'] as $field) {
            if (!is_string($fact[$field])
                || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $fact[$field]) !== 1
            ) {
                throw new InvalidArgumentException('Invalid opaque reference.');
            }
        }
        foreach (['revision', 'amount_cents', 'refunded_cents', 'occurred_at'] as $field) {
            if (!is_int($fact[$field]) || $fact[$field] < 0 || $fact[$field] > 2147483647) {
                throw new InvalidArgumentException('Invalid integer fact.');
            }
        }
        if ($fact['revision'] < 1 || $fact['occurred_at'] < 1 || $fact['amount_cents'] < 1
            || $fact['refunded_cents'] > $fact['amount_cents']
            || !in_array($fact['category'], ['hosted_allowed_content', 'external_adult_delivery_right'], true)
            || !in_array($fact['source'], ['eur_support', 'funded_support', 'pf_purchase', 'earned_pf', 'promotional_pf', 'cosmetic'], true)
            || !in_array($fact['status'], ['pending', 'confirmed', 'failed', 'refunded', 'disputed'], true)
        ) {
            throw new InvalidArgumentException('Invalid support state.');
        }
        // Canonical key order makes duplicate comparison independent of JSON field order.
        return [
            'version' => $fact['version'], 'owner' => $fact['owner'],
            'reference' => $fact['reference'], 'revision' => $fact['revision'],
            'member' => $fact['member'], 'creator' => $fact['creator'],
            'category' => $fact['category'], 'source' => $fact['source'],
            'amount_cents' => $fact['amount_cents'], 'refunded_cents' => $fact['refunded_cents'],
            'status' => $fact['status'], 'occurred_at' => $fact['occurred_at'],
        ];
    }
}
