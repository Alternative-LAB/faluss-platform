<?php

declare(strict_types=1);

namespace Faluss\Platform\Progression;

use InvalidArgumentException;

/** Pure fixture calculator: no authority authentication, storage or runtime integration. */
final class SupportSimulation
{
    private const FIELDS = [
        'version', 'owner', 'reference', 'revision', 'member', 'creator', 'category',
        'source', 'economic_class', 'funding', 'allocation_reference', 'coins',
        'amount_cents', 'refunded_coins', 'refunded_cents', 'status', 'occurred_at',
    ];

    /**
     * Latest cumulative revisions are reprojected even for a closed fixture session.
     * Donor consumption is cumulative; only creator scores use the session window.
     *
     * @param array<mixed> $facts
     * @param array<mixed>|null $session
     * @return array{simulation:true,policy:string,donor_consumed_eur_cents:array<string,int>,creator_score_centipoints:array<string,int>}
     */
    public static function project(array $facts, ?array $session = null): array
    {
        if (!array_is_list($facts) || count($facts) > 10000 || ($session !== null && (
            count($session) !== 3 || !is_int($session['start'] ?? null)
            || !is_int($session['end'] ?? null) || !is_bool($session['closed'] ?? null)
            || $session['start'] < 0 || $session['end'] <= $session['start']
        ))) {
            throw new InvalidArgumentException('Invalid simulation batch or session.');
        }
        $histories = [];
        foreach ($facts as $fact) {
            $row = self::validate($fact);
            $key = $row['owner'] . ':' . $row['reference'];
            $revision = $row['revision'];
            if (isset($histories[$key][$revision]) && $histories[$key][$revision] !== $row) {
                throw new InvalidArgumentException('Conflicting fact revision.');
            }
            $histories[$key][$revision] = $row;
        }
        $members = $creators = $allocations = [];
        foreach ($histories as $key => $history) {
            ksort($history);
            $previous = null;
            foreach ($history as $row) {
                if ($previous !== null) {
                    foreach (self::FIELDS as $field) {
                        if (!in_array($field, ['revision', 'status', 'refunded_coins', 'refunded_cents'], true)
                            && $row[$field] !== $previous[$field]
                        ) {
                            throw new InvalidArgumentException('Changed immutable support fact.');
                        }
                    }
                    if ($row['refunded_coins'] < $previous['refunded_coins']
                        || $row['refunded_cents'] < $previous['refunded_cents']
                    ) {
                        throw new InvalidArgumentException('Decreased cumulative refund.');
                    }
                }
                $previous = $row;
            }
            $row = end($history);
            // A fixture allocation identifies one consumed slice, never a whole reusable pack.
            if ($row['allocation_reference'] !== null) {
                if (isset($allocations[$row['allocation_reference']])) {
                    throw new InvalidArgumentException('Reused consumption allocation.');
                }
                $allocations[$row['allocation_reference']] = $key;
            }
            $score = $spent = 0;
            if ($row['status'] === 'confirmed' && $row['category'] === 'hosted_allowed_content') {
                if ($row['source'] === 'funded_coin_gift') {
                    $score = ($row['coins'] - $row['refunded_coins']) * 100;
                    $spent = $row['amount_cents'] - $row['refunded_cents'];
                } elseif ($row['source'] === 'direct_eur_support') {
                    $score = $spent = $row['amount_cents'] - $row['refunded_cents'];
                }
            }
            if ($session !== null && ($row['occurred_at'] < $session['start'] || $row['occurred_at'] >= $session['end'])) {
                $score = 0;
            }
            $members[$row['member']] = ($members[$row['member']] ?? 0) + $spent;
            $creators[$row['creator']] = ($creators[$row['creator']] ?? 0) + $score;
        }
        ksort($members);
        ksort($creators);
        return [
            'simulation' => true, 'policy' => 'fans.support-simulation/2.0.0',
            'donor_consumed_eur_cents' => $members, 'creator_score_centipoints' => $creators,
        ];
    }

    /** @return array{version:string,owner:string,reference:string,revision:int,member:string,creator:string,category:string,source:string,economic_class:string,funding:string,allocation_reference:?string,coins:int,amount_cents:int,refunded_coins:int,refunded_cents:int,status:string,occurred_at:int} */
    private static function validate(mixed $fact): array
    {
        if (!is_array($fact) || count($fact) !== count(self::FIELDS)
            || array_diff(self::FIELDS, array_keys($fact)) !== []
            || ($fact['version'] ?? null) !== '2.0.0' || ($fact['owner'] ?? null) !== 'faluss-fans'
        ) {
            throw new InvalidArgumentException('Invalid support contract.');
        }
        foreach (['reference', 'member', 'creator'] as $field) {
            if (!self::uuid($fact[$field])) {
                throw new InvalidArgumentException('Invalid opaque reference.');
            }
        }
        foreach (['revision', 'coins', 'amount_cents', 'refunded_coins', 'refunded_cents', 'occurred_at'] as $field) {
            if (!is_int($fact[$field]) || $fact[$field] < 0 || $fact[$field] > 2147483647) {
                throw new InvalidArgumentException('Invalid integer fact.');
            }
        }
        if ($fact['revision'] < 1 || $fact['occurred_at'] < 1
            || $fact['refunded_coins'] > $fact['coins'] || $fact['refunded_cents'] > $fact['amount_cents']
            || !in_array($fact['category'], ['hosted_allowed_content', 'external_adult_delivery_right'], true)
            || !in_array($fact['source'], ['pack_purchase', 'funded_coin_gift', 'direct_eur_support', 'free_gift'], true)
            || !in_array($fact['economic_class'], ['none', 'funded', 'earned', 'promotional'], true)
            || !in_array($fact['funding'], ['none', 'donor', 'faluss'], true)
            || !in_array($fact['status'], ['pending', 'confirmed', 'failed', 'refunded', 'disputed'], true)
        ) {
            throw new InvalidArgumentException('Invalid support state.');
        }
        if (in_array($fact['source'], ['funded_coin_gift', 'direct_eur_support'], true)
            && $fact['member'] === $fact['creator']
        ) {
            throw new InvalidArgumentException('Self-support is not allowed.');
        }
        if ($fact['source'] === 'free_gift') {
            if ($fact['funding'] === 'faluss') {
                throw new InvalidArgumentException('Faluss funded gift allocation policy remains undefined.');
            }
            $valid = $fact['funding'] === 'none' && $fact['amount_cents'] === 0
                && $fact['coins'] > 0 && $fact['allocation_reference'] === null
                && in_array($fact['economic_class'], ['none', 'earned', 'promotional'], true);
        } elseif ($fact['source'] === 'funded_coin_gift') {
            $valid = $fact['funding'] === 'donor' && $fact['economic_class'] === 'funded'
                && $fact['coins'] > 0 && $fact['amount_cents'] > 0 && self::uuid($fact['allocation_reference']);
        } else {
            $valid = $fact['funding'] === 'donor' && $fact['amount_cents'] > 0
                && $fact['allocation_reference'] === null
                && ($fact['source'] === 'pack_purchase'
                    ? $fact['economic_class'] === 'funded' && $fact['coins'] > 0
                    : $fact['economic_class'] === 'none' && $fact['coins'] === 0);
        }
        if (!$valid) {
            throw new InvalidArgumentException('Inconsistent economic fixture.');
        }
        // Canonical field order makes replay insensitive to JSON field ordering.
        return [
            'version' => $fact['version'], 'owner' => $fact['owner'],
            'reference' => $fact['reference'], 'revision' => $fact['revision'],
            'member' => $fact['member'], 'creator' => $fact['creator'],
            'category' => $fact['category'], 'source' => $fact['source'],
            'economic_class' => $fact['economic_class'], 'funding' => $fact['funding'],
            'allocation_reference' => $fact['allocation_reference'], 'coins' => $fact['coins'],
            'amount_cents' => $fact['amount_cents'], 'refunded_coins' => $fact['refunded_coins'],
            'refunded_cents' => $fact['refunded_cents'], 'status' => $fact['status'],
            'occurred_at' => $fact['occurred_at'],
        ];
    }

    private static function uuid(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) === 1;
    }
}
