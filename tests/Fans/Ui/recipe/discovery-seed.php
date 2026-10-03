<?php
declare(strict_types=1);

// Synthetic local data only; invoke inside admission-wordpress.py's disposable database.
use Faluss\Platform\Fans\Profiles\{CreatorProfileService, CreatorProfileSchema, CreatorStatusSchema, EditorialService};
use Faluss\Platform\Fans\Publications\TextPublicationService;
use Faluss\Platform\Fans\Sso\FansSsoSchema;

if (!defined('WP_CLI') || !WP_CLI || DB_NAME !== 'admission_recipe') { throw new RuntimeException('Disposable fixture only'); }
$fixture = json_decode(file_get_contents($args[0]), true);
$check = static function ($value) { if (is_wp_error($value)) { throw new RuntimeException($value->get_error_code()); } return $value; };
global $wpdb;
if (!CreatorStatusSchema::installOrVerify()) { throw new RuntimeException('Status schema'); }
$fixture['discovery'] = [];
for ($i = 0; $i < 14; ++$i) {
    $category = $i < 12 ? 'arts' : ($i === 12 ? 'music' : 'games');
    $uid = $i === 0 ? $fixture['sessions']['member']['id'] : $check(wp_insert_user(['user_login' => 'discovery_' . $i, 'user_pass' => wp_generate_password(40), 'user_email' => 'discovery_' . $i . '@example.invalid', 'role' => 'subscriber']));
    if ($i > 0) { $wpdb->insert(FansSsoSchema::tables()['links'], ['wp_user_id' => $uid, 'faluss_id' => wp_generate_uuid4(), 'created_at' => gmdate('Y-m-d H:i:s'), 'last_proved_at' => gmdate('Y-m-d H:i:s')]); }
    wp_set_current_user($uid);
    $profile = $check(CreatorProfileService::create($category));
    wp_set_current_user($fixture['sessions']['admin']['id']);
    $check(CreatorProfileService::setStatus($profile['creator_id'], 'active', 0));
    wp_set_current_user($uid);
    $name = ['Atelier de recette', 'Studio de recette', 'Carnets de recette'][$i % 3] . ' ' . sprintf('%02d', $i + 1);
    $bio = 'Profil fictif de recette locale. Des formes, des couleurs et des idées à partager. Ces données servent uniquement à vérifier la présentation de Fans.';
    $draft = $check(EditorialService::submit(0, $name, $bio, '', 0));
    wp_set_current_user($fixture['sessions']['admin']['id']);
    $check(EditorialService::decide($profile['creator_id'], $draft['revision'], 'approve', 'allowed_editorial'));
    // Deliberately distinct creation dates, unrelated to UUID sorting.
    $wpdb->update(CreatorProfileSchema::table(), ['created_at' => sprintf('2026-09-%02d 12:00:00', $i + 1)], ['creator_id' => $profile['creator_id']]);
    $fixture['discovery'][] = ['id' => $profile['creator_id'], 'user' => $uid, 'name' => $name, 'category' => $category];
}
wp_set_current_user($fixture['sessions']['member']['id']);
foreach (['Une étude de lumière et de matières. Publication fictive pour la recette locale.', 'Les carnets de l’atelier : quelques formes en mouvement, une palette qui évolue.', 'Prendre le temps de regarder. Ce texte de recette permet de vérifier la lisibilité sur mobile.'] as $body) {
    $pub = $check(TextPublicationService::create($body, TextPublicationService::CATEGORY, wp_generate_uuid4()));
    wp_set_current_user($fixture['sessions']['admin']['id']);
    $check(TextPublicationService::change($pub['publication_id'], (int) $pub['revision'], 'approve', null, 'allowed_text'));
    $fixture['publications'][] = $pub['publication_id'];
    wp_set_current_user($fixture['sessions']['member']['id']);
}
file_put_contents($args[0], wp_json_encode($fixture));
// Geometric fixture, not a person's portrait or production artwork.
$im = imagecreatetruecolor(960, 720);
imagefill($im, 0, 0, imagecolorallocate($im, 22, 53, 45));
imagefilledellipse($im, 690, 250, 530, 530, imagecolorallocate($im, 185, 150, 104));
imagefilledrectangle($im, 50, 430, 690, 680, imagecolorallocate($im, 70, 140, 109));
imagestring($im, 5, 35, 35, 'RECETTE LOCALE - IMAGE SYNTHETIQUE', imagecolorallocate($im, 255, 255, 255));
imagejpeg($im, dirname($args[0]) . '/fixture.jpg', 90);
