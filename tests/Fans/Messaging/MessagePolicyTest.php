<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Messaging;
use PHPUnit\Framework\TestCase;

final class MessagePolicyTest extends TestCase
{
    public function testTextBoundaryCountsUnicodeAndRejectsOtherPayloads(): void
    {
        self::assertTrue(MessagePolicy::text(str_repeat('é',1000),1000));
        self::assertFalse(MessagePolicy::text(str_repeat('é',1001),1000));
        self::assertTrue(MessagePolicy::text("Bonjour\nDeuxième ligne\ttexte"));
        foreach([''," \n ","\xff",'<script>',"abc\x00",[],false,null] as $invalid) { self::assertFalse(MessagePolicy::text($invalid)); }
    }
    public function testNoClientSubscriptionOrImplicitRuntimeOpening(): void
    {
        self::assertFalse(MessagePolicy::directOpeningAvailable());
        self::assertFalse(MessageModule::enabled());
        $bootstrap=file_get_contents(dirname(__DIR__,3).'/faluss-platform.php');
        self::assertStringNotContainsString('MessageRest::routes', $bootstrap);
    }
    public function testOpaqueIdentifiersAreStrict(): void
    {
        self::assertTrue(MessagePolicy::uuid('11111111-1111-4111-8111-111111111111'));
        foreach(['11111111-1111-1111-8111-111111111111',str_repeat('a',36),[],1,null] as $id) { self::assertFalse(MessagePolicy::uuid($id)); }
    }
}
