<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngineConnector;

final class ConnectorSettingsUpgrade
{
    public const OPTION = 'token_engine_connector_settings';
    public const VERSION_OPTION = 'token_engine_connector_settings_version';
    public const VERSION = '2';

    public static function activate(): void
    {
        self::maybeUpgrade();
    }

    public static function maybeUpgrade(): void
    {
        $stored = get_option(self::OPTION, []);
        $stored = is_array($stored) ? $stored : [];

        if (!array_key_exists('core_site_url', $stored) && isset($stored['core_url'])) {
            $siteUrl = ConnectorService::migrateLegacySiteUrl($stored['core_url']);
            if ($siteUrl !== '') {
                $stored['core_site_url'] = $siteUrl;
                unset($stored['core_url']);
                update_option(self::OPTION, $stored, false);
            }
        }

        if (get_option(self::VERSION_OPTION) !== self::VERSION) {
            update_option(self::VERSION_OPTION, self::VERSION, false);
        }
    }
}
