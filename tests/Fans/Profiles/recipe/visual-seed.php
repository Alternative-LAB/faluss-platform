<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
use Faluss\Platform\Fans\Images\ImageStorage;
use Faluss\Platform\Fans\Images\ImageService;
use Faluss\Platform\Fans\Profiles\EditorialService;
const VISUAL_IMAGE = '33333333-3333-4333-8333-333333333333';
$canvas = imagecreatetruecolor(500, 500);
imagefill($canvas, 0, 0, imagecolorallocate($canvas, 13, 48, 40));
imagefilledellipse($canvas, 250, 220, 260, 260, imagecolorallocate($canvas, 65, 194, 149));
imagestring($canvas, 5, 154, 390, 'IMAGE DE TEST ABSTRAITE', imagecolorallocate($canvas, 248, 244, 235));
ob_start(); imagepng($canvas); $png = ImageStorage::normalize(ob_get_clean());
if (!is_string($png) || !ImageStorage::put(ImageStorage::root(), VISUAL_IMAGE, $png)) { throw new RuntimeException('Private raster fixture failed'); }
$wpdb->query($wpdb->prepare('INSERT INTO test_faluss_fans_images(image_id,creator_id,revision,file_hash,bytes,state,created_at,updated_at) VALUES(%s,%s,1,%s,%d,%s,UTC_TIMESTAMP(),UTC_TIMESTAMP())',
    VISUAL_IMAGE, '11111111-1111-4111-8111-111111111111', hash('sha256', $png), strlen($png), 'pending'));
$fixtureUser = 1;
if (ImageService::decide(VISUAL_IMAGE, 1, 'approve', 'allowed_image') instanceof WP_Error) { throw new RuntimeException('Fixture image approval failed'); }
$fixtureUser = 17;
$own = EditorialService::own();
if (in_array($own['state'], ['pending', 'approved'], true)) { EditorialService::decide($own['creator_id'], (int) $own['revision'], 'withdraw', ''); }
echo "Synthetic owner and abstract private image ready. No public editorial identity seeded.\n";
