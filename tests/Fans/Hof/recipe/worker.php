<?php

declare(strict_types=1);

use Faluss\Platform\Fans\Hof\RankingRegistry;
use Faluss\Platform\Fans\Hof\RankingSchema;
use Faluss\Platform\Fans\Hof\RankingVisibility;
use Faluss\Platform\Fans\Profiles\CreatorProfileService;
use Faluss\Platform\Fans\Profiles\CreatorStatusSchema;
use Faluss\Platform\Fans\Profiles\EditorialService;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

if (!defined('WP_CLI') || !WP_CLI || DB_NAME !== 'admission_recipe'
    || preg_match('#^/var/tmp/fans-admission-wp-[A-Za-z0-9_-]+/wordpress/$#D', ABSPATH) !== 1) {
    throw new RuntimeException('Disposable fixture only');
}
/** @var array<string,mixed> $input */
$input = json_decode(file_get_contents($args[0]), true, 512, JSON_THROW_ON_ERROR);
global $wpdb;
wp_set_current_user((int) $input['actor']);
if (isset($input['barrier'])) {
    file_put_contents($input['barrier'] . '.' . $input['worker'] . '.ready', 'ready');
    $until = microtime(true) + 15;
    while (!is_file($input['barrier'] . '.go')) {
        if (microtime(true) > $until) { throw new RuntimeException('Fixture interleaving timed out'); }
        usleep(10000);
    }
}
$registry = new RankingRegistry($wpdb);
$visibility = new RankingVisibility($wpdb);
try {
    $result = match ($input['action']) {
        'install' => (static function () use ($wpdb): array { RankingSchema::installOrVerify($wpdb); return ['ready' => RankingSchema::ready($wpdb)]; })(),
        'origin' => $registry->prepareOrigin($input['origin']),
        'read_origin' => $registry->origin(),
        'dimension' => $registry->dimension($input['kind'], $input['value'] ?? ''),
        'own' => $visibility->own(),
        'submit' => $visibility->submit($input['alias'], $input['revision']),
        'consent' => $visibility->consent($input['family'], $input['enabled'], $input['revision']),
        'withdraw' => $visibility->withdraw($input['revision']),
        'decide' => $visibility->decide($input['member'], $input['revision'], $input['decision'], $input['reason']),
        'review' => $visibility->review($input['after'] ?? 0),
        'visible_fan' => $visibility->visibleFan($input['member']),
        'visible_creator' => $visibility->visibleCreator($input['creator']),
        'admit' => (static function () use ($input): mixed { CreatorStatusSchema::installOrVerify(); return CreatorProfileService::setStatus($input['creator'], $input['status']); })(),
        'editorial_submit' => EditorialService::submit($input['revision'], 'Local creator fixture', 'Fictitious recipe biography', '', 0),
        'editorial_decide' => EditorialService::decide($input['creator'], $input['revision'], $input['decision'], $input['reason']),
        'nested_install' => (static function () use ($wpdb): mixed { $wpdb->query('START TRANSACTION'); try { RankingSchema::installOrVerify($wpdb); } finally { $wpdb->query('ROLLBACK'); } return null; })(),
        default => throw new RuntimeException('Unknown fixture action'),
    };
    echo wp_json_encode($result instanceof WP_Error ? ['error' => $result->get_error_code()] : ['data' => $result]);
} catch (ModelViolation $error) { echo wp_json_encode(['error' => $error->getMessage()]); }
