<?php

declare(strict_types=1);

namespace Faluss\Platform\AppsRegistry;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WordPressStubs.php';

final class ManifestValidatorTest extends TestCase
{
    public function testAcceptsOnlyTheExactVersionedHubAndMeManifests(): void
    {
        self::assertTrue(ManifestValidator::validate(
            AppsRegistryFixtures::hubManifest(),
            AppsRegistryFixtures::manifestContract(),
            AppsRegistryFixtures::manifestContext('faluss-hub')
        ));
        self::assertTrue(ManifestValidator::validate(
            AppsRegistryFixtures::meManifest(),
            AppsRegistryFixtures::manifestContract(),
            AppsRegistryFixtures::manifestContext('faluss-me')
        ));

        $wrongVersion = AppsRegistryFixtures::hubManifest();
        $wrongVersion['manifest_version'] = '2.0.0';
        self::assertFalse(ManifestValidator::validate(
            $wrongVersion,
            AppsRegistryFixtures::manifestContract(),
            AppsRegistryFixtures::manifestContext('faluss-hub')
        ));

        $foreignCapability = AppsRegistryFixtures::hubManifest();
        $foreignCapability['capabilities'][0]['capability_key'] = 'faluss-me.daily-reward';
        self::assertFalse(ManifestValidator::validate(
            $foreignCapability,
            AppsRegistryFixtures::manifestContract(),
            AppsRegistryFixtures::manifestContext('faluss-hub')
        ));

        $missingCapabilityContract = AppsRegistryFixtures::hubManifest();
        $missingCapabilityContract['capabilities'][0]['read_model_contract'] = null;
        $missingCapabilityContract['capabilities'][0]['interfaces'] = ['module_read_model'];
        self::assertFalse(ManifestValidator::validate(
            $missingCapabilityContract,
            AppsRegistryFixtures::manifestContract(),
            AppsRegistryFixtures::manifestContext('faluss-hub')
        ));

        $sensitive = AppsRegistryFixtures::hubManifest();
        $sensitive['public_presentation']['summary'] = 'Contact par e-mail';
        self::assertFalse(ManifestValidator::validate(
            $sensitive,
            AppsRegistryFixtures::manifestContract(),
            AppsRegistryFixtures::manifestContext('faluss-hub')
        ));

        $executable = AppsRegistryFixtures::hubManifest();
        $executable['public_presentation']['summary'] = '<script>alert(1)</script>';
        self::assertFalse(ManifestValidator::validate(
            $executable,
            AppsRegistryFixtures::manifestContract(),
            AppsRegistryFixtures::manifestContext('faluss-hub')
        ));
    }
}
