<?php

declare(strict_types=1);

namespace Faluss\Platform\AppsRegistry;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/AppsRegistryFixtures.php';

final class ReadModelValidatorTest extends TestCase
{
    public function testRejectsIncompatibleMissingAndMemberLeakingOutputs(): void
    {
        self::assertTrue(ReadModelValidator::validate(AppsRegistryFixtures::readModel()));

        $notLinkedWithOutputs = AppsRegistryFixtures::readModel();
        $notLinkedWithOutputs['applications'][0]['member_relationship'] = 'not_linked';
        self::assertFalse(ReadModelValidator::validate($notLinkedWithOutputs));

        $missingCapability = AppsRegistryFixtures::readModel();
        unset($missingCapability['applications'][0]['capabilities'][0]['specialized_read_model']);
        self::assertFalse(ReadModelValidator::validate($missingCapability));

        $incompatibleWithAction = AppsRegistryFixtures::readModel();
        $incompatibleWithAction['applications'][0]['capabilities'][0]['surface_compatibility']['status'] = 'incompatible';
        self::assertFalse(ReadModelValidator::validate($incompatibleWithAction));

        $memberLeak = AppsRegistryFixtures::readModel();
        $memberLeak['faluss_id'] = '11111111-1111-4111-8111-111111111111';
        self::assertFalse(ReadModelValidator::validate($memberLeak));

        $wrongVersion = AppsRegistryFixtures::readModel();
        $wrongVersion['consumer']['consumer_version'] = '2.0.0';
        self::assertFalse(ReadModelValidator::validate($wrongVersion));
    }
}
