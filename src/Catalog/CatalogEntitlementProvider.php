<?php

declare(strict_types=1);

namespace Faluss\Platform\Catalog;

final class CatalogEntitlementProvider
{
    public function __construct(
        private readonly string $connectorClass = 'Token_Engine_Connector_Service'
    ) {
    }

    /** @return array<string, string>|false */
    public function available(): array|false
    {
        $callback = [$this->connectorClass, 'entitlement_definitions'];
        if (!class_exists($this->connectorClass, false) || !is_callable($callback)) {
            return false;
        }

        $definitions = call_user_func($callback);
        if ($definitions instanceof \WP_Error || !is_array($definitions)) {
            return false;
        }

        return self::filter($definitions);
    }

    /** @param array<mixed> $definitions
     *  @return array<string, string>
     */
    public static function filter(array $definitions): array
    {
        $themes = [];

        foreach ($definitions as $definition) {
            if (!is_array($definition) || ($definition['type'] ?? null) !== 'theme') {
                continue;
            }

            $code = self::code($definition['code'] ?? null);
            if ($code === '') {
                continue;
            }

            $label = is_string($definition['label'] ?? null)
                ? sanitize_text_field($definition['label'])
                : '';
            $themes[$code] = $label === '' ? $code : (function_exists('mb_substr') ? mb_substr($label, 0, 120) : substr($label, 0, 120));
        }

        return $themes;
    }

    private static function code(mixed $value): string
    {
        $code = is_string($value) ? strtolower(trim($value)) : '';

        return preg_match('/^[a-z][a-z0-9_.-]{1,118}$/D', $code) ? $code : '';
    }
}
