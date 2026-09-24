<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\Core;

use Faluss\Platform\Release\ReleasePreparer;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/scripts/prepare-release.php';

final class ReleasePreparerTest extends TestCase
{
    public function testAutomaticBumpFollowsGitmojiSemantics(): void
    {
        self::assertSame('2.0.0', ReleasePreparer::nextVersion('1.4.2', 'auto', [
            ['sha' => str_repeat('a', 40), 'subject' => '💥 Change public contract'],
        ]));
        self::assertSame('1.5.0', ReleasePreparer::nextVersion('1.4.2', 'auto', [
            ['sha' => str_repeat('b', 40), 'subject' => '✨ Add release automation'],
        ]));
        self::assertSame('1.5.0', ReleasePreparer::nextVersion('1.4.2', 'auto', [
            ['sha' => str_repeat('d', 40), 'subject' => '🚀 Automate release delivery'],
        ]));
        self::assertSame('1.4.3', ReleasePreparer::nextVersion('1.4.2', 'auto', [
            ['sha' => str_repeat('c', 40), 'subject' => '🐛 Preserve active state'],
        ]));
    }

    public function testReleaseNotesAreGroupedAndTraceable(): void
    {
        $notes = ReleasePreparer::releaseNotes([
            ['sha' => '1234567890abcdef', 'subject' => '✨ Add private updater'],
            ['sha' => 'abcdef1234567890', 'subject' => '🔒 Restrict upload token'],
            ['sha' => 'fedcba0987654321', 'subject' => '📝 Explain release flow'],
        ]);

        self::assertStringContainsString("### Ajouté\n\n- Add private updater (`1234567`)", $notes);
        self::assertStringContainsString("### Sécurité\n\n- Restrict upload token (`abcdef1`)", $notes);
        self::assertStringContainsString("### Documentation\n\n- Explain release flow (`fedcba0`)", $notes);
    }

    public function testSquashedCommitBodiesRestoreOriginalGitmojiSubjects(): void
    {
        $log = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\x1fTitre français (#39)\x1f"
            . "* 🚀 Automate semantic release preparation\n\n"
            . "* 🐛 Classify delivery commits as minor releases\n\x1e";

        self::assertSame([
            ['sha' => str_repeat('a', 40), 'subject' => '🚀 Automate semantic release preparation'],
            ['sha' => str_repeat('a', 40), 'subject' => '🐛 Classify delivery commits as minor releases'],
        ], ReleasePreparer::parseCommitLog($log));
        self::assertSame('0.3.0', ReleasePreparer::nextVersion(
            '0.2.0',
            'auto',
            ReleasePreparer::parseCommitLog($log)
        ));
    }
}
