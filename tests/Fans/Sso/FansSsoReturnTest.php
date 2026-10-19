<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Sso;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WordPressStubs.php';

final class FansSsoReturnTest extends TestCase
{
    protected function setUp(): void { fans_sso_reset(); }

    public function testSupportReturnKeepsOnlyTheExactTargetWithoutGrantingAnAttribution(): void
    {
        $id='123e4567-e89b-42d3-a456-426614174000';$state=str_repeat('s',43);
        foreach (['fan','creator'] as $role) {
            $path='/app/'.$role.'/hof?support='.$id;
            self::assertSame($path,FansSsoReturn::path($path));
            self::assertSame($path,FansSsoReturn::open(FansSsoReturn::seal($path,$state),$state));
            foreach ([$path.'&quantity=10',$path.'&sessions[]='.$id,$path.'&nonce=x',$path.'&support='.$id,$path.'#fragment',
                str_replace('?support=','?support[]=',$path)] as $bad) { self::assertNull(FansSsoReturn::path($bad)); }
        }
        $GLOBALS['fans_sso_home']='https://fans.example.test/community';
        self::assertSame('/community/faluss-fans/fan/hof?support='.$id,FansSsoReturn::path('/community/faluss-fans/fan/hof?support='.$id));
    }

    public function testOnlyExplicitFansPathsSurviveIncludingSubdirectories(): void
    {
        foreach (['/app', '/app/fan/explorer', '/app/creator/mon-profil', '/app/creator/images'] as $path) {
            self::assertSame($path, FansSsoReturn::path($path . '/'));
        }
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

    public function testOnlyOneExactPublicationSelectionIsPreserved(): void
    {
        $id = '123e4567-e89b-42d3-a456-426614174000';
        $path = '/faluss-fans/creator/creer?publication=' . $id;
        self::assertSame($path, FansSsoReturn::path($path));
        self::assertSame($path, FansSsoReturn::path('/faluss-fans/creator/creer/?publication=' . $id));
        $state = str_repeat('s', 43);
        self::assertSame($path, FansSsoReturn::open(FansSsoReturn::seal($path, $state), $state));
        foreach ([
            $path . '&publication=' . $id, $path . '&nonce=test', $path . '#fragment',
            $path . '/', $path . '%0a', '/faluss-fans/fan/explorer?publication=' . $id,
            '/faluss-fans/creator/creer?publication[]=' . $id,
            '/faluss-fans/creator/creer?publication=' . strtoupper($id),
            '/faluss-fans/creator/creer?publication=' . str_replace('-42d3-', '-12d3-', $id),
            '/faluss-fans/creator/creer?publication=' . str_replace('123e', '%3123e', $id),
        ] as $candidate) {
            self::assertNull(FansSsoReturn::path($candidate), $candidate);
            self::assertNull(FansSsoReturn::seal($candidate, $state), $candidate);
        }
        $GLOBALS['fans_sso_home'] = 'https://fans.example.test/community';
        self::assertSame('/community' . $path, FansSsoReturn::path('/community' . $path));
        self::assertSame('/community' . $path, FansSsoReturn::open(FansSsoReturn::seal('/community' . $path, $state), $state));
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

    public function testSessionManagementReturnKeepsOnlyAnExactOpaqueSelection(): void
    {
        $id = '123e4567-e89b-42d3-a456-426614174000'; $state = str_repeat('s', 43);
        foreach (['/app/', '/faluss-fans/'] as $prefix) {
            foreach (['manage=sessions', 'manage=new', 'manage=session&session=' . $id, 'manage=sessions&sessions_cursor=' . $id] as $query) {
                $path = $prefix . 'creator/hof?' . $query;
                self::assertSame($path, FansSsoReturn::path($path));
                self::assertSame($path, FansSsoReturn::open(FansSsoReturn::seal($path, $state), $state));
                self::assertNull(FansSsoReturn::open(FansSsoReturn::seal($path, $state), str_repeat('t', 43)));
            }
        }
        foreach (['manage=new&nonce=private', 'manage=new&manage=sessions', 'manage=session&sessions_cursor=' . $id,
            'manage=sessions&session=' . $id, 'manage=session&session[]=' . $id, 'manage=session&session=' . strtoupper($id),
            'manage=session&session=' . $id . '&redirect=https://other.example', 'manage=sessions#private'] as $query) {
            self::assertNull(FansSsoReturn::path('/app/creator/hof?' . $query));
        }
        self::assertNull(FansSsoReturn::path('/app/fan/hof?manage=new'));
        $GLOBALS['fans_sso_home'] = 'https://fans.example.test/community';
        self::assertSame('/community/app/creator/hof?manage=new', FansSsoReturn::path('/community/app/creator/hof?manage=new'));
    }
    public function testTerritorialNavigationKeepsOnlyExactOwnerPageAndPublicCursors(): void
    {
        $id = '123e4567-e89b-42d3-a456-426614174000'; $state = str_repeat('s',43);
        foreach (['/app/','/faluss-fans/'] as $prefix) {
            foreach (['','&policy_cursor=' . $id,'&change_cursor=' . $id,'&change=' . $id] as $query) {
                $path = $prefix . 'creator/hof?manage=territory' . $query;
                self::assertSame($path,FansSsoReturn::path($path));
                self::assertSame($path,FansSsoReturn::open(FansSsoReturn::seal($path,$state),$state));
            }
        }
        foreach (['&change=' . strtoupper($id),'&change=' . $id . '&change=' . $id,
            '&change[]=' . $id,'&creator=' . $id,'&change=' . $id . '&nonce=private','&redirect=https://other.example'] as $query) {
            self::assertNull(FansSsoReturn::path('/app/creator/hof?manage=territory' . $query));
        }
        self::assertNull(FansSsoReturn::path('/app/fan/hof?manage=territory'));
        $GLOBALS['fans_sso_home'] = 'https://fans.example.test/community';
        self::assertSame('/community/app/creator/hof?manage=territory&change=' . $id,
            FansSsoReturn::path('/community/app/creator/hof?manage=territory&change=' . $id));
    }

    public function testPrivateCoorganizerPageReturnsToTheSameSessionWithOnlyACanonicalPublicCursor(): void
    {
        $id = '123e4567-e89b-42d3-a456-426614174000'; $state = str_repeat('s', 43);
        $cursor = rawurlencode('d1.all.2026-10-17T12:30:00.' . $id);
        foreach (['/app/', '/faluss-fans/'] as $prefix) {
            $path = $prefix . 'creator/hof?manage=session&session=' . $id . '&coorganizer_cursor=' . $cursor;
            self::assertSame($path, FansSsoReturn::path($path));
            self::assertSame($path, FansSsoReturn::open(FansSsoReturn::seal($path, $state), $state));
            self::assertNull(FansSsoReturn::open(FansSsoReturn::seal($path, $state), str_repeat('t', 43)));
            foreach ([$path . '&nonce=private', $path . '&coorganizer_cursor=' . $cursor,
                str_replace('d1.all.', 'd1.music.', $path), str_replace('2026-10-17', '2026-02-30', $path),
                str_replace('%3A', '%253A', $path), str_replace('manage=session&', 'manage=new&', $path)] as $invalid) {
                self::assertNull(FansSsoReturn::path($invalid));
            }
        }
        $GLOBALS['fans_sso_home'] = 'https://fans.example.test/community';
        $path = '/community/app/creator/hof?manage=session&session=' . $id . '&coorganizer_cursor=' . $cursor;
        self::assertSame($path, FansSsoReturn::path($path));
    }

    public function testMessageReturnKeepsOnlyOneOpaqueSelection(): void
    {
        $id='123e4567-e89b-42d3-a456-426614174000';$state=str_repeat('s',43);
        foreach(['fan','creator'] as $role) {foreach(['creator','thread'] as $kind) {
            $path='/faluss-fans/'.$role.'/messages?'.$kind.'='.$id;
            self::assertSame($path,FansSsoReturn::path($path));
            self::assertSame($path,FansSsoReturn::open(FansSsoReturn::seal($path,$state),$state));
            foreach([$path.'&body=private',$path.'&nonce=test',$path.'#fragment',$path.'&'.$kind.'='.$id,str_replace('?'.$kind.'=','?'.$kind.'[]=',$path)] as $invalid) {self::assertNull(FansSsoReturn::path($invalid));}
        }}
        self::assertNull(FansSsoReturn::path('/faluss-fans/fan/messages?email=private'));
    }

    public function testVoluntaryEntryAndParticipantPaginationKeepOnlyTheirCanonicalSelection(): void
    {
        $id = '123e4567-e89b-42d3-a456-426614174000'; $state = str_repeat('s',43);
        foreach (['/app/','/faluss-fans/'] as $prefix) {
            foreach (['manage=join&session=' . $id,'manage=session&session=' . $id . '&participant_cursor=' . $id] as $query) {
                $path = $prefix . 'creator/hof?' . $query;
                self::assertSame($path,FansSsoReturn::path($path));
                self::assertSame($path,FansSsoReturn::open(FansSsoReturn::seal($path,$state),$state));
                foreach ([$path . '&nonce=private',$path . '&session=' . $id,$path . '#private',str_replace($id,strtoupper($id),$path)] as $invalid) {
                    self::assertNull(FansSsoReturn::path($invalid));
                }
            }
        }
        self::assertNull(FansSsoReturn::path('/app/creator/hof?manage=join&sessions_cursor=' . $id));
        self::assertNull(FansSsoReturn::path('/app/fan/hof?manage=join&session=' . $id));
        $GLOBALS['fans_sso_home'] = 'https://fans.example.test/community';
        self::assertSame('/community/app/creator/hof?manage=join&session=' . $id,
            FansSsoReturn::path('/community/app/creator/hof?manage=join&session=' . $id));
    }
}
