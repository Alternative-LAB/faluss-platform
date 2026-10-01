<?php
declare(strict_types=1);

use Faluss\Platform\Fans\Sso\FansAccountDirectory;
use Faluss\Platform\Fans\Sso\FansSsoSchema;
use Faluss\Platform\Fans\Profiles\CreatorProfileService;
use Faluss\Platform\Fans\Profiles\CreatorStatusReview;

if (!defined('WP_CLI') || !WP_CLI || DB_NAME !== 'admission_recipe') { throw new RuntimeException('Disposable fixture only'); }
$fixture = json_decode(file_get_contents($args[0]), true);
$checks = [];
$check = static function (string $name, bool $ok) use (&$checks): void { if (!$ok) { throw new RuntimeException($name); } $checks[] = $name; };
$member = $fixture['sessions']['member']['id']; $admin = $fixture['sessions']['admin']['id'];
foreach ([0, $member, $fixture['sessions']['editor']['id']] as $id) {
    wp_set_current_user($id);
    $check('private account projection denied role '.$id, FansAccountDirectory::account($member) === null && is_wp_error(FansAccountDirectory::search()));
    $check('private profile ownership denied role '.$id, CreatorProfileService::administration($fixture['profiles']['member']) === null);
}
wp_set_current_user($admin);
$account = FansAccountDirectory::account($member);
$check('real local email and valid link', $account['email'] === 'member@example.invalid' && $account['linked'] && $account['faluss_id'] !== null);
$check('no technical login promoted to handle', $account['handle'] === null && !isset($account['user_login']));
$check('email search exact real result', count(FansAccountDirectory::search('member@example.invalid')['items']) === 1);
$check('Faluss ID search real result', FansAccountDirectory::search($account['faluss_id'])['items'][0]['user_id'] === $member);
$check('wildcard escaping', FansAccountDirectory::search('%')['items'] === []);
$check('search bounds', is_wp_error(FansAccountDirectory::search(str_repeat('x',192))) && is_wp_error(FansAccountDirectory::search('',-1)));
$check('pagination cursor strictly advances', array_filter(FansAccountDirectory::search('', $member)['items'], static fn($row) => $row['user_id'] <= $member) === []);
$check('unlinked account clearly marked', FansAccountDirectory::account($fixture['sessions']['unlinked']['id'])['linked'] === false);
global $wpdb;
$links = FansSsoSchema::tables()['links']; $creator = $fixture['profiles']['waiting']; $owner = $fixture['sessions']['waiting']['id'];
$link = $wpdb->get_row($wpdb->prepare('SELECT * FROM `'.$links.'` WHERE wp_user_id=%d', $owner), ARRAY_A);
$before = CreatorStatusReview::detail($creator);
try {
    $wpdb->delete($links, ['wp_user_id'=>$owner]);
    $blocked = CreatorProfileService::setStatus($creator,'active',$before['status_revision']);
    $check('missing link blocks real activation', is_wp_error($blocked) && $blocked->get_error_code() === 'creator_link_missing');
    $check('blocked activation leaves journal and status unchanged', CreatorStatusReview::detail($creator) === $before);
    $check('missing link context honest', FansAccountDirectory::account($owner)['faluss_id'] === null);
    $check('suspension remains possible', !is_wp_error(CreatorProfileService::setStatus($creator,'suspended',$before['status_revision'])));
} finally { $wpdb->insert($links, $link); }
$check('restored link valid', FansAccountDirectory::account($owner)['linked']);
echo wp_json_encode(['passed'=>count($checks),'checks'=>$checks]);
