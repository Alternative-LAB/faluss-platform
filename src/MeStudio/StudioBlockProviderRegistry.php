<?php

declare(strict_types=1);

namespace Faluss\Platform\MeStudio;

use LogicException;

/** Strict local runtime registry. Apps Registry manifests remain descriptive only. */
final class StudioBlockProviderRegistry
{
    private static ?self $shared = null;

    /** @var array<string, StudioBlockProvider> */
    private array $providers = [];

    public static function shared(): self
    {
        return self::$shared ??= new self();
    }

    public function register(StudioBlockProvider $provider): void
    {
        $descriptor = $provider->descriptor();
        if (!$this->validDescriptor($descriptor)) {
            throw new LogicException('Invalid Faluss Studio block provider descriptor.');
        }
        $id = $descriptor['id'];
        if (isset($this->providers[$id])) {
            throw new LogicException('Duplicate Faluss Studio block provider.');
        }
        $this->providers[$id] = $provider;
    }

    /** @return list<array<string, mixed>> */
    public function descriptors(): array
    {
        $descriptors = array_map(static fn (StudioBlockProvider $provider): array => $provider->descriptor(), $this->providers);
        usort($descriptors, static fn (array $left, array $right): int => strcmp($left['id'], $right['id']));

        return $descriptors;
    }

    /** Test-only reset; production keeps one registry for the complete request. */
    public static function resetForTests(): void
    {
        self::$shared = null;
    }

    /** @param array<string, mixed> $descriptor */
    private function validDescriptor(array $descriptor): bool
    {
        $keys = array_keys($descriptor);
        sort($keys, SORT_STRING);
        if ($keys !== ['actions', 'fallback', 'id', 'placement', 'read_model_contract', 'visibility']) {
            return false;
        }
        $readModelKeys = is_array($descriptor['read_model_contract']) ? array_keys($descriptor['read_model_contract']) : [];
        sort($readModelKeys, SORT_STRING);
        if (!is_string($descriptor['id']) || preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $descriptor['id']) !== 1
            || !in_array($descriptor['placement'], ['me.studio.tab', 'me.studio.block_source', 'me.public.tab', 'me.public.block'], true)
            || !is_array($descriptor['read_model_contract'])
            || $readModelKeys !== ['contract_version', 'document_type']
            || !is_string($descriptor['read_model_contract']['document_type'])
            || preg_match('/^[a-z][a-z0-9.-]+$/D', $descriptor['read_model_contract']['document_type']) !== 1
            || !is_string($descriptor['read_model_contract']['contract_version'])
            || preg_match('/^[1-9][0-9]*\.[0-9]+\.[0-9]+$/D', $descriptor['read_model_contract']['contract_version']) !== 1
            || !is_array($descriptor['actions']) || !array_is_list($descriptor['actions'])
            || !in_array($descriptor['visibility'], ['all', 'members', 'owner'], true)
            || !in_array($descriptor['fallback'], ['hide', 'placeholder', 'read_only'], true)
        ) {
            return false;
        }
        foreach ($descriptor['actions'] as $action) {
            if (!is_string($action) || preg_match('/^[a-z][a-z0-9-]{1,63}(?:\.[a-z][a-z0-9_.-]{1,127})+$/D', $action) !== 1) {
                return false;
            }
        }

        return count($descriptor['actions']) === count(array_unique($descriptor['actions'], SORT_STRING));
    }
}
