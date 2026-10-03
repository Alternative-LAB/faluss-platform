<?php
declare(strict_types=1);

use Faluss\Platform\Fans\Profiles\{CreatorProfileService, CreatorProfileSchema, EditorialService, EditorialSchema};
use Faluss\Platform\Fans\Publications\{TextPublicationService, TextPublicationSchema};
use Faluss\Platform\Fans\Images\ImageSchema;

if (!defined('WP_CLI') || !WP_CLI || DB_NAME !== 'admission_recipe' || WP_HOME !== 'https://fans.example.test') { throw new RuntimeException('Disposable fixture only'); }
$s = json_decode(file_get_contents($args[0]), true); global $wpdb;
$checks = [];
$assert = static function (bool $ok, string $label) use (&$checks): void { if (!$ok) { throw new RuntimeException($label); } $checks[] = $label; };
$expected = $s['discovery'];
usort($expected, static fn ($a, $b) => strcmp($b['created_at'], $a['created_at']) ?: strcmp($b['id'], $a['id']));
$newest = EditorialService::discovery(null, 3);
$assert(!is_wp_error($newest) && array_column($newest['items'], 'creator_id') === array_column(array_slice($expected, 0, 3), 'id'), 'Newest three, creator request date DESC and UUID DESC ties');
$assert($newest['items'][0]['creator_id'] === $s['profiles']['member'] && get_userdata($s['sessions']['member']['id'])->user_registered === '2020-01-01 00:00:00', 'Old WP account does not demote new creator arrival');
$legacy = CreatorProfileService::publicList('arts');
$assert(count($legacy) === 20 && !in_array($expected[1]['id'], array_column($legacy, 'creator_id'), true), 'Newest creator beyond legacy UUID LIMIT 20 selected; legacy cap unchanged');
foreach ([null, 'arts', 'music', 'games', 'learning', 'lifestyle'] as $category) {
    $ids = []; $cursor = null; $pages = 0;
    do {
        $page = EditorialService::discovery($category, 10, $cursor);
        $assert(!is_wp_error($page), 'Readable category page ' . ($category ?? 'all') . ':' . ++$pages);
        array_push($ids, ...array_column($page['items'], 'creator_id')); $cursor = $page['next_cursor'];
        if ($pages > 6) { throw new RuntimeException('Unbounded pagination'); }
    } while ($cursor !== null);
    $wanted = array_column(array_filter($expected, static fn ($p) => $category === null || $p['category'] === $category), 'id');
    $assert($ids === array_values($wanted), 'Exact complete pagination without duplicates/exclusions: ' . ($category ?? 'all'));
}
foreach ([['arts', 21, null], ['wrong', 10, null], ['music', 10, EditorialService::discovery('arts', 10)['next_cursor']], ['arts', 10, 'bad']] as $input) {
    $result = EditorialService::discovery(...$input); $assert(is_wp_error($result) && $result->get_error_data()['status'] === 400, 'Invalid/category-crossing discovery page rejected');
}
$creator = $s['profiles']['member']; $pub = $s['publications'][0];
$read = static fn () => TextPublicationService::listing('public', 6, null, $creator, true);
$page = $read();
$assert(count($page['items']) === 3 && count(array_filter($page['items'], static fn ($p) => $p['has_public_image'])) === 1, 'Only one associated approved image; two text-only publications false');
$assert(array_keys($page['items'][0]) === ['publication_id','creator_id','revision','body','updated_at','has_public_image'], 'Public image indication exposes boolean only');
$plain = TextPublicationService::listing('public', 6, null, $creator);
$assert(count($plain['items'][0]) === 5 && !isset($plain['items'][0]['has_public_image']), 'Default publication projection unchanged');
$image = $wpdb->get_row($wpdb->prepare('SELECT * FROM `' . ImageSchema::table() . '` WHERE image_id=%s', $s['image']), ARRAY_A);
try {
    foreach ([['state'=>'pending'], ['state'=>'rejected'], ['state'=>'withdrawn'], ['revision'=>(int)$image['revision']+1], ['creator_id'=>$s['discovery'][1]['id']], ['file_hash'=>str_repeat('0',64)]] as $change) {
        $wpdb->update(ImageSchema::table(), $change, ['image_id'=>$s['image']]);
        $page = $read(); $assert(count(array_filter($page['items'], static fn ($p) => $p['has_public_image'])) === 0, 'Image denied after ' . implode(',',array_keys($change)) . ':' . implode(',',array_values($change)));
        $wpdb->update(ImageSchema::table(), $image, ['image_id'=>$s['image']]);
    }
} finally { $wpdb->update(ImageSchema::table(), $image, ['image_id'=>$s['image']]); }
$reference = $wpdb->get_row($wpdb->prepare('SELECT * FROM `' . TextPublicationSchema::table('images') . '` WHERE publication_id=%s ORDER BY revision DESC LIMIT 1', $pub), ARRAY_A);
try {
    $wpdb->update(TextPublicationSchema::table('images'), ['image_id'=>'', 'image_revision'=>0], ['publication_id'=>$pub,'revision'=>$reference['revision']]);
    $assert(!array_filter($read()['items'], static fn ($p) => $p['has_public_image']), 'Detached association false without image URL probe');
} finally { $wpdb->update(TextPublicationSchema::table('images'), $reference, ['publication_id'=>$pub,'revision'=>$reference['revision']]); }
// Revocations and suspensions are checked fresh on every page; restore the disposable snapshot.
$editorial = $wpdb->get_row($wpdb->prepare('SELECT * FROM `' . EditorialSchema::publishedTable() . '` WHERE creator_id=%s', $creator), ARRAY_A);
try {
    $wpdb->update(EditorialSchema::publishedTable(), ['state'=>'withdrawn'], ['creator_id'=>$creator]);
    $assert(!in_array($creator,array_column(EditorialService::discovery(null,20)['items'],'creator_id'),true), 'Withdrawn approved presentation excluded');
    $wpdb->update(EditorialSchema::publishedTable(), ['state'=>'approved','public_name'=>''], ['creator_id'=>$creator]);
    $assert(!in_array($creator,array_column(EditorialService::discovery(null,20)['items'],'creator_id'),true), 'Empty approved presentation excluded');
} finally { $wpdb->update(EditorialSchema::publishedTable(), $editorial, ['creator_id'=>$creator]); }
file_put_contents($args[1], wp_json_encode(['checks'=>$checks,'eligible'=>37,'arts'=>35,'excluded'=>5], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
echo count($checks) . " real SQL/owner-contract checks passed.\n";
