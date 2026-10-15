<?php

declare(strict_types=1);

namespace Faluss\Platform\MeStudio;

use Faluss\Platform\Link\Import\LinkImportBatch;
use Faluss\Platform\Link\Import\LinkImportParser;
use Faluss\Platform\Link\Import\LinkImportValidator;
use Faluss\Platform\Link\LinkStudioContract;
use InvalidArgumentException;

/** Private Studio AJAX adapter, using the same authentication boundary as editing. */
final class LinkImportActions
{
    public static function register(): void
    {
        add_action('wp_ajax_faluss_studio_link_import_preview', [self::class, 'preview']);
    }

    public static function preview(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_send_json_error(['code' => 'method_not_allowed'], 405);
        }
        if (!OnboardingV3::enabled() || !is_user_logged_in()
            || !check_ajax_referer('faluss_studio_link_import_preview', 'nonce', false)) {
            wp_send_json_error(['code' => 'forbidden'], 403);
        }
        if (array_diff(array_keys($_POST), ['action', 'nonce', 'source', 'format', 'rows'])) {
            wp_send_json_error(['code' => 'unexpected_field'], 422);
        }
        $state = LinkStudioContract::state();
        if (is_wp_error($state)) {
            wp_send_json_error(['code' => 'unavailable'], 403);
        }
        try {
            if (isset($_POST['rows'])) {
                if (isset($_POST['source']) || !is_string($_POST['rows']) || strlen($_POST['rows']) > 1048576) {
                    throw new InvalidArgumentException('invalid_rows');
                }
                $decoded = json_decode(wp_unslash($_POST['rows']), true, 16);
                if (!is_array($decoded)) { throw new InvalidArgumentException('invalid_rows'); }
                $rows = LinkImportBatch::rows($decoded);
            } else {
                if (!is_string($_POST['source'] ?? null) || (isset($_POST['format']) && !is_string($_POST['format']))) {
                    throw new InvalidArgumentException('invalid_source');
                }
                $rows = (new LinkImportParser())->parse(wp_unslash($_POST['source']), $_POST['format'] ?? 'auto');
            }
            $urls = [];
            foreach ((array) ($state['blocks'] ?? []) as $block) {
                if (is_array($block) && ($block['type'] ?? '') === 'link') { $urls[] = (string) ($block['url'] ?? ''); }
            }
            wp_send_json_success([
                'rows' => (new LinkImportValidator())->review($rows, $urls),
                'capacity' => max(0, 32 - count((array) ($state['blocks'] ?? []))),
            ]);
        } catch (InvalidArgumentException $error) {
            wp_send_json_error(['code' => $error->getMessage()], $error->getMessage() === 'too_large' ? 413 : 422);
        }
    }
}
