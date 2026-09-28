<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\Fans\PfContract;

use Closure;
use RuntimeException;

/** Executable proposal only. In-memory fixture server, never a Hub implementation. */
final class FakeHub
{
    public const VERSION = 'fans.hub-purchased-pf/0.1.0';
    private const TEST_KEY = 'public-test-only-not-a-deployment-secret';
    private array $intents = [];
    private array $keys = [];
    private array $corrections = [];
    private bool $locked = false;
    private bool $mappingComplete = true;
    private string $lotState = 'confirmed';
    private int $lotRevision = 1;
    public int $now = 100;
    public bool $unavailable = false;

    /** Seed is a synthetic purchased lot, not a purchase API or ledger. */
    public function __construct(
        private readonly string $member = 'fan-a',
        private readonly int $lotQuantity = 10,
        private readonly string $lotClass = 'funded',
        private readonly bool $purchaseAttested = true,
    ) {
    }

    /** Controlled interleaving exercises the lock contract, not database concurrency. */
    public function reserve(array $request, ?Closure $insideLock = null, bool $loseResponse = false): array
    {
        $this->authenticate($request);
        if ($this->locked) {
            throw new RuntimeException('busy');
        }
        $this->locked = true;
        try {
            if ($insideLock !== null) {
                $insideLock();
            }
            $fields = ['version', 'audience', 'caller', 'key', 'attribution', 'member', 'creator', 'pf'];
            if (count($request) !== count($fields) || array_diff($fields, array_keys($request)) !== []) {
                throw new RuntimeException('invalid_shape');
            }
            if (!is_string($request['key']) || $request['key'] === ''
                || !is_string($request['attribution']) || $request['attribution'] === ''
                || !is_string($request['creator']) || $request['creator'] === ''
                || $request['member'] !== $this->member || $request['creator'] === $this->member
                || !is_int($request['pf']) || $request['pf'] < 1
            ) {
                throw new RuntimeException('ineligible');
            }
            ksort($request);
            $digest = hash('sha256', json_encode($request, JSON_THROW_ON_ERROR));
            if (isset($this->keys[$request['key']])) {
                if ($this->keys[$request['key']]['digest'] !== $digest) {
                    throw new RuntimeException('idempotency_conflict');
                }
                return $this->intents[$this->keys[$request['key']]['attribution']];
            }
            if (isset($this->intents[$request['attribution']])) {
                throw new RuntimeException('attribution_conflict');
            }
            if (!$this->purchaseAttested || $this->lotClass !== 'funded' || $this->lotState !== 'confirmed') {
                throw new RuntimeException('purchase_unavailable');
            }
            $used = 0;
            foreach ($this->intents as &$intent) {
                if ($intent['state'] === 'reserved' && $intent['expires_at'] <= $this->now) {
                    $intent['state'] = 'expired';
                }
                if (in_array($intent['state'], ['reserved', 'confirmed'], true)) {
                    $used += $intent['pf'];
                }
            }
            unset($intent);
            if ($used + $request['pf'] > $this->lotQuantity) {
                throw new RuntimeException('insufficient_purchased_pf');
            }
            $result = [
                'attribution' => $request['attribution'], 'member' => $this->member,
                'creator' => $request['creator'], 'lot' => 'lot-a', 'purchase_proof' => 'proof-a',
                'pf' => $request['pf'], 'state' => 'reserved', 'expires_at' => $this->now + 30,
                'receipt' => null,
            ];
            $this->keys[$request['key']] = ['digest' => $digest, 'attribution' => $request['attribution']];
            $this->intents[$request['attribution']] = $result;
            if ($loseResponse) {
                throw new RuntimeException('timeout_after_commit');
            }
            return $result;
        } finally {
            $this->locked = false;
        }
    }

    public function lookup(string $key, string $caller = 'fans'): ?array
    {
        $this->authenticate(['version' => self::VERSION, 'audience' => 'hub', 'caller' => $caller]);
        if (!isset($this->keys[$key])) {
            return null;
        }
        $reference = $this->keys[$key]['attribution'];
        if ($this->intents[$reference]['state'] === 'reserved' && $this->intents[$reference]['expires_at'] <= $this->now) {
            $this->intents[$reference]['state'] = 'expired';
        }
        return $this->intents[$reference];
    }

    public function confirm(string $attribution, string $caller = 'fans', bool $loseResponse = false): array
    {
        $this->authenticate(['version' => self::VERSION, 'audience' => 'hub', 'caller' => $caller]);
        $intent = $this->intents[$attribution] ?? throw new RuntimeException('not_found');
        if ($intent['state'] === 'confirmed') {
            return $intent['receipt'];
        }
        if ($intent['state'] !== 'reserved' || $intent['expires_at'] <= $this->now || $this->lotState !== 'confirmed') {
            throw new RuntimeException('reservation_closed');
        }
        $payload = [
            'version' => self::VERSION, 'issuer' => 'hub', 'audience' => 'fans',
            'receipt_id' => 'receipt-' . $attribution, 'attribution' => $attribution,
            'member' => $intent['member'], 'creator' => $intent['creator'],
            'lot' => 'lot-a', 'purchase_proof' => 'proof-a', 'pf' => $intent['pf'], 'revision' => 1,
        ];
        $receipt = self::seal($payload);
        $this->intents[$attribution]['state'] = 'confirmed';
        $this->intents[$attribution]['receipt'] = $receipt;
        if ($loseResponse) {
            throw new RuntimeException('timeout_after_commit');
        }
        return $receipt;
    }

    public function release(string $attribution): void
    {
        $this->authenticate(['version' => self::VERSION, 'audience' => 'hub', 'caller' => 'fans']);
        if (!isset($this->intents[$attribution])) {
            throw new RuntimeException('not_found');
        }
        if ($this->intents[$attribution]['state'] === 'confirmed') {
            throw new RuntimeException('already_consumed');
        }
        $this->intents[$attribution]['state'] = 'released';
    }

    /** Simulate missing traceability without modifying any real owner storage. */
    public function breakMapping(): void
    {
        $this->mappingComplete = false;
        $this->lotState = 'review_required';
    }

    /** Owner-only fixture control, not a Fans command. No real dispute or refund is performed. */
    public function correctLot(int $revision, string $state, bool $partial = false): array
    {
        $this->authenticate(['version' => self::VERSION, 'audience' => 'hub', 'caller' => 'fans']);
        if ($partial) {
            throw new RuntimeException('hub_partial_compensation_missing');
        }
        if (!$this->mappingComplete) {
            throw new RuntimeException('mapping_incomplete_manual_review');
        }
        if (!in_array($state, ['disputed', 'refunded', 'confirmed'], true) || $revision < 2) {
            throw new RuntimeException('invalid_correction');
        }
        if (isset($this->corrections[$revision])) {
            if ($this->corrections[$revision]['state'] !== $state) {
                throw new RuntimeException('revision_conflict');
            }
            return $this->corrections[$revision]['envelope'];
        }
        if ($revision <= $this->lotRevision || $this->lotState === 'refunded') {
            throw new RuntimeException('stale_or_terminal');
        }
        $affected = [];
        foreach ($this->intents as $reference => &$intent) {
            if ($intent['state'] === 'reserved') {
                $intent['state'] = 'released';
            }
            if ($intent['state'] === 'confirmed') {
                $affected[$reference] = ['receipt_id' => $intent['receipt']['payload']['receipt_id'], 'pf' => $intent['pf']];
            }
        }
        unset($intent);
        ksort($affected);
        $envelope = self::seal([
            'version' => self::VERSION, 'issuer' => 'hub', 'audience' => 'fans', 'lot' => 'lot-a',
            'revision' => $revision, 'state' => $state, 'complete' => true, 'affected' => $affected,
        ]);
        $this->corrections[$revision] = ['state' => $state, 'envelope' => $envelope];
        $this->lotRevision = $revision;
        $this->lotState = $state;
        return $envelope;
    }

    /** HMAC is a test tamper detector only, not the proposed production signer. */
    private static function seal(array $payload): array
    {
        return ['payload' => $payload, 'signature' => hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), self::TEST_KEY)];
    }

    public static function verify(array $envelope): bool
    {
        if (array_keys($envelope) !== ['payload', 'signature'] || !is_array($envelope['payload']) || !is_string($envelope['signature'])) {
            return false;
        }
        $payload = $envelope['payload'];
        return ($payload['version'] ?? null) === self::VERSION && ($payload['issuer'] ?? null) === 'hub'
            && ($payload['audience'] ?? null) === 'fans'
            && hash_equals(self::seal($payload)['signature'], $envelope['signature']);
    }

    private function authenticate(array $request): void
    {
        if ($this->unavailable) {
            throw new RuntimeException('unavailable');
        }
        if (($request['version'] ?? null) !== self::VERSION || ($request['caller'] ?? null) !== 'fans'
            || ($request['audience'] ?? null) !== 'hub'
        ) {
            throw new RuntimeException('unauthorized_contract');
        }
    }
}
