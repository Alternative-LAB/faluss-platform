<?php

declare(strict_types=1);

namespace Faluss\Platform\Link\Import;

/** Parses only member-supplied content; never retrieves a remote page or URL. */
final class LinkImportParser
{
    public const MAX_BYTES = 524288;
    public const MAX_ROWS = 500;
    private const LABEL_FIELDS = ['title', 'label', 'name', 'titre', 'text'];
    private const URL_FIELDS = ['url', 'href', 'link', 'lien', 'address', 'website', 'destination'];
    private const ORDER_FIELDS = ['order', 'position', 'sort_order', 'rank', 'ordre'];

    /** @return list<array{label:string,url:string,order:int}> */
    public function parse(string $source, string $format = 'auto'): array
    {
        if (strlen($source) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('too_large');
        }
        $source = trim((string) preg_replace('/^\xEF\xBB\xBF/', '', $source));
        if (!in_array($format, ['auto', 'csv', 'json', 'text'], true)) {
            throw new \InvalidArgumentException('unsupported_format');
        }
        if ($format === 'auto') {
            $isMarkdown = preg_match('/^\[[^\]\r\n]*\]\(/u', $source) === 1;
            $format = !$isMarkdown && (str_starts_with($source, '[') || str_starts_with($source, '{')) ? 'json' : 'text';
            if ($format === 'text' && $this->looksLikeCsv($source)) {
                $format = 'csv';
            }
        }
        $rows = match ($format) {
            'json' => $this->json($source),
            'csv' => $this->csv($source),
            default => $this->text($source),
        };
        if ($rows === []) {
            throw new \InvalidArgumentException('no_links');
        }
        if (count($rows) > self::MAX_ROWS) {
            throw new \InvalidArgumentException('too_many_rows');
        }
        // PHP's stable sort preserves the source sequence for equal positions.
        usort($rows, static fn (array $left, array $right): int => $left['order'] <=> $right['order']);
        return $rows;
    }

    /** @return list<array{label:string,url:string,order:int}> */
    private function json(string $source): array
    {
        try {
            $decoded = json_decode($source, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('invalid_json');
        }
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('invalid_json');
        }
        $rows = [];
        $this->collectJson($decoded, $rows, 0);
        return $rows;
    }

    /**
     * @param array<mixed> $node
     * @param list<array{label:string,url:string,order:int}> $rows
     */
    private function collectJson(array $node, array &$rows, int $depth, bool $record = false): void
    {
        if ($depth > 16) {
            throw new \InvalidArgumentException('invalid_json');
        }
        $fields = $this->fields($node);
        $url = $this->first($fields, self::URL_FIELDS);
        $label = $this->first($fields, self::LABEL_FIELDS);
        if ($url !== null || ($record && $label !== null)) {
            $rows[] = $this->row($label ?? '', $url ?? '', $this->first($fields, self::ORDER_FIELDS), count($rows));
        } elseif ($record && $node === []) {
            $rows[] = $this->row('', '', null, count($rows));
        }
        if (count($rows) > self::MAX_ROWS) {
            throw new \InvalidArgumentException('too_many_rows');
        }
        foreach ($node as $key => $child) {
            if (is_array($child) && (is_int($key) || in_array(strtolower($key), ['links', 'items', 'data', 'account', 'profile', 'page', 'response', 'export', 'linktree', 'children'], true))) {
                $this->collectJson($child, $rows, $depth + 1, is_int($key));
            } elseif (is_int($key) && is_string($child)) {
                $rows[] = $this->row('', $child, null, count($rows));
                if (count($rows) > self::MAX_ROWS) {
                    throw new \InvalidArgumentException('too_many_rows');
                }
            }
        }
    }

    /** @return list<array{label:string,url:string,order:int}> */
    private function csv(string $source): array
    {
        if (substr_count($source, '"') % 2 !== 0) {
            throw new \InvalidArgumentException('invalid_csv');
        }
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new \InvalidArgumentException('invalid_csv');
        }
        fwrite($stream, $source);
        rewind($stream);
        $delimiter = $this->delimiter($source);
        $rows = [];
        $headers = null;
        try {
            while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
                if ($cells === [null]) {
                    continue;
                }
                $cells = array_map(static fn (?string $value): string => trim($value ?? ''), $cells);
                if ($headers === null) {
                    $candidate = array_map([$this, 'fieldName'], $cells);
                    if (array_intersect($candidate, [...self::URL_FIELDS, ...self::LABEL_FIELDS]) !== []) {
                        $headers = $candidate;
                        continue;
                    }
                    $headers = [];
                }
                if ($headers !== []) {
                    $fields = [];
                    foreach ($headers as $index => $header) {
                        $fields[$header] = $cells[$index] ?? '';
                    }
                    $rows[] = $this->row($this->first($fields, self::LABEL_FIELDS) ?? '', $this->first($fields, self::URL_FIELDS) ?? '', $this->first($fields, self::ORDER_FIELDS), count($rows));
                } else {
                    $urlIndex = 0;
                    foreach ($cells as $index => $cell) {
                        if ($this->containsUrl($cell)) {
                            $urlIndex = $index;
                            break;
                        }
                    }
                    $labelIndex = $urlIndex === 0 ? 1 : 0;
                    $rows[] = $this->row($cells[$labelIndex] ?? '', $cells[$urlIndex], $cells[2] ?? null, count($rows));
                }
                if (count($rows) > self::MAX_ROWS) {
                    throw new \InvalidArgumentException('too_many_rows');
                }
            }
        } finally {
            fclose($stream);
        }
        return $rows;
    }

    private function delimiter(string $source): string
    {
        $firstLine = explode("\n", $source, 2)[0];
        $delimiter = ',';
        $maximum = 0;
        foreach ([',', ';', "\t"] as $candidate) {
            $count = count(str_getcsv($firstLine, $candidate, '"', ''));
            if ($count > $maximum) {
                $delimiter = $candidate;
                $maximum = $count;
            }
        }
        return $delimiter;
    }

    private function looksLikeCsv(string $source): bool
    {
        $line = explode("\n", $source, 2)[0];
        $cells = str_getcsv($line, $this->delimiter($source), '"', '');
        if (count($cells) < 2) {
            return false;
        }
        $names = array_map(fn (?string $cell): string => $this->fieldName($cell ?? ''), $cells);
        return array_intersect($names, self::URL_FIELDS) !== []
            || ($this->containsUrl($line) && !str_contains($line, '](') && !str_contains($line, '<'));
    }

    /** @return list<array{label:string,url:string,order:int}> */
    private function text(string $source): array
    {
        if (preg_match('/<a\s/i', $source) === 1) {
            return $this->anchors($source);
        }
        $rows = [];
        $previousTitle = '';
        foreach (preg_split('/\R/u', $source) ?: [] as $line) {
            $line = trim($line);
            $matched = false;
            $line = (string) preg_replace_callback('~\[([^\]\r\n]*)\]\(([^\s)]+)(?:\s+"[^"]*")?\)~u', function (array $match) use (&$rows, &$matched): string {
                $rows[] = $this->row($match[1], $match[2], null, count($rows));
                $matched = true;
                return '';
            }, $line);
            preg_match_all('~(?:https?://|www\.)[^\s<>"\']+|(?<![@\w])(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}(?:/[^\s<>"\']*)?~iu', $line, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as [$url, $offset]) {
                $url = $this->trimTextUrl($url);
                $prefix = trim(substr($line, 0, $offset), " \t-|:•");
                $suffix = trim(substr($line, $offset + strlen($url)), " \t-|:•.,;)");
                $label = count($matches[0]) === 1 ? ($prefix !== '' ? $prefix : ($suffix !== '' ? $suffix : $previousTitle)) : '';
                $rows[] = $this->row($label, $url, null, count($rows));
                $matched = true;
            }
            if (count($rows) > self::MAX_ROWS) {
                throw new \InvalidArgumentException('too_many_rows');
            }
            $previousTitle = $matched ? '' : trim($line, " \t#*-•");
        }
        return $rows;
    }

    /** @return list<array{label:string,url:string,order:int}> */
    private function anchors(string $source): array
    {
        if (!class_exists(\DOMDocument::class)) {
            throw new \InvalidArgumentException('unsupported_html');
        }
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $source, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            if (!$loaded) {
                return [];
            }
            $rows = [];
            foreach ($document->getElementsByTagName('a') as $anchor) {
                $rows[] = $this->row($anchor->textContent, $anchor->getAttribute('href'), null, count($rows));
                if (count($rows) > self::MAX_ROWS) {
                    throw new \InvalidArgumentException('too_many_rows');
                }
            }
            return $rows;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function trimTextUrl(string $url): string
    {
        $url = rtrim($url, '.,;!?:');
        while (str_ends_with($url, ')') && substr_count($url, ')') > substr_count($url, '(')) {
            $url = substr($url, 0, -1);
        }
        return $url;
    }

    /**
     * @param array<mixed> $values
     * @return array<string,string>
     */
    private function fields(array $values): array
    {
        $fields = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && (is_string($value) || is_int($value) || is_float($value))) {
                $fields[$this->fieldName($key)] = (string) $value;
            }
        }
        return $fields;
    }

    private function fieldName(string $name): string
    {
        return str_replace([' ', '-'], '_', strtolower(trim($name)));
    }

    /**
     * @param array<string,string> $fields
     * @param list<string> $names
     */
    private function first(array $fields, array $names): ?string
    {
        foreach ($names as $name) {
            if (array_key_exists($name, $fields)) {
                return $fields[$name];
            }
        }
        return null;
    }

    private function containsUrl(string $value): bool
    {
        return preg_match('~(?:https?://|www\.|[a-z0-9-]+\.[a-z]{2,})~i', $value) === 1;
    }

    /** @return array{label:string,url:string,order:int} */
    private function row(string $label, string $url, ?string $order, int $fallback): array
    {
        $position = $order !== null && preg_match('/^\d{1,9}$/D', trim($order)) === 1 ? (int) $order : $fallback;
        return [
            'label' => trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($label), ENT_QUOTES | ENT_HTML5, 'UTF-8'))),
            'url' => (new LinkImportValidator())->normalizeUrl($url),
            'order' => $position,
        ];
    }
}
