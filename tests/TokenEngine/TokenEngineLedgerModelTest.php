<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine;

use PHPUnit\Framework\TestCase;

final class TokenEngineLedgerModelTest extends TestCase
{
    public function testDailyRewardsStayCumulativeIdempotentAndNonNegative(): void
    {
        $entries = [];
        $hub = $this->entry('hub-credit', 20, 'credit', 'earned', 'daily-hub');
        $me = $this->entry('me-credit', 75, 'credit', 'earned', 'daily-me');

        self::assertSame('appended', $this->append($entries, $hub));
        self::assertSame('idempotent', $this->append($entries, $hub));
        self::assertSame('appended', $this->append($entries, $me));
        self::assertSame(95, $this->balance($entries, 'earned'));
        self::assertSame('insufficient_balance', $this->append(
            $entries,
            $this->entry('overspend', 96, 'debit', 'earned', 'overspend')
        ));
        self::assertCount(2, $entries);
    }

    public function testCompensationIsAppendOnlySameClassAndUniquePerOriginal(): void
    {
        $entries = [];
        $credit = $this->entry('funded-credit', 100, 'credit', 'funded', 'funded-credit');
        $debit = $this->entry('funded-debit', 40, 'debit', 'funded', 'funded-debit');
        self::assertSame('appended', $this->append($entries, $credit));
        self::assertSame('appended', $this->append($entries, $debit));

        $wrong = $this->entry('wrong-comp', 40, 'compensation', 'earned', 'wrong-comp', 'funded-debit');
        self::assertSame('invalid_compensation', $this->append($entries, $wrong));
        $valid = $this->entry('valid-comp', 40, 'compensation', 'funded', 'valid-comp', 'funded-debit');
        self::assertSame('appended', $this->append($entries, $valid));
        self::assertSame('idempotent', $this->append($entries, $valid));
        $second = $this->entry('second-comp', 40, 'compensation', 'funded', 'second-comp', 'funded-debit');
        self::assertSame('already_compensated', $this->append($entries, $second));
        self::assertSame(100, $this->balance($entries, 'funded'));
        self::assertCount(3, $entries);
    }

    /** @return array<string, mixed> */
    private function entry(string $uuid, int $amount, string $direction, string $class, string $key, ?string $original = null): array
    {
        return [
            'entry_uuid' => $uuid,
            'amount_pf' => $amount,
            'direction' => $direction,
            'economic_class' => $class,
            'idempotency_key' => $key,
            'compensates_entry_uuid' => $original,
        ];
    }

    /** @param list<array<string, mixed>> $entries */
    private function append(array &$entries, array $entry): string
    {
        foreach ($entries as $existing) {
            if ($existing['idempotency_key'] === $entry['idempotency_key']) {
                return 'idempotent';
            }
        }
        if ($entry['direction'] === 'compensation') {
            $original = null;
            foreach ($entries as $existing) {
                if ($existing['entry_uuid'] === $entry['compensates_entry_uuid']) {
                    $original = $existing;
                }
                if ($existing['direction'] === 'compensation'
                    && $existing['compensates_entry_uuid'] === $entry['compensates_entry_uuid']
                ) {
                    return 'already_compensated';
                }
            }
            if (!is_array($original)
                || $original['economic_class'] !== $entry['economic_class']
                || $original['direction'] === 'compensation'
            ) {
                return 'invalid_compensation';
            }
        }

        $projected = $this->balance($entries, (string) $entry['economic_class'])
            + ($entry['direction'] === 'credit' ? $entry['amount_pf'] : -$entry['amount_pf']);
        if ($entry['direction'] === 'compensation') {
            foreach ($entries as $original) {
                if ($original['entry_uuid'] === $entry['compensates_entry_uuid']) {
                    $projected = $this->balance($entries, (string) $entry['economic_class'])
                        + ($original['direction'] === 'debit' ? $entry['amount_pf'] : -$entry['amount_pf']);
                    break;
                }
            }
        }
        if ($projected < 0) {
            return 'insufficient_balance';
        }

        $entries[] = $entry;

        return 'appended';
    }

    /** @param list<array<string, mixed>> $entries */
    private function balance(array $entries, string $class): int
    {
        $byUuid = [];
        foreach ($entries as $entry) {
            $byUuid[$entry['entry_uuid']] = $entry;
        }
        $balance = 0;
        foreach ($entries as $entry) {
            if ($entry['economic_class'] !== $class) {
                continue;
            }
            if ($entry['direction'] === 'credit') {
                $balance += $entry['amount_pf'];
            } elseif ($entry['direction'] === 'debit') {
                $balance -= $entry['amount_pf'];
            } elseif (isset($byUuid[$entry['compensates_entry_uuid']])) {
                $balance += $byUuid[$entry['compensates_entry_uuid']]['direction'] === 'debit'
                    ? $entry['amount_pf']
                    : -$entry['amount_pf'];
            }
        }

        return $balance;
    }
}
