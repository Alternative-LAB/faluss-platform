<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\Link\Import;

use Faluss\Platform\Link\Import\LinkImportParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LinkImportParserTest extends TestCase
{
    public function testCsvDetectsBomDelimiterAliasesAndStableOrder(): void
    {
        $rows = (new LinkImportParser())->parse("\xEF\xBB\xBFtitre;lien;ordre\nVidéo;http://video.fr/watch;2\nBoutique;shop.fr;1\nContact;https://contact.fr;2");
        self::assertSame(['Boutique', 'Vidéo', 'Contact'], array_column($rows, 'label'));
        self::assertSame('https://shop.fr', $rows[0]['url']);
        self::assertSame('https://video.fr/watch', $rows[1]['url']);
    }

    public function testQuotedCsvHandlesDelimiterEscapedQuotesAndMultiline(): void
    {
        $rows = (new LinkImportParser())->parse("title,url\n\"Mon, titre \"\"original\"\"\nseconde ligne\",https://shop.fr\nInvalide,javascript:alert(1)", 'csv');
        self::assertCount(2, $rows);
        self::assertSame("Mon, titre \"original\" seconde ligne", $rows[0]['label']);
        self::assertSame('javascript:alert(1)', $rows[1]['url']);
    }

    public function testHeaderlessCsvSupportsUrlFirstAndTitleFirst(): void
    {
        $parser = new LinkImportParser();
        self::assertSame('Portfolio', $parser->parse("portfolio.fr,Portfolio\nhttps://shop.fr,Boutique")[0]['label']);
        self::assertSame('https://portfolio.fr', $parser->parse("Portfolio\tportfolio.fr\nBoutique\thttps://shop.fr")[0]['url']);
    }

    public function testJsonFindsNestedLinktreeRowsWithoutDiscardingInvalidRecords(): void
    {
        $source = '{"data":{"account":{"links":[{"title":"Vidéo","url":"video.fr","position":2},{"name":"À corriger","href":"javascript:alert(1)","position":1},{"title":"URL manquante"}]}}}';
        $rows = (new LinkImportParser())->parse($source);
        self::assertCount(3, $rows);
        self::assertSame('À corriger', $rows[0]['label']);
        self::assertSame('javascript:alert(1)', $rows[0]['url']);
        self::assertSame('', $rows[2]['url']);
    }

    public function testJsonIgnoresUnrelatedImageAndMetadataUrls(): void
    {
        $source = '{"data":{"profile":{"avatar":{"url":"https://images.fr/avatar.png"},"tags":["musique","photo"],"links":[{"title":"Boutique","url":"https://shop.fr","image":{"url":"https://images.fr/shop.png"},"metadata":{"labels":["tag"]}}]}}}';
        $rows = (new LinkImportParser())->parse($source);
        self::assertCount(1, $rows);
        self::assertSame('https://shop.fr', $rows[0]['url']);
    }

    public function testAutoDetectsMarkdownWithoutMistakingItForJson(): void
    {
        $rows = (new LinkImportParser())->parse('[Boutique](https://shop.fr)');
        self::assertSame('Boutique', $rows[0]['label']);
    }

    public function testJsonSupportsSingleRecordAndStringArrays(): void
    {
        $parser = new LinkImportParser();
        self::assertSame('https://shop.fr', $parser->parse('{"label":"Boutique","url":"shop.fr"}')[0]['url']);
        self::assertCount(2, $parser->parse('["shop.fr","https://video.fr"]'));
    }

    public function testTextExtractsAdjacentTitlesMarkdownAndBalancedUrlPunctuation(): void
    {
        $rows = (new LinkImportParser())->parse("Mon portfolio\nhttps://portfolio.fr\n[Ma vidéo](https://video.fr/watch)\nBoutique : https://shop.fr.\nhttps://encyclopedia.fr/Example_(film)", 'text');
        self::assertSame(['Mon portfolio', 'Ma vidéo', 'Boutique', ''], array_column($rows, 'label'));
        self::assertSame('https://shop.fr', $rows[2]['url']);
        self::assertSame('https://encyclopedia.fr/Example_(film)', $rows[3]['url']);
    }

    public function testHtmlExtractsUnicodeEntitiesAndInvalidAnchorsWithoutExecutingMarkup(): void
    {
        $rows = (new LinkImportParser())->parse('<div><a href="https://shop.fr?a=1&amp;b=2"><strong>Été &amp; café</strong></a><a href="javascript:alert(1)">Erreur</a></div>');
        self::assertSame('Été & café', $rows[0]['label']);
        self::assertSame('https://shop.fr?a=1&b=2', $rows[0]['url']);
        self::assertSame('javascript:alert(1)', $rows[1]['url']);
    }

    /** @return iterable<string,array{string,string,string}> */
    public static function invalidSources(): iterable
    {
        yield 'oversize' => [str_repeat('x', LinkImportParser::MAX_BYTES + 1), 'text', 'too_large'];
        yield 'JSON broken' => ['[{', 'json', 'invalid_json'];
        yield 'JSON excessive depth' => [str_repeat('[', 40) . '[]' . str_repeat(']', 40), 'json', 'invalid_json'];
        yield 'CSV broken quote' => ["title,url\n\"unclosed,https://shop.fr", 'csv', 'invalid_csv'];
        yield 'unknown format' => ['https://shop.fr', 'xml', 'unsupported_format'];
        yield 'no links' => ['Aucun lien dans ce texte.', 'text', 'no_links'];
        yield 'empty CSV' => ['title,url', 'csv', 'no_links'];
        yield 'too many JSON rows' => [json_encode(array_fill(0, 501, ['url' => 'https://shop.fr']), JSON_THROW_ON_ERROR), 'json', 'too_many_rows'];
        yield 'too many CSV rows' => ["title,url\n" . str_repeat("Shop,https://shop.fr\n", 501), 'csv', 'too_many_rows'];
        yield 'too many text rows' => [str_repeat("https://shop.fr\n", 501), 'text', 'too_many_rows'];
    }

    #[DataProvider('invalidSources')]
    public function testInvalidSourcesFailWithStableCodes(string $source, string $format, string $code): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($code);
        (new LinkImportParser())->parse($source, $format);
    }
}
