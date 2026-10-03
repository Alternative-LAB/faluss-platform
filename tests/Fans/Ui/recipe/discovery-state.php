<?php
declare(strict_types=1);

use Faluss\Platform\Fans\Profiles\{CreatorProfileSchema, EditorialSchema};
use Faluss\Platform\Fans\Images\ImageSchema;

if (!defined('WP_CLI') || !WP_CLI || DB_NAME !== 'admission_recipe' || WP_HOME !== 'https://fans.example.test') { throw new RuntimeException('Disposable fixture only'); }
$fixture = json_decode(file_get_contents($args[0]), true);
$state = $args[1];
if (!in_array($state, ['one', 'many', 'no-media', 'empty'], true)) { throw new RuntimeException('Unknown state'); }
global $wpdb;
foreach ($fixture['discovery'] as $index => $profile) {
    $wpdb->update(CreatorProfileSchema::table(), ['status' => $state === 'empty' ? 'pending' : ($index === 0 || $state === 'many' ? 'active' : 'pending')], ['creator_id' => $profile['id']]);
}
// Reproduce the same approved record with/without available media, without changing any flag.
if (!isset($fixture['published'])) {
    $fixture['published'] = $wpdb->get_row($wpdb->prepare('SELECT * FROM `' . EditorialSchema::publishedTable() . '` WHERE creator_id=%s', $fixture['discovery'][0]['id']), ARRAY_A);
    file_put_contents($args[0], wp_json_encode($fixture));
}
$wpdb->update(EditorialSchema::publishedTable(), ['portrait_id' => $state === 'no-media' ? '' : $fixture['published']['portrait_id'], 'portrait_revision' => $state === 'no-media' ? 0 : $fixture['published']['portrait_revision']], ['creator_id' => $fixture['discovery'][0]['id']]);
if (isset($fixture['image'])) { $wpdb->update(ImageSchema::table(), ['state' => $state === 'no-media' ? 'withdrawn' : 'approved'], ['image_id' => $fixture['image']]); }
