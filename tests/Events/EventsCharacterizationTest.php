<?php

declare(strict_types=1);

namespace Faluss\Platform\Events;

use PHPUnit\Framework\TestCase;

final class EventsCharacterizationTest extends TestCase
{
    public function testHistoricalRuntimeAndContractsRemainContentIdentical(): void
    {
        $root = dirname(__DIR__, 2);
        $files = [
            'src/Events/Legacy/faluss-events.php' => 'a397818b4270737743b1e1103fdd4a4bccf04b377384a5263dd33d4d4f8d4a3e',
            'src/Events/Legacy/includes/class-faluss-events-canonicalizer.php' => '5c4a1d1a02fb5f85623ffcf346aca8e474ea5b635657934db7366e92b33d269d',
            'src/Events/Legacy/includes/class-faluss-events-catalog-validator.php' => '76d25102bebf49ad86e25805fee3a0f3f3f1ce02473bff6369140dd9659f6d28',
            'src/Events/Legacy/includes/class-faluss-events-engine.php' => '0d180efa2fa3d34ac39c46fcad2ef0bb866e7c96778d814a5d91055e7f5e2674',
            'src/Events/Legacy/includes/class-faluss-events-envelope-validator.php' => '654a9fd0d0b5ba9910005800f59a916206b87a09c3671e31c8f26bf26a76804d',
            'src/Events/Legacy/includes/class-faluss-events-retention.php' => '0f2a5aa1087903c91a29f0e0344efd5c3c2f9a2cfb789b81a6fb480075d07dc4',
            'src/Events/Legacy/includes/class-faluss-events-schema.php' => '4f368f352bd8199dd1f4cdf01907ab8ffaf1ef571466f7d77b20b67c7ff9ceed',
            'src/Events/Legacy/includes/class-faluss-events-workers.php' => 'e5c518c486c4386038dd8b1d7ea5012ed3bf45cb51ee6b15c907fae2f09a215e',
            'src/Events/Legacy/includes/class-faluss-events.php' => '2b9ca08e17d867173290b0396342ccc580d39755d8c9ce90924def847c9f9b39',
            'contracts/faluss-event-acceptance.schema.json' => '4a3659f597cd32ac3f2010ad48c98111603f2aada89006b2c43b8dbcff1091ca',
            'contracts/faluss-event-envelope.schema.json' => 'cd46611fb644baf5c7fc4529f6b680ec22033143c58fe8a8126f5a714c014880',
            'contracts/faluss-event-source-catalog.schema.json' => 'fa30636bdb1776ab6a81022db8379a01e3a87fb007cf18a3bf70f0b401927c05',
        ];

        foreach ($files as $file => $hash) {
            $contents = file_get_contents($root . '/' . $file);
            self::assertNotFalse($contents, $file);
            self::assertSame($hash, hash('sha256', str_replace("\r\n", "\n", $contents)), $file);
        }
    }
}
