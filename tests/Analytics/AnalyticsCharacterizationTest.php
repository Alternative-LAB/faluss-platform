<?php

declare(strict_types=1);

namespace Faluss\Platform\Analytics;

use PHPUnit\Framework\TestCase;

final class AnalyticsCharacterizationTest extends TestCase
{
    public function testHistoricalRuntimeSchemasAndContractRemainContentIdentical(): void
    {
        $root = dirname(__DIR__, 2);
        $files = [
            'src/Analytics/Legacy/faluss-analytics.php' => 'c2f9e99491ed0eb4f167b4e4bd2d9c64003f821d4e968a02b8a34c7f2f0feb23',
            'src/Analytics/Legacy/includes/class-faluss-analytics.php' => 'ef67954bee9fca020b39b56329da961dca034f0ffbce2450b5bfd2f3ad319e79',
            'src/Analytics/Legacy/includes/class-faluss-analytics-schema.php' => '757347267a23ff353085abbeda2b161329415431aa8ed7ace0fd67e64379f677',
            'src/Analytics/Legacy/includes/class-faluss-analytics-event-validator.php' => 'a1ba89cf244e84acaa7713e92995be192e5d7ee9ba06b129ca1a2b22f4757c36',
            'src/Analytics/Legacy/includes/class-faluss-analytics-consumer.php' => 'f7ff001131169fc1cdc77f8bf352c5c53db58cf9986a241547cfdae19147c6a5',
            'src/Analytics/Legacy/includes/class-faluss-analytics-read-model.php' => 'c12473fd8a2fb5af52ab1ad639611e386a1968986a8a3a161f6a002f2f49dcff',
            'src/Analytics/Legacy/includes/class-faluss-analytics-retention.php' => 'ee8ed3098ae0ea440b40e11193bad2d193d35361f17d29b833caa2f15bcbd8f6',
            'contracts/faluss-hub-portal-viewed.schema.json' => '479010ca7db7ec178c23145a64705b47a57504b26b3cdc0da8c250a77c22c399',
            'contracts/faluss-hub-app-opened.schema.json' => 'a1d3403da385c77710165c59b32a684d45ebb19b02604b122ef2e40c90df33c3',
            'contracts/faluss-hub-daily-reward-claimed.schema.json' => 'bdc54f32e9ded1e036bd7ba0b5abbf5cf0ed5e62701d2aed1d95be1de90a691e',
            'contracts/faluss-me-card-viewed.schema.json' => 'ebf93d47d39ed8c8962660f3e4a664981beffa0e92ecebea0101f9ea52fe21ec',
            'contracts/faluss-me-link-clicked.schema.json' => '71e57f48a4c89c67f84e4de5382dab695694373f337c3061602b9f5c052b056e',
            'contracts/faluss-me-collection-opened.schema.json' => '350e8d87bb53f1361123585e3b818731a67725d837cf3a73372b632831827144',
            'contracts/faluss-analytics-summary.schema.json' => '96df283d064b9e06f3e6c0902d13a6b2a676e5ffeb2c3c34897264586c4b0776',
            'docs/FALUSS_ANALYTICS_CONTRACT.md' => '084a097234b93c71f1c1a228a48c002605345a4f6614f9983e379837fc3f7de1',
        ];

        foreach ($files as $file => $hash) {
            $contents = file_get_contents($root . '/' . $file);
            self::assertNotFalse($contents, $file);
            self::assertSame($hash, hash('sha256', str_replace("\r\n", "\n", $contents)), $file);
        }
    }
}
