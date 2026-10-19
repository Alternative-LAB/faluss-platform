<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\Link\Import;

use Faluss\Platform\Link\Import\LinkImportValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LinkImportValidatorTest extends TestCase
{
    public function testReviewIdentifiesExistingAndBatchDuplicatesAndDefaultsTitles(): void
    {
        $review = (new LinkImportValidator())->review([
            ['label' => 'Déjà là', 'url' => 'http://SHOP.fr/?utm_source=linktree'],
            ['label' => '', 'url' => 'video.fr/watch', 'order' => 4],
            ['label' => 'Doublon', 'url' => 'https://video.fr/watch?fbclid=abc'],
            ['label' => 'Erreur', 'url' => 'javascript:alert(1)'],
        ], ['https://shop.fr']);
        self::assertSame(['duplicate', 'ready', 'duplicate', 'invalid'], array_column($review, 'status'));
        self::assertSame([false, true, false, false], array_column($review, 'selected'));
        self::assertSame('video.fr', $review[1]['label']);
        self::assertSame(4, $review[1]['order']);
        self::assertNotSame('', $review[0]['message']);
    }

    public function testReviewConvertsEditedMarkupTitlesToPlainTextBeforeValidation(): void
    {
        $review = (new LinkImportValidator())->review([
            ['label' => "<b>Ma boutique</b>\n ici", 'url' => 'https://shop.fr'],
            ['label' => '<script>alert(1)</script>', 'url' => 'https://video.fr'],
            ['label' => '<img src=x onerror=alert(1)>', 'url' => 'https://portfolio.fr'],
            ['label' => 'Mon %20 lien', 'url' => 'https://percent.fr'],
            ['label' => '%20', 'url' => 'https://empty.fr'],
        ]);
        self::assertSame(['Ma boutique ici', 'alert(1)', 'portfolio.fr', 'Mon lien', 'empty.fr'], array_column($review, 'label'));
        self::assertSame(['ready', 'ready', 'ready', 'ready', 'ready'], array_column($review, 'status'));
    }

    public function testTitlesThatExistingStudioStorageWouldRewriteRemainVisibleAsErrors(): void
    {
        $rows = (new LinkImportValidator())->review([
            ['label' => 'A\\B', 'url' => 'https://shop.fr'],
            ['label' => 'A < 10', 'url' => 'https://video.fr'],
        ]);
        self::assertSame(['A\\B', 'A < 10'], array_column($rows, 'label'));
        self::assertSame(['invalid', 'invalid'], array_column($rows, 'status'));
        self::assertSame([false, false], array_column($rows, 'selected'));
        foreach ($rows as $row) {
            self::assertStringContainsString('Remplace', $row['message']);
        }
    }

    public function testUrlBracketsAreCanonicalBeforeReviewAndReimport(): void
    {
        $validator = new LinkImportValidator();
        $raw = 'https://shop.fr/[x]?filter[y]=1#[part]';
        $canonical = 'https://shop.fr/%5Bx%5D?filter%5By%5D=1#%5Bpart%5D';
        $row = $validator->review([['label' => 'Lien', 'url' => $raw]])[0];
        self::assertSame('ready', $row['status']);
        self::assertSame($canonical, $row['url']);
        self::assertSame($canonical, $validator->normalizeUrl($canonical));
        self::assertSame($validator->key($canonical), $validator->key($raw));
        self::assertSame('duplicate', $validator->review([['label' => 'Lien', 'url' => $raw]], [$canonical])[0]['status']);
    }

    public function testIpv6HostBracketsSurviveUrlCanonicalization(): void
    {
        $validator = new LinkImportValidator();
        $raw = 'http://[2606:4700:4700::1111]/[x]?filter[y]=1#[part]';
        $canonical = 'https://[2606:4700:4700::1111]/%5Bx%5D?filter%5By%5D=1#%5Bpart%5D';
        $row = $validator->review([['label' => 'Lien', 'url' => $raw]])[0];
        self::assertSame('ready', $row['status']);
        self::assertSame($canonical, $row['url']);
        self::assertSame('[2606:4700:4700::1111]', parse_url($row['url'], PHP_URL_HOST));
        self::assertSame($validator->key($canonical), $validator->key($raw));
    }

    public function testInvalidRowsDoNotHideLaterValidRowsAsDuplicates(): void
    {
        $review = (new LinkImportValidator())->review([
            ['label' => str_repeat('a', 81), 'url' => 'https://shop.fr'],
            ['label' => 'Valide', 'url' => 'https://shop.fr'],
            ['label' => str_repeat('é', 80), 'url' => 'https://video.fr'],
        ]);
        self::assertSame(['invalid', 'ready', 'ready'], array_column($review, 'status'));
    }

    /** @return iterable<string,array{string}> */
    public static function invalidUrls(): iterable
    {
        foreach (['javascript:alert(1)', 'data:text/html,hello', 'ftp://shop.fr', 'https://user:pass@shop.fr', 'https://localhost', 'https://sub.localhost', 'https://router.local', 'https://127.0.0.1', 'https://100.64.0.1', 'https://224.0.0.1', 'https://[ff02::1]', 'https://192.0.2.1', 'https://[2001:db8::1]', 'https://10.0.0.1', 'https://172.16.0.1', 'https://192.168.1.1', 'https://169.254.1.1', 'https://[::1]', 'https://[fc00::1]', 'https://[::ffff:127.0.0.1]', 'https://2130706433', 'https://bad_host.fr', 'https://-bad.fr', 'https://shop.fr:99999', 'https://shop.fr/%0d%0aheader', 'https://shop.fr/%broken', "https://shop.fr/with space", "https://shop.fr/\npath", 'https://shop.fr/\\path', 'https://shop.fr/' . str_repeat('a', 2048)] as $url) {
            yield $url => [$url];
        }
    }

    #[DataProvider('invalidUrls')]
    public function testUnsafeOrMalformedUrlsRemainVisibleButCannotBeImported(string $url): void
    {
        $row = (new LinkImportValidator())->review([['label' => 'Lien', 'url' => $url]])[0];
        self::assertSame('invalid', $row['status']);
        self::assertFalse($row['selected']);
        self::assertNotSame('', $row['message']);
    }

    /** @return iterable<string,array{string,string}> */
    public static function equivalentUrls(): iterable
    {
        yield 'scheme host and root' => ['http://SHOP.fr', 'https://shop.fr/'];
        yield 'HTTP default port' => ['http://shop.fr:80/', 'https://shop.fr'];
        yield 'default port' => ['https://shop.fr:443/', 'https://shop.fr'];
        yield 'tracking and percent encoding' => ['https://SHOP.fr/%7euser?utm_source=linktree&id=%41&gclid=abc#', 'https://shop.fr/~user?id=A'];
        yield 'encoded tracking parameter' => ['https://shop.fr?%75tm_medium=bio&fbclid=test', 'https://shop.fr/'];
    }

    #[DataProvider('equivalentUrls')]
    public function testConservativeEquivalenceRecognizesSafeVariants(string $left, string $right): void
    {
        $validator = new LinkImportValidator();
        self::assertSame($validator->key($left), $validator->key($right));
    }

    /** @return iterable<string,array{string,string}> */
    public static function distinctUrls(): iterable
    {
        yield 'www' => ['https://www.shop.fr', 'https://shop.fr'];
        yield 'path case' => ['https://shop.fr/Shop', 'https://shop.fr/shop'];
        yield 'path slash' => ['https://shop.fr/shop', 'https://shop.fr/shop/'];
        yield 'meaningful query' => ['https://shop.fr?id=1', 'https://shop.fr?id=2'];
        yield 'query parameter order' => ['https://shop.fr?a=1&b=2', 'https://shop.fr?b=2&a=1'];
        yield 'fragment' => ['https://shop.fr#part1', 'https://shop.fr#part2'];
        yield 'reserved encoding' => ['https://shop.fr/a%2fb', 'https://shop.fr/a/b'];
        yield 'non default port' => ['https://shop.fr:8443', 'https://shop.fr'];
    }

    #[DataProvider('distinctUrls')]
    public function testDistinctDestinationsAreNeverMerged(string $left, string $right): void
    {
        $validator = new LinkImportValidator();
        self::assertNotSame($validator->key($left), $validator->key($right));
    }
}
