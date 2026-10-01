<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Sso;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/WordPressStubs.php';

final class FansSsoTestRedirect extends \RuntimeException
{
    public function __construct(public string $location) { parent::__construct('Test redirect'); }
}

function admin_url(string $path): string { return home_url('/wp-admin/' . $path); }
function plugins_url(string $path, string $plugin): string { return home_url('/wp-content/plugins/faluss-platform/' . $path); }
function wp_enqueue_style(string $handle, string $url, array $deps, string $version): void { $GLOBALS['fans_sso_styles'][$handle] = [$url, $deps, $version]; }
function esc_url(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function esc_attr(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function esc_html__(string $value, string $domain): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function wp_nonce_field(string $action, string $name, bool $referer, bool $echo): string { return '<input type="hidden" name="' . $name . '" value="fixture-nonce">'; }
function wp_unslash(string $value): string { return $value; }
function sanitize_text_field(string $value): string { return $value; }
function wp_verify_nonce(string $value, string $action): int|false { return $value === 'fixture-nonce' ? 1 : false; }
function setcookie(string $name, string $value, array $options): bool { $GLOBALS['fans_return_cookies'][$name] = [$value, $options]; return true; }
function wp_redirect(string $location, int $status = 302, string $by = ''): never { throw new FansSsoTestRedirect($location); }
function wp_safe_redirect(string $location): never { throw new FansSsoTestRedirect($location); }
function add_query_arg(array|string $args, string $value, ?string $url = null): string
{
    return is_array($args) ? $value . '?' . http_build_query($args) : $url . '?' . http_build_query([$args => $value]);
}
function get_query_var(string $name): string { return '1'; }
function wp_set_current_user(int $id): void { $GLOBALS['fans_return_session'] = $id; }
function wp_set_auth_cookie(int $id, bool $remember, bool $secure): void { $GLOBALS['fans_return_auth'][] = [$id, $remember, $secure]; }
function is_ssl(): bool { return true; }
function do_action(string $name, mixed ...$args): void {}

final class FansSsoReturnFlowTest extends TestCase
{
    protected function setUp(): void
    {
        fans_sso_reset();
        if (!defined('FALUSS_PLATFORM_VERSION')) { define('FALUSS_PLATFORM_VERSION', 'test'); }
        if (!defined('FALUSS_FANS_SSO_CLIENT_ID')) {
            define('FALUSS_FANS_SSO_CLIENT_ID', 'fans-test-client');
            define('FALUSS_FANS_SSO_CLIENT_SECRET', str_repeat('s', 43));
        }
        $GLOBALS['fans_return_cookies'] = [];
        $GLOBALS['fans_return_auth'] = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    /** @return array<string,array{string}> */
    public static function destinations(): array
    {
        return [
            'create' => ['/faluss-fans/creator/creer'],
            'selected text' => ['/faluss-fans/creator/creer?publication=123e4567-e89b-42d3-a456-426614174000'],
        ];
    }

    public function testButtonKeepsNativePostFieldsAndAccessibleText(): void
    {
        $html = FansSsoService::button(['return_to' => 'https://evil.example/']);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $form = $dom->getElementsByTagName('form')->item(0);
        self::assertSame('post', $form->getAttribute('method'));
        self::assertSame(admin_url('admin-post.php'), $form->getAttribute('action'));
        $fields = [];
        foreach ($dom->getElementsByTagName('input') as $input) {
            self::assertSame('hidden', $input->getAttribute('type'));
            $fields[$input->getAttribute('name')] = $input->getAttribute('value');
        }
        self::assertSame(['action' => FansSsoService::START_ACTION, 'faluss_fans_sso_nonce' => 'fixture-nonce', 'faluss_fans_return_to' => '/app'], $fields);
        $button = $dom->getElementsByTagName('button')->item(0);
        self::assertSame('submit', $button->getAttribute('type'));
        self::assertSame('Rejoindre avec Faluss Identity', $button->textContent);
        $icon = $dom->getElementsByTagName('img')->item(0);
        self::assertSame('', $icon->getAttribute('alt'));
        self::assertSame('true', $icon->getAttribute('aria-hidden'));
        self::assertStringEndsWith('/assets/images/apps/faluss-hub.png', $icon->getAttribute('src'));
        self::assertStringNotContainsString('onclick', $html);
    }

    public function testStyleIsQueuedBeforeShortcodesInTheThemeBody(): void
    {
        FansSsoService::register();
        self::assertSame([FansSsoService::class, 'enqueueButtonStyle'], $GLOBALS['fans_sso_hooks']['wp_enqueue_scripts']);
        FansSsoService::enqueueButtonStyle();
        self::assertStringEndsWith('/assets/fans-sso-button.css', $GLOBALS['fans_sso_styles']['faluss-fans-sso-button'][0]);
    }

    #[DataProvider('destinations')]
    public function testStartAndCallbackReturnOnlyAfterTheExistingSsoProof(string $target): void
    {
        $button = FansSsoService::button(['return_to' => $target]);
        self::assertStringContainsString('name="faluss_fans_return_to" value="' . $target . '"', $button);
        $state = $this->start($target);
        self::assertSame($target, FansSsoReturn::open($_COOKIE[FansSsoReturn::COOKIE], $state));
        foreach ($GLOBALS['fans_return_cookies'] as [$value, $options]) {
            self::assertTrue($options['secure']);
            self::assertTrue($options['httponly']);
            self::assertSame('Lax', $options['samesite']);
        }
        $this->readyCallback($state);
        self::assertSame($target, $this->redirect(fn () => FansSsoService::callback()));
        self::assertSame([[51, false, true]], $GLOBALS['fans_return_auth']);
        self::assertArrayNotHasKey(FansSsoReturn::COOKIE, $_COOKIE);
        self::assertSame('', $GLOBALS['fans_return_cookies'][FansSsoReturn::COOKIE][0]);
        self::assertStringContainsString('faluss_fans_sso=invalid', $this->redirect(fn () => FansSsoService::callback()));
        self::assertCount(1, $GLOBALS['fans_return_auth']);
    }

    public function testFailureAndMissingDestinationNeverBypassSso(): void
    {
        $state = $this->start('/faluss-fans/fan/espace');
        $this->readyCallback($state);
        $GLOBALS['fans_sso_remote_queue'] = [['code' => 503, 'body' => '{}']];
        self::assertStringContainsString('faluss_fans_sso=invalid', $this->redirect(fn () => FansSsoService::callback()));
        self::assertSame([], $GLOBALS['fans_return_auth']);
        self::assertArrayNotHasKey(FansSsoReturn::COOKIE, $_COOKIE);

        $state = $this->start('https://evil.example');
        $this->readyCallback($state);
        self::assertSame(home_url('/'), $this->redirect(fn () => FansSsoService::callback()));
    }

    public function testInvalidNonceAndPrivilegedAccountDoNotStartLogin(): void
    {
        $_POST = ['faluss_fans_sso_nonce' => 'wrong'];
        self::assertStringContainsString('faluss_fans_sso=invalid', $this->redirect(fn () => FansSsoService::start()));
        self::assertSame([], $GLOBALS['fans_return_cookies']);
        $GLOBALS['fans_sso_logged_in'] = true;
        $GLOBALS['fans_sso_current_user'] = 1;
        $GLOBALS['fans_sso_users'][1] = new \WP_User(1, ['administrator']);
        self::assertSame('', FansSsoService::button(['return_to' => '/faluss-fans/fan/espace']));
    }

    private function start(string $target): string
    {
        $_POST = ['faluss_fans_sso_nonce' => 'fixture-nonce', 'faluss_fans_return_to' => $target];
        $redirect = $this->redirect(fn () => FansSsoService::start());
        self::assertStringStartsWith('https://faluss.me/oauth/authorize?', $redirect);
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $args);
        self::assertSame('https://fans.example.test/faluss-fans/sso/callback', $args['redirect_uri']);
        self::assertArrayNotHasKey('faluss_fans_return_to', $args);
        self::assertArrayNotHasKey('publication', $args);
        self::assertStringNotContainsString('123e4567', $redirect);
        foreach ($GLOBALS['fans_return_cookies'] as $name => [$value]) { $_COOKIE[$name] = $value; }
        return $args['state'];
    }

    private function readyCallback(string $state): void
    {
        $GLOBALS['wpdb']->stateRow = ['id' => 9, 'flow_mode' => 'login', 'wp_user_id' => null, 'expires_at' => '2099-01-01 00:00:00', 'consumed_at' => null];
        $GLOBALS['wpdb']->linkedId = 51;
        $GLOBALS['fans_sso_users'][51] = new \WP_User(51);
        $GLOBALS['fans_sso_remote_queue'] = [['code' => 200, 'body' => json_encode(['faluss_id' => '11111111-1111-4111-8111-111111111111', 'scope' => 'identity.basic identity.email', 'email' => 'fixture@example.test'])]];
        $_GET = ['state' => $state, 'code' => str_repeat('c', 43)];
    }

    private function redirect(callable $action): string
    {
        try { $action(); } catch (FansSsoTestRedirect $redirect) { return $redirect->location; }
        self::fail('Expected a redirect');
    }
}
