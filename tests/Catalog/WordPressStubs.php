<?php

declare(strict_types=1);

namespace Faluss\Platform\Catalog;

function sanitize_title(string $value): string
{
    return strtolower(trim(preg_replace('/[^a-z0-9-]+/i', '-', $value) ?? '', '-'));
}

function sanitize_text_field(string $value): string
{
    return trim(strip_tags($value));
}

function sanitize_hex_color(string $value): string
{
    return preg_match('/^#[a-f0-9]{6}$/i', $value) ? $value : '';
}

function absint(mixed $value): int
{
    return abs((int) $value);
}

function wp_attachment_is_image(int $id): bool
{
    return $id === 17;
}
