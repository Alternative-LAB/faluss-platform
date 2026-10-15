<?php

declare(strict_types=1);

namespace Faluss\Platform\Link {
    // The adapter test owns only this boundary; transaction behavior uses the real Link runtime.
    final class LinkStudioContract
    {
        public static function state(): array|\WP_Error
        {
            return $GLOBALS['import_owner'] ? ['blocks' => [['type' => 'link', 'url' => 'https://example.com/existing']]] : new \WP_Error();
        }
    }
}

namespace {
    require dirname(__DIR__, 2) . '/vendor/autoload.php';
    define('FALUSS_PLATFORM_ONBOARDING_V3', true);
    class WP_Error {}
    final class ImportResponse extends RuntimeException
    {
        public function __construct(public array $payload, public int $status, public bool $success) { parent::__construct(); }
    }
    function is_user_logged_in(): bool { return $GLOBALS['import_logged_in']; }
    function check_ajax_referer($action, $field, $die): bool { return $action === 'faluss_studio_link_import_preview' && ($_POST[$field] ?? '') === 'valid-nonce'; }
    function is_wp_error($value): bool { return $value instanceof WP_Error; }
    function wp_unslash($value) { return is_array($value) ? array_map('wp_unslash', $value) : stripslashes($value); }
    function wp_send_json_error($payload, $status = 200): never { throw new ImportResponse($payload, $status, false); }
    function wp_send_json_success($payload): never { throw new ImportResponse($payload, 200, true); }
    function verifyImport($condition, $message): void { if (!$condition) { throw new RuntimeException($message); } }
    function requestImport(array $fields, string $method = 'POST', bool $loggedIn = true, bool $owner = true): ImportResponse
    {
        $_POST = array_map(static fn ($value) => is_string($value) ? addslashes($value) : $value, $fields);
        $_SERVER['REQUEST_METHOD'] = $method;
        $GLOBALS['import_logged_in'] = $loggedIn; $GLOBALS['import_owner'] = $owner;
        try { \Faluss\Platform\MeStudio\LinkImportActions::preview(); } catch (ImportResponse $response) { return $response; }
        throw new RuntimeException('No JSON response');
    }
    $base = ['action' => 'faluss_studio_link_import_preview', 'nonce' => 'valid-nonce'];
    $valid = $base + ['source' => "Titre,URL\nMon site,https://example.com/new", 'format' => 'csv'];
    verifyImport(requestImport($valid, 'GET')->status === 405, 'GET must never parse or write');
    verifyImport(requestImport($valid, 'POST', false)->status === 403, 'Anonymous access');
    verifyImport(requestImport(array_replace($valid, ['nonce' => 'bad']))->status === 403, 'Invalid nonce');
    verifyImport(requestImport($valid, 'POST', true, false)->status === 403, 'No current owner');
    verifyImport(requestImport($valid + ['faluss_id' => 'someone-else'])->status === 422, 'Cannot select another member');
    verifyImport(requestImport(array_replace($valid, ['source' => []]))->status === 422, 'Array source rejected');
    verifyImport(requestImport(array_replace($valid, ['source' => str_repeat('x', 524289)]))->status === 413, 'Source size enforced');
    verifyImport(requestImport($base + ['rows' => '{broken'])->status === 422, 'Malformed rows JSON');
    verifyImport(requestImport($base + ['rows' => json_encode([['label' => 'Bad', 'url' => 'https://example.com', 'selected' => true]])])->status === 422, 'Forged row status fields');
    $response = requestImport($valid);
    verifyImport($response->success && $response->payload['capacity'] === 31 && $response->payload['rows'][0]['status'] === 'ready', 'Valid CSV preview');
    $response = requestImport($base + ['source' => '[{"title":"L\'art","url":"https://example.com/new"}]', 'format' => 'json']);
    verifyImport($response->success && $response->payload['rows'][0]['label'] === "L'art", 'Unslash raw source once');
    $response = requestImport($base + ['rows' => json_encode([
        ['label' => 'Existing', 'url' => 'https://EXAMPLE.com/existing?utm_source=export', 'order' => 0],
        ['label' => 'Unsafe', 'url' => 'javascript:alert(1)', 'order' => 1],
        ['label' => 'Corrected', 'url' => 'example.com/new', 'order' => 2],
    ])]);
    verifyImport($response->success && array_column($response->payload['rows'], 'status') === ['duplicate', 'invalid', 'ready'], 'Review is authoritative per row');
    echo "Link import AJAX permissions, validation, raw input and preview: OK\n";
}
