<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Sso;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WordPressStubs.php';

final class FansSsoReturnTest extends TestCase
{
    protected function setUp(): void { fans_sso_reset(); }

    public function testOnlyExplicitFansPathsSurviveIncludingSubdirectories(): void
    {
        foreach (['fan/explorer', 'fan/hof', 'fan/classement-fans', 'creator/creer', 'creator/mon-profil', 'creator/progression', 'creator/boutique', 'creators/123e4567-e89b-42d3-a456-426614174000'] as $view) {
            self::assertSame('/faluss-fans/' . $view, FansSsoReturn::path('/faluss-fans/' . $view . '/'));
        }
        foreach ([null, [], 42, 'https://evil.example/faluss-fans/fan/explorer', '//evil.example', '/wp-admin/', '/wp-json/', '/faluss-fans/sso/callback', '/faluss-fans/fan/nope', '/faluss-fans/fan/explorer?code=secret', '/faluss-fans/fan/explorer#fragment', '/faluss-fans/fan/../creator/creer', '/faluss-fans/fan/%65xplorer', '/faluss-fans/fan/explorer\\evil', "/faluss-fans/fan/explorer\r\nLocation: evil", str_repeat('x', 801)] as $candidate) {
            self::assertNull(FansSsoReturn::path($candidate));
        }
        $GLOBALS['fans_sso_home'] = 'https://fans.example.test/community';
        self::assertSame('/community/faluss-fans/fan/explorer', FansSsoReturn::path('/community/faluss-fans/fan/explorer'));
        self::assertNull(FansSsoReturn::path('/faluss-fans/fan/explorer'));
    }

    public function testReturnCannotBeTamperedOrMovedToAnotherLoginState(): void
    {
        $state = str_repeat('s', 43);
        $path = '/faluss-fans/creator/mon-profil';
        $cookie = FansSsoReturn::seal($path, $state);
        self::assertIsString($cookie);
        self::assertSame($path, FansSsoReturn::open($cookie, $state));
        self::assertNull(FansSsoReturn::open($cookie, str_repeat('t', 43)));
        self::assertNull(FansSsoReturn::open('x' . $cookie, $state));
        self::assertNull(FansSsoReturn::open(substr($cookie, 0, -1), $state));
        self::assertNull(FansSsoReturn::open([], $state));
        self::assertNull(FansSsoReturn::seal('https://evil.example', $state));
        self::assertNull(FansSsoReturn::seal($path, 'invalid'));
    }
}
