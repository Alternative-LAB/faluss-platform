<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngineConnector;

/**
 * Narrow server-side client for the authoritative Token Engine on faluss.com.
 *
 * The connector never stores a balance, entitlement, reward decision or Faluss
 * identifier. Its only cache is the short-lived Core bearer token.
 */
final class ConnectorService
{
    public const OPTION = 'token_engine_connector_settings';
    public const PROTOCOL_VERSION = '1';
    public const CORE_ENGINE = 'token-engine';
    public const REST_MODE_REWRITE = 'rewrite';
    public const REST_MODE_QUERY = 'query';
    public const REST_NAMESPACE = 'token-engine/v1';
    public const PERMISSION_WALLET_READ = 'wallet.read';
    public const PERMISSION_REWARD_CLAIM = 'reward.claim';
    public const PERMISSION_ENTITLEMENTS_READ = 'entitlements.read';

    private const CACHE_GROUP = 'faluss-platform-token-engine-connector';
    private const TOKEN_MAX_TTL = 240;

    /** @return array{core_site_url:string,client_id:string,project_key:string,secret_configured:bool,secret_state:string} */
    public static function configuration(): array
    {
        $stored = get_option(self::OPTION, []);
        $stored = is_array($stored) ? $stored : [];
        $secretState = self::secretState($stored);
        $siteUrl = array_key_exists('core_site_url', $stored)
            ? self::normaliseSiteUrl($stored['core_site_url'])
            : self::migrateLegacySiteUrl($stored['core_url'] ?? '');

        return [
            'core_site_url' => $siteUrl,
            'client_id' => self::clientId($stored['client_id'] ?? ''),
            'project_key' => self::projectKey($stored['project_key'] ?? ''),
            'secret_configured' => $secretState === 'saved',
            'secret_state' => $secretState,
        ];
    }

    public static function isConfigured(): bool
    {
        $settings = self::configuration();

        return $settings['core_site_url'] !== ''
            && $settings['client_id'] !== ''
            && $settings['project_key'] !== ''
            && $settings['secret_configured'];
    }

    /** @return array{core_site_url:string,client_id:string,project_key:string,secret_configured:bool,secret_state:string}|\WP_Error */
    public static function saveConfiguration(mixed $values): array|\WP_Error
    {
        $values = is_array($values) ? $values : [];
        $current = get_option(self::OPTION, []);
        $current = is_array($current) ? $current : [];
        $oldCacheKey = self::cacheKey($current);
        $next = [
            'core_site_url' => self::normaliseSiteUrl($values['core_site_url'] ?? ''),
            'client_id' => self::clientId($values['client_id'] ?? ''),
            'project_key' => self::projectKey($values['project_key'] ?? ''),
            'secret_protected' => is_string($current['secret_protected'] ?? null)
                ? $current['secret_protected']
                : '',
        ];
        $submittedSecret = isset($values['client_secret'])
            ? trim((string) wp_unslash($values['client_secret']))
            : '';

        if ($submittedSecret !== '') {
            $protected = ConnectorCrypto::encrypt($submittedSecret);
            if (is_wp_error($protected)) {
                return self::error('connector_secret_protection_unavailable');
            }

            $verified = ConnectorCrypto::decrypt($protected);
            if ($verified instanceof \WP_Error || !hash_equals($submittedSecret, $verified)) {
                return self::error('connector_secret_protection_unavailable');
            }

            $next['secret_protected'] = $protected;
        }

        if ($next['core_site_url'] === '') {
            return self::error('connector_core_url_invalid');
        }
        if ($next['client_id'] === '') {
            return self::error('connector_client_invalid');
        }
        if ($next['project_key'] === '') {
            return self::error('connector_project_invalid');
        }
        if ($submittedSecret === '' && self::secretState($current) !== 'saved') {
            return self::error('connector_secret_required');
        }
        if ($next['secret_protected'] === '') {
            return self::error('connector_secret_required');
        }

        update_option(self::OPTION, $next, false);
        $persisted = get_option(self::OPTION, []);
        $persistedSecret = ConnectorCrypto::decrypt(
            is_array($persisted) ? ($persisted['secret_protected'] ?? '') : ''
        );
        if ($persistedSecret instanceof \WP_Error
            || ($submittedSecret !== '' && !hash_equals($submittedSecret, $persistedSecret))
        ) {
            update_option(self::OPTION, $current, false);

            return self::error('connector_secret_persistence_failed');
        }

        wp_cache_delete($oldCacheKey, self::CACHE_GROUP);
        wp_cache_delete(self::cacheKey($next), self::CACHE_GROUP);

        return self::configuration();
    }

    /** Converts only recognized legacy REST bases to a canonical Core site URL. */
    public static function migrateLegacySiteUrl(mixed $value): string
    {
        $value = self::rawHttpsUrl($value, true);
        if ($value === '') {
            return '';
        }

        $parts = wp_parse_url($value);
        if (!is_array($parts)) {
            return '';
        }

        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        $query = (string) ($parts['query'] ?? '');

        if (preg_match('#^(.*)/wp-json/token-engine/v1$#', $path, $matches) === 1 && $query === '') {
            return self::siteUrlFromParts($parts, $matches[1]);
        }

        parse_str($query, $queryValues);
        if (preg_match('#^(.*)/index\.php$#', $path, $matches) === 1
            && count($queryValues) === 1
            && ($queryValues['rest_route'] ?? '') === '/token-engine/v1/'
        ) {
            return self::siteUrlFromParts($parts, $matches[1]);
        }

        return self::normaliseSiteUrl($value);
    }

    public static function currentSubjectId(): string
    {
        return ConnectorSubjectResolver::current();
    }

    /** @return array<string, bool|string> */
    public static function subjectDiagnostic(): array
    {
        return ConnectorSubjectResolver::diagnostic();
    }

    /** @return array<string, mixed>|\WP_Error */
    public static function coreConnectionTest(): array|\WP_Error
    {
        $response = self::authenticatedRoute('diagnostic', 'GET', [], self::PERMISSION_WALLET_READ);
        if (is_wp_error($response)) {
            return $response;
        }

        $data = $response['data'];
        $token = $response['token'];
        if (($data['engine'] ?? null) !== self::CORE_ENGINE) {
            return self::error('connector_core_unidentified', self::diagnosticFrom($data, $token), 'core');
        }
        if (($data['protocol_version'] ?? null) !== self::PROTOCOL_VERSION) {
            return self::error('connector_protocol_incompatible', self::diagnosticFrom($data, $token), 'protocol');
        }
        if (($data['connected'] ?? null) !== true) {
            return self::error('connector_core_rejected', self::diagnosticFrom($data, $token), 'core');
        }
        if (self::configuration()['project_key'] !== self::projectKey($data['project_key'] ?? '')) {
            return self::error('connector_project_rejected', self::diagnosticFrom($data, $token), 'credentials');
        }

        $permissions = self::permissions($data['permissions'] ?? []);
        if (!in_array(self::PERMISSION_WALLET_READ, $permissions, true)) {
            return self::error(
                'connector_permission_wallet_read_missing',
                self::diagnosticFrom($data, $token),
                'permission'
            );
        }

        return [
            'connected' => true,
            'project_key' => self::projectKey($data['project_key'] ?? ''),
            'permissions' => $permissions,
            'protocol_version' => self::PROTOCOL_VERSION,
            'rest_mode' => $response['rest_mode'],
            'steps' => self::successfulSteps($response['rest_mode']),
            'diagnostic_id' => self::diagnosticFrom($data, $token),
        ];
    }

    /** @return array{project_key:string,balance:int}|\WP_Error */
    public static function balanceForCurrentSubject(): array|\WP_Error
    {
        $subject = self::currentSubjectId();
        if ($subject === '') {
            return self::error('connector_subject_unavailable');
        }

        $response = self::authenticatedRoute(
            'balance',
            'POST',
            ['body' => ['subject_id' => $subject]],
            self::PERMISSION_WALLET_READ
        );
        if (is_wp_error($response)) {
            return $response;
        }

        $data = $response['data'];
        if (!is_int($data['balance'] ?? null)
            || $data['balance'] < 0
            || self::configuration()['project_key'] !== self::projectKey($data['project_key'] ?? '')
        ) {
            return self::error('connector_balance_unavailable', self::diagnosticFrom($data), 'route');
        }

        return ['project_key' => self::projectKey($data['project_key']), 'balance' => $data['balance']];
    }

    /** @return list<array{code:string,label:string,type:string}>|\WP_Error */
    public static function entitlementDefinitions(): array|\WP_Error
    {
        $response = self::authenticatedRoute(
            'entitlement_definitions',
            'GET',
            [],
            self::PERMISSION_ENTITLEMENTS_READ
        );
        if (is_wp_error($response)) {
            return $response;
        }

        $data = $response['data'];
        if (self::configuration()['project_key'] !== self::projectKey($data['project_key'] ?? '')
            || !is_array($data['definitions'] ?? null)
        ) {
            return self::error('connector_entitlements_unavailable', self::diagnosticFrom($data), 'entitlements');
        }

        $definitions = [];
        foreach ($data['definitions'] as $definition) {
            if (!is_array($definition)) {
                continue;
            }
            $code = self::entitlementCode($definition['entitlement_code'] ?? '');
            $label = is_string($definition['label'] ?? null)
                ? sanitize_text_field($definition['label'])
                : '';
            $type = ($definition['entitlement_type'] ?? null) === 'theme' ? 'theme' : '';
            if ($code !== '' && $label !== '' && $type !== '') {
                $definitions[] = ['code' => $code, 'label' => $label, 'type' => $type];
            }
        }

        return $definitions;
    }

    public static function subjectHasEntitlement(mixed $subjectId, mixed $entitlementCode): bool|\WP_Error
    {
        $subjectId = self::subjectId($subjectId);
        $entitlementCode = self::entitlementCode($entitlementCode);
        if ($subjectId === '' || $entitlementCode === '') {
            return false;
        }

        $response = self::authenticatedRoute(
            'entitlement',
            'POST',
            ['body' => ['subject_id' => $subjectId, 'entitlement_code' => $entitlementCode]],
            self::PERMISSION_ENTITLEMENTS_READ
        );
        if (is_wp_error($response)) {
            return $response;
        }

        $data = $response['data'];
        if (!is_bool($data['granted'] ?? null)
            || self::configuration()['project_key'] !== self::projectKey($data['project_key'] ?? '')
            || !hash_equals($entitlementCode, self::entitlementCode($data['entitlement_code'] ?? ''))
        ) {
            return self::error('connector_entitlements_unavailable', self::diagnosticFrom($data), 'entitlements');
        }

        return $data['granted'];
    }

    public static function currentSubjectHasEntitlement(mixed $entitlementCode): bool|\WP_Error
    {
        $subject = self::currentSubjectId();

        return $subject === '' ? false : self::subjectHasEntitlement($subject, $entitlementCode);
    }

    /** @return array<string, bool> */
    public static function entitlementsDiagnostic(): array
    {
        $token = self::accessToken();
        $subject = self::subjectDiagnostic();
        $authorised = !is_wp_error($token)
            && in_array(self::PERMISSION_ENTITLEMENTS_READ, $token['permissions'], true);
        $result = [
            'core_connected' => !is_wp_error($token),
            'entitlements_read_authorized' => $authorised,
            'subject_checked' => !empty($subject['signed_in']),
            'subject_available' => !empty($subject['signed_in']) && !empty($subject['subject_available']),
            'definitions_readable' => false,
        ];

        if (!$authorised) {
            return $result;
        }

        $result['definitions_readable'] = !is_wp_error(self::entitlementDefinitions());

        return $result;
    }

    /** @return array<string, bool|int|string> */
    public static function dailyRewardStatusForCurrentSubject(): array
    {
        return self::dailyRewardRequest('reward_status');
    }

    /** @return array<string, bool|int|string> */
    public static function dailyRewardOffer(): array
    {
        $response = self::authenticatedRoute('reward_offer', 'POST', [], self::PERMISSION_REWARD_CLAIM);
        if (is_wp_error($response)) {
            return self::dailyRewardErrorResult($response);
        }

        return self::normaliseDailyRewardResponse($response['data'], false);
    }

    /** @return array<string, bool|int|string> */
    public static function claimDailyRewardForCurrentSubject(): array
    {
        return self::dailyRewardRequest('reward_claim');
    }

    /** @return array<string, bool|string> */
    public static function dailyRewardDiagnostic(): array
    {
        $connection = self::coreConnectionTest();
        $subject = self::subjectDiagnostic();
        $base = [
            'core_connected' => !is_wp_error($connection),
            'reward_claim_authorized' => !is_wp_error($connection)
                && in_array(self::PERMISSION_REWARD_CLAIM, $connection['permissions'], true),
            'rule_state' => 'transient_error',
            'global_scope_accepted' => false,
            'subject_checked' => !empty($subject['signed_in']),
            'subject_available' => !empty($subject['signed_in']) && !empty($subject['subject_available']),
        ];
        if (is_wp_error($connection)) {
            $base['rule_state'] = self::dailyRewardErrorResult($connection)['state'];

            return $base;
        }

        $response = self::authenticatedRoute(
            'reward_diagnostic',
            'POST',
            [],
            self::PERMISSION_REWARD_CLAIM
        );
        if (is_wp_error($response)) {
            $base['rule_state'] = self::dailyRewardErrorResult($response)['state'];

            return $base;
        }

        $data = $response['data'];
        if (self::configuration()['project_key'] !== self::projectKey($data['project_key'] ?? '')) {
            return $base;
        }

        $base['reward_claim_authorized'] = ($data['reward_claim_authorized'] ?? null) === true;
        $base['rule_state'] = in_array(
            $data['state'] ?? null,
            ['ready', 'rule_unavailable', 'configuration_invalid'],
            true
        ) ? $data['state'] : 'transient_error';
        $base['global_scope_accepted'] = ($data['global_scope_accepted'] ?? null) === true;

        return $base;
    }

    /** @return array<string, bool|int|string> */
    private static function dailyRewardRequest(string $route): array
    {
        $subject = self::currentSubjectId();
        if ($subject === '') {
            return ['state' => 'subject_unavailable'];
        }

        $response = self::authenticatedRoute(
            $route,
            'POST',
            ['body' => ['subject_id' => $subject]],
            self::PERMISSION_REWARD_CLAIM
        );
        if (is_wp_error($response)) {
            return self::dailyRewardErrorResult($response);
        }

        return self::normaliseDailyRewardResponse($response['data'], true);
    }

    /** @param array<string, mixed> $data
     *  @return array<string, bool|int|string>
     */
    private static function normaliseDailyRewardResponse(array $data, bool $requireBalance): array
    {
        if (self::configuration()['project_key'] !== self::projectKey($data['project_key'] ?? '')) {
            return ['state' => 'transient_error'];
        }

        $state = is_string($data['state'] ?? null) ? $data['state'] : '';
        $states = [
            'available',
            'granted',
            'already_claimed',
            'rule_unavailable',
            'permission_denied',
            'subject_unavailable',
            'configuration_invalid',
            'transient_error',
        ];
        if (!in_array($state, $states, true)) {
            return ['state' => 'transient_error'];
        }
        if (!in_array($state, ['available', 'granted', 'already_claimed'], true)) {
            return ['state' => $state];
        }
        if (!is_int($data['amount'] ?? null) || $data['amount'] < 1) {
            return ['state' => 'transient_error'];
        }

        $unit = is_string($data['unit'] ?? null) ? sanitize_text_field($data['unit']) : '';
        if ($unit === '' || ($requireBalance && (!is_int($data['balance'] ?? null) || $data['balance'] < 0))) {
            return ['state' => 'transient_error'];
        }

        $result = ['state' => $state, 'amount' => $data['amount'], 'unit' => $unit];
        if ($requireBalance) {
            $result['balance'] = $data['balance'];
            $result['next_available_at'] = self::isoDatetime($data['next_available_at'] ?? '');
            $result['claimed_now'] = ($data['claimed_now'] ?? null) === true;
        }

        return $result;
    }

    /** @return array{state:string} */
    private static function dailyRewardErrorResult(mixed $error): array
    {
        $code = is_wp_error($error) ? (string) $error->get_error_code() : '';
        if ($code === 'connector_permission_reward_claim_missing') {
            return ['state' => 'permission_denied'];
        }
        if ($code === 'connector_subject_unavailable') {
            return ['state' => 'subject_unavailable'];
        }
        if (in_array($code, [
            'connector_core_url_invalid',
            'connector_client_invalid',
            'connector_project_invalid',
            'connector_secret_missing',
            'connector_secret_required',
            'connector_secret_unavailable',
            'not_configured',
            'schema_not_ready',
        ], true)) {
            return ['state' => 'configuration_invalid'];
        }

        return ['state' => 'transient_error'];
    }

    /**
     * @param array<string, mixed> $args
     * @return array{data:array<string,mixed>,rest_mode:string,token:array<string,mixed>}|\WP_Error
     */
    private static function authenticatedRoute(
        string $route,
        string $method,
        array $args,
        string $requiredPermission
    ): array|\WP_Error {
        $token = self::accessToken();
        if (is_wp_error($token)) {
            return $token;
        }
        if (!in_array($requiredPermission, $token['permissions'], true)) {
            return self::permissionError($requiredPermission, self::diagnosticFrom($token));
        }

        $response = self::requestWithToken($route, $method, $args, $token);
        if (!is_wp_error($response)) {
            return $response + ['token' => $token];
        }

        if ($response->get_error_code() !== 'connector_token_rejected' || empty($token['cached'])) {
            return $response;
        }

        self::clearTokenCache();
        $renewed = self::accessToken();
        if (is_wp_error($renewed)) {
            return $renewed;
        }
        if (!in_array($requiredPermission, $renewed['permissions'], true)) {
            return self::permissionError($requiredPermission, self::diagnosticFrom($renewed));
        }

        $retry = self::requestWithToken($route, $method, $args, $renewed);

        return is_wp_error($retry) ? $retry : $retry + ['token' => $renewed];
    }

    /**
     * @param array<string, mixed> $args
     * @param array<string, mixed> $token
     * @return array{data:array<string,mixed>,rest_mode:string}|\WP_Error
     */
    private static function requestWithToken(string $route, string $method, array $args, array $token): array|\WP_Error
    {
        $headers = is_array($args['headers'] ?? null) ? $args['headers'] : [];
        $headers['Authorization'] = 'Bearer ' . $token['access_token'];
        $args['headers'] = $headers;

        return self::routeRequest($route, $method, $args, (string) $token['rest_mode']);
    }

    /** @return array<string, mixed>|\WP_Error */
    private static function accessToken(): array|\WP_Error
    {
        if (!self::isConfigured()) {
            return self::error(self::configurationErrorCode(), '', self::configurationStage());
        }

        $stored = get_option(self::OPTION, []);
        $stored = is_array($stored) ? $stored : [];
        $cacheKey = self::cacheKey($stored);
        $cached = wp_cache_get($cacheKey, self::CACHE_GROUP);
        if (is_array($cached)
            && is_int($cached['expires_at'] ?? null)
            && $cached['expires_at'] > time() + 5
            && self::validTokenRecord($cached)
        ) {
            $cached['cached'] = true;

            return $cached;
        }

        wp_cache_delete($cacheKey, self::CACHE_GROUP);
        $secret = ConnectorCrypto::decrypt($stored['secret_protected'] ?? '');
        if ($secret instanceof \WP_Error || $secret === '') {
            return self::error('connector_secret_unavailable', '', 'credentials');
        }

        $settings = self::configuration();
        $response = self::routeRequest('token', 'POST', [
            'body' => ['client_id' => $settings['client_id'], 'client_secret' => $secret],
        ]);
        if (is_wp_error($response)) {
            return $response;
        }

        $data = $response['data'];
        $ttl = $data['expires_in'] ?? null;
        $record = [
            'access_token' => $data['access_token'] ?? null,
            'token_type' => $data['token_type'] ?? null,
            'expires_in' => $ttl,
            'expires_at' => is_int($ttl) ? time() + $ttl : 0,
            'permissions' => self::permissions($data['permissions'] ?? []),
            'protocol_version' => $data['protocol_version'] ?? null,
            'rest_mode' => $response['rest_mode'],
            'diagnostic_id' => self::diagnosticFrom($data),
            'cached' => false,
        ];
        if (!self::validTokenResponse($data) || !self::validTokenRecord($record)) {
            return self::error('connector_token_rejected', self::diagnosticFrom($data), 'token');
        }

        $cacheTtl = min(self::TOKEN_MAX_TTL, $ttl - 30);
        wp_cache_set($cacheKey, $record, self::CACHE_GROUP, $cacheTtl);

        return $record;
    }

    /** @param array<string, mixed> $data */
    private static function validTokenResponse(array $data): bool
    {
        if (($data['protocol_version'] ?? null) !== self::PROTOCOL_VERSION
            || ($data['token_type'] ?? null) !== 'Bearer'
            || !is_string($data['access_token'] ?? null)
            || preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $data['access_token']) !== 1
            || !is_int($data['expires_in'] ?? null)
            || $data['expires_in'] < 30
            || $data['expires_in'] > 300
            || !is_array($data['permissions'] ?? null)
        ) {
            return false;
        }

        foreach ($data['permissions'] as $permission) {
            if (!is_string($permission) || !in_array($permission, self::allowedPermissions(), true)) {
                return false;
            }
        }

        return count($data['permissions']) === count(array_unique($data['permissions']));
    }

    /** @param array<string, mixed> $record */
    private static function validTokenRecord(array $record): bool
    {
        return ($record['token_type'] ?? null) === 'Bearer'
            && ($record['protocol_version'] ?? null) === self::PROTOCOL_VERSION
            && is_string($record['access_token'] ?? null)
            && preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $record['access_token']) === 1
            && is_array($record['permissions'] ?? null)
            && in_array($record['rest_mode'] ?? null, [self::REST_MODE_REWRITE, self::REST_MODE_QUERY], true);
    }

    /**
     * @param array<string, mixed> $args
     * @return array{data:array<string,mixed>,rest_mode:string}|\WP_Error
     */
    private static function routeRequest(
        string $route,
        string $method,
        array $args,
        string $knownMode = ''
    ): array|\WP_Error {
        $args += ['timeout' => 10, 'redirection' => 0, 'sslverify' => true];
        $lastError = self::error('connector_route_missing', '', 'route');
        foreach (self::restModes($knownMode) as $restMode) {
            $response = $method === 'POST'
                ? wp_safe_remote_post(self::endpoint($route, $restMode), $args)
                : wp_safe_remote_get(self::endpoint($route, $restMode), $args);
            $data = self::responseData($response, 'route');
            if (!is_wp_error($data)) {
                return ['data' => $data, 'rest_mode' => $restMode];
            }

            $lastError = $data;
            if (!in_array($data->get_error_code(), ['connector_route_missing', 'connector_core_invalid_response'], true)
                || $knownMode !== ''
            ) {
                return $data;
            }
        }

        return $lastError;
    }

    private static function endpoint(string $route, string $restMode): string
    {
        $routes = [
            'token' => 'connector/token',
            'diagnostic' => 'connector/diagnostic',
            'balance' => 'connector/balance',
            'reward_offer' => 'connector/reward/offer',
            'reward_diagnostic' => 'connector/reward/diagnostic',
            'reward_status' => 'connector/reward/status',
            'reward_claim' => 'connector/reward/claim',
            'entitlement_definitions' => 'connector/entitlements/definitions',
            'entitlement' => 'connector/entitlement',
        ];
        $routePath = $routes[$route] ?? '';
        $siteUrl = self::configuration()['core_site_url'];
        if ($restMode === self::REST_MODE_QUERY) {
            return add_query_arg(
                'rest_route',
                '/' . self::REST_NAMESPACE . '/' . $routePath,
                trailingslashit($siteUrl) . 'index.php'
            );
        }

        return trailingslashit($siteUrl) . 'wp-json/' . self::REST_NAMESPACE . '/' . $routePath;
    }

    /** @return list<string> */
    private static function restModes(string $knownMode): array
    {
        return in_array($knownMode, [self::REST_MODE_REWRITE, self::REST_MODE_QUERY], true)
            ? [$knownMode]
            : [self::REST_MODE_REWRITE, self::REST_MODE_QUERY];
    }

    /** @return array<string, mixed>|\WP_Error */
    private static function responseData(mixed $response, string $defaultStage): array|\WP_Error
    {
        if (is_wp_error($response)) {
            return self::error('connector_core_inaccessible', '', $defaultStage);
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status >= 300 && $status <= 399) {
            return self::error('connector_core_redirect_rejected', '', $defaultStage);
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if ($status === 404 || (is_array($data) && ($data['code'] ?? null) === 'rest_no_route')) {
            return self::error('connector_route_missing', self::diagnosticFrom($data), 'route');
        }
        if ($status < 200 || $status > 299) {
            $code = is_array($data) && is_string($data['code'] ?? null) ? $data['code'] : '';
            $safeCodes = [
                'https_required',
                'connector_project_inactive',
                'connector_client_rejected',
                'connector_secret_rejected',
                'connector_permissions_missing',
                'connector_permission_wallet_read_missing',
                'connector_permission_reward_claim_missing',
                'connector_permission_entitlements_read_missing',
                'connector_token_rejected',
                'connector_credentials_missing',
                'schema_not_ready',
                'not_configured',
                'reward_busy',
            ];
            $code = in_array($code, $safeCodes, true) ? $code : 'connector_core_rejected';

            return self::error($code, self::diagnosticFrom($data), self::stageForCode($code, $defaultStage));
        }

        return is_array($data)
            ? $data
            : self::error('connector_core_invalid_response', '', $defaultStage);
    }

    private static function normaliseSiteUrl(mixed $value): string
    {
        $value = self::rawHttpsUrl($value);
        if ($value === '') {
            return '';
        }
        $parts = wp_parse_url($value);
        if (!is_array($parts)) {
            return '';
        }
        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        if (preg_match('#(?:^|/)(?:wp-json|index\.php)(?:/|$)#i', $path) === 1) {
            return '';
        }

        return self::siteUrlFromParts($parts, $path);
    }

    private static function rawHttpsUrl(mixed $value, bool $allowQuery = false): string
    {
        $value = is_string($value) ? esc_url_raw(trim((string) wp_unslash($value))) : '';
        $parts = wp_parse_url($value);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || (!$allowQuery && isset($parts['query']))
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
        ) {
            return '';
        }

        return $value;
    }

    /** @param array<string, mixed> $parts */
    private static function siteUrlFromParts(array $parts, string $path): string
    {
        $siteUrl = 'https://' . strtolower((string) $parts['host']);
        if (isset($parts['port']) && (int) $parts['port'] === 443) {
            $siteUrl .= ':443';
        }
        $path = '/' . ltrim($path, '/');

        return rtrim($siteUrl . ($path === '/' ? '' : $path), '/');
    }

    private static function clientId(mixed $value): string
    {
        $value = is_string($value) ? sanitize_text_field((string) wp_unslash($value)) : '';

        return preg_match('/^tec_[A-Za-z0-9_-]{20,60}$/D', $value) === 1 ? $value : '';
    }

    private static function projectKey(mixed $value): string
    {
        $value = is_string($value) ? strtolower(sanitize_text_field((string) wp_unslash($value))) : '';

        return preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/D', $value) === 1 ? $value : '';
    }

    private static function subjectId(mixed $value): string
    {
        $value = is_string($value) ? trim(sanitize_text_field((string) wp_unslash($value))) : '';

        if ($value === '') {
            return '';
        }

        return function_exists('mb_substr') ? mb_substr($value, 0, 191) : substr($value, 0, 191);
    }

    private static function entitlementCode(mixed $value): string
    {
        $value = is_string($value) ? strtolower(sanitize_text_field((string) wp_unslash($value))) : '';

        return preg_match('/^[a-z0-9][a-z0-9_.-]{1,119}$/D', $value) === 1 ? $value : '';
    }

    /** @return list<string> */
    private static function permissions(mixed $permissions): array
    {
        if (!is_array($permissions)) {
            return [];
        }

        return array_values(array_filter(
            self::allowedPermissions(),
            static fn (string $permission): bool => in_array($permission, $permissions, true)
        ));
    }

    /** @return list<string> */
    private static function allowedPermissions(): array
    {
        return [
            self::PERMISSION_WALLET_READ,
            self::PERMISSION_REWARD_CLAIM,
            self::PERMISSION_ENTITLEMENTS_READ,
        ];
    }

    private static function permissionError(string $permission, string $diagnosticId): \WP_Error
    {
        $codes = [
            self::PERMISSION_WALLET_READ => 'connector_permission_wallet_read_missing',
            self::PERMISSION_REWARD_CLAIM => 'connector_permission_reward_claim_missing',
            self::PERMISSION_ENTITLEMENTS_READ => 'connector_permission_entitlements_read_missing',
        ];

        return self::error($codes[$permission] ?? 'connector_permissions_missing', $diagnosticId, 'permission');
    }

    /** @return array<string, string> */
    private static function successfulSteps(string $restMode): array
    {
        return [
            'url' => 'valid',
            'rest' => $restMode === self::REST_MODE_QUERY ? 'rest_route' : 'wp-json',
            'route' => 'reachable',
            'core' => 'identified',
            'protocol' => 'compatible',
            'credentials' => 'accepted',
            'permission' => 'wallet.read',
            'token' => 'received',
        ];
    }

    private static function stageForCode(string $code, string $fallback): string
    {
        $stages = [
            'connector_client_rejected' => 'credentials',
            'connector_secret_rejected' => 'credentials',
            'connector_credentials_missing' => 'credentials',
            'connector_project_inactive' => 'credentials',
            'connector_permissions_missing' => 'permission',
            'connector_permission_wallet_read_missing' => 'permission',
            'connector_permission_reward_claim_missing' => 'permission',
            'connector_permission_entitlements_read_missing' => 'permission',
            'connector_token_rejected' => 'token',
        ];

        return $stages[$code] ?? $fallback;
    }

    private static function isoDatetime(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' && strtotime($value) !== false ? $value : '';
    }

    /** @param array<string, mixed>|null $data
     *  @param array<string, mixed> $fallback
     */
    private static function diagnosticFrom(?array $data, array $fallback = []): string
    {
        $id = is_array($data)
            ? (string) ($data['diagnostic_id'] ?? ($data['data']['diagnostic_id'] ?? ''))
            : '';
        if ($id === '') {
            $id = (string) ($fallback['diagnostic_id'] ?? '');
        }

        return preg_match('/^[a-f0-9-]{16,64}$/iD', $id) === 1 ? $id : wp_generate_uuid4();
    }

    /** @param array<string, mixed> $stored */
    private static function secretState(array $stored): string
    {
        $protected = $stored['secret_protected'] ?? null;
        if (!is_string($protected) || $protected === '') {
            return 'required';
        }
        $secret = ConnectorCrypto::decrypt($protected);

        return $secret instanceof \WP_Error || $secret === '' ? 'required' : 'saved';
    }

    private static function configurationErrorCode(): string
    {
        $settings = self::configuration();
        if ($settings['core_site_url'] === '') {
            return 'connector_core_url_invalid';
        }
        if ($settings['client_id'] === '') {
            return 'connector_client_invalid';
        }
        if ($settings['project_key'] === '') {
            return 'connector_project_invalid';
        }

        return 'connector_secret_required';
    }

    private static function configurationStage(): string
    {
        return self::configurationErrorCode() === 'connector_core_url_invalid' ? 'url' : 'credentials';
    }

    /** @param array<string, mixed> $stored */
    private static function cacheKey(array $stored): string
    {
        $material = implode('|', [
            (string) ($stored['core_site_url'] ?? $stored['core_url'] ?? ''),
            (string) ($stored['client_id'] ?? ''),
            (string) ($stored['project_key'] ?? ''),
            (string) ($stored['secret_protected'] ?? ''),
        ]);

        return 'bearer-' . hash('sha256', $material);
    }

    private static function clearTokenCache(): void
    {
        $stored = get_option(self::OPTION, []);
        wp_cache_delete(self::cacheKey(is_array($stored) ? $stored : []), self::CACHE_GROUP);
    }

    private static function error(string $code, string $diagnosticId = '', string $stage = ''): \WP_Error
    {
        return new \WP_Error(
            $code,
            __('Le connecteur ne peut pas terminer cette opération.', 'faluss-platform'),
            [
                'diagnostic_id' => $diagnosticId !== '' ? $diagnosticId : wp_generate_uuid4(),
                'stage' => $stage,
            ]
        );
    }
}
