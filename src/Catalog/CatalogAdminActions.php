<?php

declare(strict_types=1);

namespace Faluss\Platform\Catalog;

final class CatalogAdminActions
{
    public function __construct(private readonly CatalogEntitlementProvider $entitlements)
    {
    }

    public function boot(): void
    {
        add_action('admin_post_faluss_catalog_create_theme', [$this, 'create']);
        add_action('admin_post_faluss_catalog_update_theme', [$this, 'update']);
        add_action('admin_post_faluss_catalog_delete_theme', [$this, 'delete']);
    }

    public function create(): void
    {
        $this->guard('faluss_catalog_create_theme');
        $editor = new CatalogThemeEditor($this->stored(), $this->entitlements->available());
        $themes = $editor->create($this->input());

        if ($themes === false) {
            $this->redirect('invalid');
        }

        update_option(CatalogThemeReader::OPTION, $themes, false);
        $this->redirect('created');
    }

    public function update(): void
    {
        $this->guard('faluss_catalog_update_theme');
        $stored = $this->stored();
        $slug = $this->slug();
        $before = (new CatalogThemeReader($stored))->getTheme($slug);
        $editor = new CatalogThemeEditor($stored, $this->entitlements->available());
        $themes = $editor->update($slug, $this->input());

        if ($themes === false) {
            $this->redirect('invalid');
        }

        update_option(CatalogThemeReader::OPTION, $themes, false);
        if (is_array($before) && !empty($before['active']) && empty($themes[$slug]['active'])) {
            do_action('faluss_catalog_theme_deactivated', $slug);
        }

        $this->redirect('updated');
    }

    public function delete(): void
    {
        $this->guard('faluss_catalog_delete_theme');
        $slug = $this->slug();
        $editor = new CatalogThemeEditor($this->stored(), false);
        $themes = $editor->delete($slug);

        if ($themes === false) {
            $this->redirect('invalid');
        }

        update_option(CatalogThemeReader::OPTION, $themes, false);
        do_action('faluss_catalog_theme_deactivated', $slug);
        $this->redirect('deleted');
    }

    private function guard(string $action): void
    {
        $nonce = $_POST['faluss_catalog_nonce'] ?? null;
        if (!current_user_can('manage_options')
            || !is_string($nonce)
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($nonce)), $action)
        ) {
            wp_die(esc_html__('Accès refusé.', 'faluss-platform'));
        }
    }

    /** @return array<mixed> */
    private function stored(): array
    {
        $stored = get_option(CatalogThemeReader::OPTION, []);

        return is_array($stored) ? $stored : [];
    }

    /** @return array<string, mixed> */
    private function input(): array
    {
        return wp_unslash($_POST);
    }

    private function slug(): string
    {
        $slug = $_POST['theme_slug'] ?? null;

        return is_string($slug) ? sanitize_title(wp_unslash($slug)) : '';
    }

    private function redirect(string $notice): never
    {
        $url = add_query_arg(
            'faluss_catalog_notice',
            sanitize_key($notice),
            admin_url('admin.php?page=faluss-platform-catalog')
        );
        wp_safe_redirect($url);
        exit;
    }
}
