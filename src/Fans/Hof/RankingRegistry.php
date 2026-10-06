<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Hof;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Immutable prepared metadata. A future attested adapter must record real economic opening. */
final class RankingRegistry
{
    private readonly RankingStorage $storage;
    /** @var array<string,string> */
    private readonly array $tables;

    public function __construct(private readonly \wpdb $db)
    { $this->storage = new RankingStorage($db); $this->tables = RankingSchema::tables($db); }

    /** @return array<string,string> */
    public function prepareOrigin(string $id): array
    {
        self::administrator(); ModelValues::uuid($id);
        return $this->storage->transaction('registry', function () use ($id): array {
            $existing = $this->originRow();
            if ($existing !== null) {
                if ($existing['origin_id'] !== $id || $existing['policy_version'] !== RankingPolicy::VERSION || $existing['state'] !== 'prepared') {
                    throw new ModelViolation('hof_origin_conflict');
                }
                return $existing;
            }
            $this->storage->query($this->db->prepare('INSERT INTO %i (id,origin_id,policy_version,state,prepared_by,prepared_at) VALUES (1,%s,%s,%s,%d,UTC_TIMESTAMP(6))',
                $this->tables['origin'], $id, RankingPolicy::VERSION, 'prepared', get_current_user_id()));
            return $this->originRow() ?? throw new ModelViolation('hof_storage_unavailable');
        });
    }

    /** @return array<string,string>|null */
    public function origin(): ?array
    { self::administrator(); return $this->storage->transaction('registry', fn (): ?array => $this->originRow()); }

    /** @return array<string,string> */
    public function dimension(string $kind, string $value = ''): array
    {
        self::administrator();
        $month = $kind === 'month' ? RankingCalendar::month($value) : null;
        $bounds = match ($kind) {
            'general' => $value === '' ? ['starts_at' => '', 'ends_at' => ''] : throw new ModelViolation('hof_invalid_dimension'),
            'category' => RankingPolicy::category($value) !== '' ? ['starts_at' => '', 'ends_at' => ''] : throw new ModelViolation('hof_invalid_dimension'),
            'month' => ['starts_at' => $month['start'], 'ends_at' => $month['end']],
            default => throw new ModelViolation('hof_invalid_dimension'),
        };
        return $this->storage->transaction('registry', function () use ($kind, $value, $bounds): array {
            $origin = $this->originRow() ?? throw new ModelViolation('hof_origin_not_prepared');
            if ($origin['state'] !== 'prepared' || $origin['policy_version'] !== RankingPolicy::VERSION) { throw new ModelViolation('hof_origin_conflict'); }
            $sql = $this->db->prepare('SELECT * FROM %i WHERE origin_id=%s AND kind=%s AND value=%s FOR UPDATE',
                $this->tables['dimensions'], $origin['origin_id'], $kind, $value);
            $row = $this->storage->row($sql);
            if ($row !== null) {
                if ($row['starts_at'] !== $bounds['starts_at'] || $row['ends_at'] !== $bounds['ends_at'] || $row['policy_version'] !== RankingPolicy::VERSION) {
                    throw new ModelViolation('hof_dimension_conflict');
                }
                return $row;
            }
            $this->storage->query($this->db->prepare('INSERT INTO %i (dimension_id,origin_id,kind,value,policy_version,starts_at,ends_at) VALUES (%s,%s,%s,%s,%s,%s,%s)',
                $this->tables['dimensions'], wp_generate_uuid4(), $origin['origin_id'], $kind, $value, RankingPolicy::VERSION, $bounds['starts_at'], $bounds['ends_at']));
            return $this->storage->row($sql) ?? throw new ModelViolation('hof_storage_unavailable');
        });
    }

    /** @return array<string,string>|null */
    private function originRow(): ?array
    { return $this->storage->row($this->db->prepare('SELECT * FROM %i WHERE id=1 FOR UPDATE', $this->tables['origin'])); }

    public static function administrator(): void
    { if (!current_user_can('manage_options')) { throw new ModelViolation('hof_forbidden'); } }
}
