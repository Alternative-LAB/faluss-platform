<?php

declare(strict_types=1);

use Faluss\Platform\AppsRegistry\AppsRegistryService;
use Faluss\Platform\AppsRegistry\ManifestValidator;
use Faluss\Platform\AppsRegistry\ReadModelValidator;

if (!defined('ABSPATH')) {
    exit;
}

final class Faluss_Apps_Registry
{
    public const DOCUMENT_TYPE = AppsRegistryService::DOCUMENT_TYPE;
    public const CONTRACT_VERSION = AppsRegistryService::CONTRACT_VERSION;
    public const READ_MODEL_VERSION = AppsRegistryService::READ_MODEL_VERSION;

    public static function boot(): void
    {
        AppsRegistryService::boot();
    }

    public static function register_federation_validator(): bool|WP_Error
    {
        return AppsRegistryService::registerFederationValidator();
    }

    /** @return array{document_type:string,contract_version:string} */
    public static function manifest_contract(): array
    {
        return AppsRegistryService::manifestContract();
    }

    public static function validate_manifest(
        mixed $manifest,
        mixed $payloadContract,
        mixed $requestContext
    ): bool {
        return AppsRegistryService::validateManifest($manifest, $payloadContract, $requestContext);
    }

    public static function register_source(mixed $descriptor): bool|WP_Error
    {
        return AppsRegistryService::registerSource($descriptor);
    }

    /** @return array<string, mixed>|WP_Error */
    public static function read_for_member(
        mixed $falussId,
        mixed $surface,
        mixed $consumerVersion
    ): array|WP_Error {
        return AppsRegistryService::readForMember($falussId, $surface, $consumerVersion);
    }
}

final class Faluss_Apps_Registry_Manifest_Validator
{
    public static function validate(
        mixed $manifest,
        mixed $payloadContract,
        mixed $requestContext
    ): bool {
        return ManifestValidator::validate($manifest, $payloadContract, $requestContext);
    }
}

final class Faluss_Apps_Registry_Read_Model_Validator
{
    public static function validate(mixed $document): bool
    {
        return ReadModelValidator::validate($document);
    }
}
