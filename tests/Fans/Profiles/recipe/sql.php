<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use Faluss\Platform\Fans\Profiles\CreatorProfileSchema;
use Faluss\Platform\Fans\Profiles\CreatorProfileService;
use Faluss\Platform\Fans\Profiles\EditorialSchema;
use Faluss\Platform\Fans\Profiles\EditorialService;
use Faluss\Platform\Fans\Profiles\EditorialRest;
use Faluss\Platform\Fans\Images\ImageSchema;
use Faluss\Platform\Fans\Images\ImageStorage;
use Faluss\Platform\Fans\Images\ImageService;
use Faluss\Platform\Fans\Sso\FansSsoSchema;

function check(string $label, bool $ok): void { if (!$ok) { throw new RuntimeException($label . ' SQL=' . $GLOBALS['wpdb']->last_error); } echo "PASS $label\n"; }
const OWNER = '11111111-1111-4111-8111-111111111111';
const IMAGE = '22222222-2222-4222-8222-222222222222';
if (($argv[1] ?? '') === 'race') {
    $result = EditorialService::submit((int) $argv[2], 'Modification concurrente', '', '', 0);
    echo $result instanceof WP_Error ? $result->get_error_data()['status'] : 200; exit;
}
if (($argv[1] ?? '') === 'setup') {
    $wpdb->query('CREATE TABLE fixture_options(name varchar(191) PRIMARY KEY,value text NOT NULL) ENGINE=InnoDB');
    check('real SSO schemas', FansSsoSchema::installOrVerify());
    check('real profile schema', CreatorProfileSchema::installOrVerify());
    check('real admission journal', \Faluss\Platform\Fans\Profiles\CreatorStatusSchema::installOrVerify());
    check('real image schemas', ImageSchema::installOrVerify());
    if (getenv('FANS_PUBLICATION_FIXTURE') === '1') { check('real text publication schemas', \Faluss\Platform\Fans\Publications\TextPublicationSchema::installOrVerify()); }
    check('additive editorial schema', EditorialSchema::installOrVerify());
    check('idempotent schema verification', EditorialSchema::installOrVerify());
    $wpdb->query("INSERT INTO test_faluss_fans_identity_links (wp_user_id,faluss_id,created_at,last_proved_at) VALUES (17,'" . OWNER . "',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $wpdb->query("INSERT INTO test_faluss_fans_identity_links (wp_user_id,faluss_id,created_at,last_proved_at) VALUES (18,'44444444-4444-4444-8444-444444444444',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $wpdb->query("INSERT INTO test_faluss_fans_creator_profiles (creator_id,wp_user_id,category,status,created_at,updated_at) VALUES ('" . OWNER . "',17,'arts','active',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    exit;
}
$r = new WP_REST_Request('POST', '/faluss-fans/v1/creators/me/editorial');
$r->set_header('X-WP-Nonce', 'fixture-wp_rest');
$r->set_body(json_encode(['revision' => 0, 'public_name' => 'Atelier test SQL', 'bio' => "Bio SQL\nDeuxième ligne.", 'portrait_id' => '', 'portrait_revision' => 0]));
$response = rest_do_request($r);
check('owner REST creates pending revision', $response->get_status() === 200 && $response->get_data()['state'] === 'pending');
check('private response no-store', str_contains($response->get_headers()['Cache-Control'], 'no-store'));
check('pending never public', EditorialService::publicById(OWNER) === null);
check('Explorer hides pending fields', rest_do_request(new WP_REST_Request('GET', '/faluss-fans/v1/creators'))->get_data()[0]['editorial'] === null);
$r->json['state'] = 'approved';
check('forged approval field rejected', rest_do_request($r)->get_status() === 400);
unset($r->json['state']); $r->query['public_name'] = 'Override';
check('mixed query mutation rejected', rest_do_request($r)->get_status() === 400);
$r->query = [];
$r->headers = [];
check('missing nonce denied', rest_do_request($r)->get_status() === 403);
$fixtureUser = 1;
check('admin approves', is_array(EditorialService::decide(OWNER, 1, 'approve', 'allowed_editorial')));
$fixtureUser = 0;
check('guest reads approved projection only', array_keys(EditorialService::publicById(OWNER)) === ['public_name','bio','revision','portrait']);
check('guest cannot submit', EditorialService::submit(2, 'x', '', '', 0) instanceof WP_Error);
$fixtureUser = 18;
check('different user cannot withdraw', EditorialService::decide(OWNER, 2, 'withdraw', '') instanceof WP_Error);
$fixtureUser = 17;
check('SQL unicode name length', EditorialService::submit(2, str_repeat('é', 81), '', '', 0) instanceof WP_Error);
$wpdb->query("CREATE TRIGGER fail_editorial_audit BEFORE INSERT ON test_faluss_fans_editorial_decisions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected fixture failure'");
check('real SQL audit failure is reported', EditorialService::submit(2, 'Must roll back', '', '', 0) instanceof WP_Error);
$wpdb->query('DROP TRIGGER fail_editorial_audit');
check('real InnoDB rollback preserves approved revision', EditorialService::publicById(OWNER)['revision'] === 2 && EditorialService::publicById(OWNER)['public_name'] === 'Atelier test SQL');
check('edit preserves approved fields only', is_array(EditorialService::submit(2, 'Atelier édité', 'Bio privée', '', 0)) && EditorialService::publicById(OWNER)['public_name'] === 'Atelier test SQL');
check('stale decision rejected', EditorialService::decide(OWNER, 2, 'withdraw', '')->get_error_data()['status'] === 409);
$fixtureUser = 1;
check('rejection purges', is_array(EditorialService::decide(OWNER, 3, 'reject', 'needs_revision')));
$row = $wpdb->get_row("SELECT * FROM test_faluss_fans_editorial WHERE creator_id='" . OWNER . "'");
check('no rejected content retained', $row['public_name'] === '' && $row['bio'] === '' && $row['portrait_id'] === '');
check('rejected draft preserves previous approval', EditorialService::publicById(OWNER)['revision'] === 2);
check('audit contains no content', !str_contains(json_encode($wpdb->get_results('SELECT * FROM test_faluss_fans_editorial_decisions')), 'Atelier'));
// Synthetic raster with no person: exercise real normalization, private storage and JPEG derivation.
$canvas = imagecreatetruecolor(96, 96); imagefill($canvas, 0, 0, imagecolorallocate($canvas, 24, 120, 96));
ob_start(); imagepng($canvas); $png = ImageStorage::normalize(ob_get_clean());
check('private normalized raster', is_string($png) && ImageStorage::root() !== null && ImageStorage::put(ImageStorage::root(), IMAGE, $png));
$wpdb->query($wpdb->prepare('INSERT INTO test_faluss_fans_images(image_id,creator_id,revision,file_hash,bytes,state,created_at,updated_at) VALUES(%s,%s,1,%s,%d,%s,UTC_TIMESTAMP(),UTC_TIMESTAMP())', IMAGE, OWNER, hash('sha256', $png), strlen($png), 'pending'));
$fixtureUser = 17;
check('owner previews private pending JPEG', is_string(ImageService::ownerPreview(IMAGE, 1)));
$fixtureUser = 18;
check('other linked member cannot preview image', ImageService::ownerPreview(IMAGE, 1) instanceof WP_Error);
$fixtureUser = 0;
check('guest cannot preview image', ImageService::ownerPreview(IMAGE, 1) instanceof WP_Error);
$fixtureUser = 17;
check('wrong image revision cannot preview', ImageService::ownerPreview(IMAGE, 2) instanceof WP_Error);
check('pending portrait cannot attach', EditorialService::submit(4, 'Atelier SQL', '', IMAGE, 1)->get_error_data()['status'] === 409);
$fixtureUser = 1;
check('real image moderation', is_array(ImageService::decide(IMAGE, 1, 'approve', 'allowed_image')));
$fixtureUser = 17;
check('approved portrait candidates are owned', ImageService::portraitCandidates()['items'][0]['image_id'] === IMAGE);
$wpdb->query("UPDATE test_faluss_fans_images SET creator_id='44444444-4444-4444-8444-444444444444' WHERE image_id='" . IMAGE . "'");
check('foreign approved portrait cannot attach', EditorialService::submit(4, 'Atelier SQL', '', IMAGE, 2)->get_error_data()['status'] === 409);
$wpdb->query("UPDATE test_faluss_fans_images SET creator_id='" . OWNER . "' WHERE image_id='" . IMAGE . "'");
check('stale image cannot attach', EditorialService::submit(4, 'Atelier SQL', '', IMAGE, 1)->get_error_data()['status'] === 409);
check('approved image attaches privately', is_array(EditorialService::submit(4, 'Atelier SQL', 'Portrait de test abstrait.', IMAGE, 2)));
check('pending editorial has no portrait', EditorialService::portrait(OWNER, 5) instanceof WP_Error);
$fixtureUser = 1;
check('approve contextual portrait', is_array(EditorialService::decide(OWNER, 5, 'approve', 'allowed_editorial')));
$jpeg = EditorialService::portrait(OWNER, 6);
check('fresh JPEG never source PNG', is_string($jpeg) && str_starts_with($jpeg, "\xff\xd8") && $jpeg !== $png);
// Migration seeds only a currently approved v1 version, without touching its revision.
$wpdb->query('DROP TABLE `' . EditorialSchema::publishedTable() . '`'); update_option(EditorialSchema::OPTION, '1');
check('upgrade approved v1 without rewriting current row', EditorialSchema::installOrVerify() && EditorialService::publicById(OWNER)['revision'] === 6);
check('upgrade idempotent', EditorialSchema::installOrVerify());
$fixtureUser = 17;
check('new draft preserves approved portrait URL', is_array(EditorialService::submit(6, 'Private new proposal', '', '', 0)) && is_string(EditorialService::portrait(OWNER, 6)));
$fixtureUser = 1;
check('reject draft preserves approved portrait URL', is_array(EditorialService::decide(OWNER, 7, 'reject', 'needs_revision')) && is_string(EditorialService::portrait(OWNER, 6)));
CreatorProfileService::setStatus(OWNER, 'suspended');
check('suspension removes fields and portrait', EditorialService::publicById(OWNER) === null && EditorialService::portrait(OWNER, 6) instanceof WP_Error);
CreatorProfileService::setStatus(OWNER, 'active');
$fixtureUser = 17;
check('image withdrawal revokes portrait', is_array(ImageService::decide(IMAGE, 2, 'withdraw', 'creator_withdrawal')) && EditorialService::portrait(OWNER, 6) instanceof WP_Error);
check('withdrawn image has no owner preview', ImageService::ownerPreview(IMAGE, 3) instanceof WP_Error);
check('withdrawn image removed from picker', ImageService::portraitCandidates()['items'] === []);
check('name survives absent portrait honestly', EditorialService::publicById(OWNER)['portrait'] === false);
check('editorial withdrawal after draft rejection purges', is_array(EditorialService::decide(OWNER, 8, 'withdraw', '')) && EditorialService::publicById(OWNER) === null);
check('SQL audit exact revisions', $wpdb->get_var('SELECT COUNT(*) FROM test_faluss_fans_editorial_decisions') == 9);
// Leave revision 9 for the runner's concurrent submissions (exactly one must win).
