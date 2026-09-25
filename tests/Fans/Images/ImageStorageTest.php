<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\Fans\Images;

use Faluss\Platform\Fans\Images\ImageStorage;
use Faluss\Platform\Fans\Images\ImageService;
use PHPUnit\Framework\TestCase;

final class ImageStorageTest extends TestCase
{
    public function testPathsAndPublicAccessAlwaysFailClosed(): void
    {
        foreach (['../image', '/tmp/file', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa/../../x', '', str_repeat('a', 36)] as $id) {
            self::assertFalse(ImageStorage::validId($id));
        }
        self::assertNull(ImageStorage::root());
        self::assertFalse(ImageService::publicAllowed());
    }
    public function testMalformedUnsupportedAndOversizedBytes(): void
    {
        foreach (['', '<svg xmlns="http://www.w3.org/2000/svg"></svg>', 'GIF89a', "\x89PNG\r\n\x1a\n", str_repeat('x', ImageStorage::INPUT_LIMIT + 1)] as $data) {
            self::assertNull(ImageStorage::normalize($data));
        }
    }
    public function testValidPngIsReencodedWithoutAppendedPayload(): void
    {
        if (!function_exists('imagecreatetruecolor')) { self::markTestSkipped('GD decoding is exercised by the real local recipe.'); }
        $im = imagecreatetruecolor(2, 2); ob_start(); imagepng($im); $png = ob_get_clean();
        $safe = ImageStorage::normalize($png . '<?php echo "bad"; ?>');
        self::assertIsString($safe);
        self::assertStringStartsWith("\x89PNG\r\n\x1a\n", $safe);
        self::assertStringNotContainsString('<?php', $safe);
    }
}
