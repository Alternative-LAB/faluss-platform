<?php

declare(strict_types=1);

namespace Faluss\Platform\Link\Import;

use InvalidArgumentException;

/** Validates the untrusted preview and commit boundary; never owns storage. */
final class LinkImportBatch
{
    /** @param array<mixed> $rows
     * @return list<array{label: string, url: string, order: int}>
     */
    public static function rows(array $rows): array
    {
        if (!array_is_list($rows) || count($rows) > LinkImportParser::MAX_ROWS) {
            throw new InvalidArgumentException('too_many_rows');
        }
        $clean = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row) || array_diff(array_keys($row), ['label', 'url', 'order'])
                || !is_string($row['label'] ?? null) || !is_string($row['url'] ?? null)
                || (isset($row['order']) && !is_int($row['order']))) {
                throw new InvalidArgumentException('invalid_rows');
            }
            $clean[] = ['label' => $row['label'], 'url' => $row['url'], 'order' => $row['order'] ?? $index];
        }
        return $clean;
    }
}
