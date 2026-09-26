<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\Fans\Images;

use Faluss\Platform\Fans\Images\ImageDisplayDerivative;
use Faluss\Platform\Fans\Publications\PublicationImageRest;
use PHPUnit\Framework\TestCase;

final class ImageDisplayDerivativeTest extends TestCase
{
    public function testFreshJpegIsBoundedOpaqueAndContainsNoAppendedSourcePayload(): void
    {
        if (!function_exists('imagecreatetruecolor')) { self::markTestSkipped('GD required; exercised in the local recipe.'); }
        $im = imagecreatetruecolor(2560, 100);
        imagealphablending($im, false); imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        ob_start(); imagepng($im); $png = ob_get_clean();
        $jpeg = ImageDisplayDerivative::render($png . 'PRIVATE_SOURCE_MARKER');
        self::assertIsString($jpeg);
        self::assertStringStartsWith("\xff\xd8", $jpeg);
        self::assertNotSame($png, $jpeg);
        self::assertStringNotContainsString('PRIVATE_SOURCE_MARKER', $jpeg);
        $size = getimagesizefromstring($jpeg);
        self::assertSame([1280, 50, 'image/jpeg'], [$size[0], $size[1], $size['mime']]);
        $decoded = imagecreatefromstring($jpeg);
        self::assertSame(0xffffff, imagecolorat($decoded, 0, 0) & 0xffffff);
        self::assertLessThanOrEqual(ImageDisplayDerivative::MAX_BYTES, strlen($jpeg));
    }

    public function testInvalidSourcesNeverFallBackToOriginalAndErrorsAreNotSharedCached(): void
    {
        foreach (['', '<svg/>', "\xff\xd8\xff", "\x89PNG\r\n\x1a\n", str_repeat('x', 8388609)] as $source) {
            self::assertNull(ImageDisplayDerivative::render($source));
        }
        $headers = PublicationImageRest::headers();
        self::assertStringContainsString('private, no-store', $headers['Cache-Control']);
        self::assertSame('no-store', $headers['CDN-Cache-Control']);
        self::assertSame('no-store', $headers['Surrogate-Control']);
        self::assertSame('same-origin', $headers['Cross-Origin-Resource-Policy']);
    }
}
