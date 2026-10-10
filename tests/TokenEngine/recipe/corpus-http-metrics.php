<?php

declare(strict_types=1);

/** Disposable CLI diagnostics: no URL, body, identity, signature or error message. */
function corpus_recipe_http_metrics(string $inputFile): void
{
    if (!defined('WP_CLI') || !WP_CLI || !is_file($inputFile) || is_link($inputFile)
        || (fileperms($inputFile) & 0777) !== 0600) { throw new RuntimeException('Private CLI input required.'); }
    $started = 0.0; $metrics = [];
    add_filter('http_request_args',static function (array $args) use (&$started): array {
        $started = microtime(true); return $args;
    });
    add_action('http_api_debug',static function ($response) use (&$started,&$metrics,$inputFile): void {
        $error = is_wp_error($response);
        $metrics[] = ['elapsed_ms' => round((microtime(true)-$started)*1000,3),
            'status' => $error ? 0 : wp_remote_retrieve_response_code($response),
            'failure' => $error ? (str_contains(strtolower($response->get_error_message()),'timed out') ? 'timeout' : 'transport') : 'none'];
        if (count($metrics) > 16) { throw new RuntimeException('Private metric budget exceeded.'); }
        $path = $inputFile . '.http-metrics';
        file_put_contents($path,wp_json_encode($metrics,JSON_THROW_ON_ERROR),LOCK_EX); chmod($path,0600);
    });
}
