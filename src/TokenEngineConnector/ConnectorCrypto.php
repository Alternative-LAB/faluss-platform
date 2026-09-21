<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngineConnector;

final class ConnectorCrypto
{
    public const V2_SODIUM = 'tecv2:sodium:';
    public const V2_OPENSSL = 'tecv2:openssl:';

    public static function isAvailable(): bool
    {
        return self::sodiumAvailable() || self::opensslAvailable();
    }

    public static function encrypt(mixed $secret): string|\WP_Error
    {
        if (!is_string($secret) || $secret === '' || strlen($secret) > 512 || !self::isAvailable()) {
            return new \WP_Error('connector_secret_protection_unavailable');
        }

        try {
            if (self::sodiumAvailable()) {
                $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

                return self::V2_SODIUM . base64_encode(
                    $nonce . sodium_crypto_secretbox($secret, $nonce, self::keyV2())
                );
            }

            $ivLength = max(1, openssl_cipher_iv_length('aes-256-gcm'));
            $iv = random_bytes($ivLength);
            $tag = '';
            $ciphertext = openssl_encrypt(
                $secret,
                'aes-256-gcm',
                self::keyV2(),
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );
            if ($ciphertext === false || strlen($tag) !== 16) {
                return new \WP_Error('connector_secret_protection_unavailable');
            }

            return self::V2_OPENSSL . base64_encode($iv . $tag . $ciphertext);
        } catch (\Throwable) {
            return new \WP_Error('connector_secret_protection_unavailable');
        }
    }

    public static function decrypt(mixed $stored): string|\WP_Error
    {
        if (!is_string($stored) || $stored === '') {
            return new \WP_Error('connector_secret_missing');
        }

        try {
            if (str_starts_with($stored, self::V2_SODIUM)) {
                return self::decryptSodium(substr($stored, strlen(self::V2_SODIUM)), self::keyV2());
            }
            if (str_starts_with($stored, self::V2_OPENSSL)) {
                return self::decryptOpenSsl(substr($stored, strlen(self::V2_OPENSSL)), self::keyV2());
            }
            if (str_starts_with($stored, 'sodium:')) {
                return self::decryptSodium(substr($stored, 7), self::legacyKey());
            }
            if (str_starts_with($stored, 'openssl:')) {
                return self::decryptOpenSsl(substr($stored, 8), self::legacyKey());
            }
        } catch (\Throwable) {
            return new \WP_Error('connector_secret_unavailable');
        }

        return new \WP_Error('connector_secret_unavailable');
    }

    private static function decryptSodium(string $encoded, string $key): string|\WP_Error
    {
        if (!self::sodiumAvailable()) {
            return new \WP_Error('connector_secret_unavailable');
        }

        $payload = base64_decode($encoded, true);
        if ($payload === false || strlen($payload) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return new \WP_Error('connector_secret_unavailable');
        }

        $secret = sodium_crypto_secretbox_open(
            substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $key
        );

        return $secret === false ? new \WP_Error('connector_secret_unavailable') : $secret;
    }

    private static function decryptOpenSsl(string $encoded, string $key): string|\WP_Error
    {
        if (!self::opensslAvailable()) {
            return new \WP_Error('connector_secret_unavailable');
        }

        $payload = base64_decode($encoded, true);
        $ivLength = max(1, openssl_cipher_iv_length('aes-256-gcm'));
        if ($payload === false || strlen($payload) <= $ivLength + 16) {
            return new \WP_Error('connector_secret_unavailable');
        }

        $secret = openssl_decrypt(
            substr($payload, $ivLength + 16),
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            substr($payload, 0, $ivLength),
            substr($payload, $ivLength, 16)
        );

        return $secret === false ? new \WP_Error('connector_secret_unavailable') : $secret;
    }

    private static function sodiumAvailable(): bool
    {
        return defined('SODIUM_CRYPTO_SECRETBOX_NONCEBYTES')
            && function_exists('sodium_crypto_secretbox')
            && function_exists('sodium_crypto_secretbox_open');
    }

    private static function opensslAvailable(): bool
    {
        return function_exists('openssl_encrypt')
            && function_exists('openssl_decrypt')
            && function_exists('openssl_cipher_iv_length')
            && openssl_cipher_iv_length('aes-256-gcm') > 0;
    }

    private static function keyV2(): string
    {
        return hash_hkdf('sha256', wp_salt('auth'), 32, 'token-engine-connector-secret-v2');
    }

    private static function legacyKey(): string
    {
        return hash('sha256', wp_salt('auth'), true);
    }
}
