<?php
declare(strict_types=1);

// Isolated adapter, NOT a WordPress installation. Services, SQL, schemas and GD are real.
// Only a runner-created local Unix socket and the dedicated disposable database are accepted.
$fixtureRoot = getenv('FANS_EDITORIAL_FIXTURE');
if (!is_string($fixtureRoot) || !preg_match('#^/var/tmp/fans-editorial-[a-zA-Z0-9_]+$#D', $fixtureRoot)
    || !is_file($fixtureRoot . '/isolated-fixture')) { throw new RuntimeException('Isolated runner required'); }
require dirname(__DIR__, 4) . '/vendor/autoload.php';
define('FALUSS_PLATFORM_ROLE', 'fans');
define('FALUSS_PLATFORM_VERSION', 'isolated-editorial-test');
foreach (['SSO', 'CREATOR_PROFILES', 'EDITORIAL', 'IMAGES', 'IMAGE_DELIVERY'] as $flag) { define('FALUSS_PLATFORM_FANS_' . $flag, true); }
if (getenv('FANS_PUBLICATION_FIXTURE') === '1') { define('FALUSS_PLATFORM_FANS_TEXT_PUBLICATIONS', true); }
define('FALUSS_FANS_SSO_CLIENT_ID', 'isolated-test');
define('FALUSS_FANS_SSO_CLIENT_SECRET', str_repeat('s', 43));
define('ABSPATH', dirname(__DIR__, 4));
define('WP_CONTENT_DIR', dirname(__DIR__, 4) . '/assets');
define('FALUSS_FANS_IMAGE_PRIVATE_ROOT', $fixtureRoot . '/images');
define('FALUSS_FANS_IMAGE_STORAGE_ATTESTED', true);

final class EditorialSqlAdapter
{
    public string $prefix = 'test_';
    public string $last_error = '';
    public PDO $pdo;
    public function __construct(string $root) {
        $this->pdo = new PDO('mysql:unix_socket=' . $root . '/sql.sock;dbname=fans_editorial_test;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
    public function prepare(string $sql, mixed ...$args): string {
        $index = 0;
        return preg_replace_callback('/%[sd]/', function ($m) use ($args, &$index) {
            $v = $args[$index++]; return $m[0] === '%d' ? (string) (int) $v : $this->pdo->quote((string) $v);
        }, $sql);
    }
    public function get_charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin'; }
    public function suppress_errors(bool $v = true): bool { return false; }
    public function query(string $sql): int|false {
        $this->last_error = '';
        try { return $this->pdo->exec($sql); } catch (PDOException $e) { $this->last_error = $e->getMessage(); return false; }
    }
    public function get_results(string $sql, mixed $output = null): ?array {
        $this->last_error = '';
        try { return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); } catch (PDOException $e) { $this->last_error = $e->getMessage(); return null; }
    }
    public function get_row(string $sql, mixed $output = null): ?array { return $this->get_results($sql)[0] ?? null; }
    public function get_var(string $sql): mixed { $row = $this->get_row($sql); return $row === null ? null : reset($row); }
}
$wpdb = new EditorialSqlAdapter($fixtureRoot);
function get_option(string $key, mixed $default = false): mixed { global $wpdb; return $wpdb->get_var($wpdb->prepare('SELECT value FROM fixture_options WHERE name=%s', $key)) ?? $default; }
function update_option(string $key, mixed $value, bool $autoload = false): bool { global $wpdb; return $wpdb->query($wpdb->prepare('REPLACE INTO fixture_options(name,value) VALUES(%s,%s)', $key, $value)) !== false; }
$fixtureUser = (int) ($_COOKIE['fixture_user'] ?? 17);
function get_current_user_id(): int { return $GLOBALS['fixtureUser']; }
function is_user_logged_in(): bool { return get_current_user_id() > 0; }
function current_user_can(string $cap): bool { return $cap === 'manage_options' && get_current_user_id() === 1; }
class WP_User { public function __construct(public int $ID, public array $roles = ['subscriber']) {} }
function get_userdata(int $id): WP_User|false { return $id > 0 ? new WP_User($id, $id === 1 ? ['administrator'] : ['subscriber']) : false; }
function wp_parse_url(string $url): array|false { return parse_url($url); }
function home_url(string $path = ''): string { return (getenv('FANS_EDITORIAL_HTTP') ?: 'https://editorial.example.test') . $path; }
function wp_verify_nonce(string $nonce, string $action): int|false { return $nonce === 'fixture-' . $action ? 1 : false; }
class WP_Error { public function __construct(public string $code, public string $message = '', public array $data = []) {} public function get_error_code(): string { return $this->code; } public function get_error_data(): array { return $this->data; } }
class WP_HTTP_Response {
    private array $headers = [];
    public function __construct(private mixed $data = null, private int $status = 200) {}
    public function get_data(): mixed { return $this->data; }
    public function get_status(): int { return $this->status; }
    public function get_headers(): array { return $this->headers; }
    public function header(string $name, string $value): void { $this->headers[$name] = $value; }
}
class WP_REST_Response extends WP_HTTP_Response {}
class WP_REST_Request {
    public array $params = []; public array $query = []; public array $headers = []; public ?array $json = null;
    public array $files = []; public array $body = [];
    public function __construct(public string $method = 'GET', public string $route = '') {}
    public function get_route(): string { return $this->route; }
    public function get_method(): string { return $this->method; }
    public function get_body(): string { return $this->json === null ? '' : (string) json_encode($this->json); }
    public function get_param(string $key): mixed { return $this->params[$key] ?? $this->query[$key] ?? $this->json[$key] ?? null; }
    public function get_header(string $key): string { return $this->headers[strtolower($key)] ?? ''; }
    public function set_header(string $key, string $value): void { $this->headers[strtolower($key)] = $value; }
    public function set_body(string $body): void { $this->json = json_decode($body, true); }
    public function set_query_params(array $query): void { $this->query = $query; }
    public function get_query_params(): array { return $this->query; }
    public function get_body_params(): array { return $this->body; }
    public function get_json_params(): ?array { return $this->json; }
    public function get_file_params(): array { return $this->files; }
    public function set_file_params(array $files): void { $this->files = $files; }
}
function wp_generate_uuid4(): string { $hex = bin2hex(random_bytes(16)); return substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-8'.substr($hex,17,3).'-'.substr($hex,20); }
function register_rest_route(string $namespace, string $route, array $args): void {
    foreach (isset($args['methods']) ? [$args] : $args as $arg) { $GLOBALS['fixtureRoutes'][] = [$namespace . $route, $arg]; }
}
function rest_do_request(WP_REST_Request $r): WP_REST_Response {
    foreach ($GLOBALS['fixtureRoutes'] as [$path, $route]) {
        if ($route['methods'] !== $r->method || !preg_match('#^/' . $path . '$#D', $r->route, $match)) { continue; }
        $r->params = array_filter($match, 'is_string', ARRAY_FILTER_USE_KEY);
        if (!$route['permission_callback']($r)) { return new WP_REST_Response(['code' => 'forbidden'], 403); }
        $value = $route['callback']($r);
        return $value instanceof WP_Error ? new WP_REST_Response(['code' => $value->code], $value->data['status']) : $value;
    }
    return new WP_REST_Response(['code' => 'no_route'], 404);
}
\Faluss\Platform\Fans\Profiles\CreatorProfileRest::routes();
\Faluss\Platform\Fans\Profiles\EditorialRest::routes();
\Faluss\Platform\Fans\Images\ImageRest::routes();
\Faluss\Platform\Fans\Publications\TextPublicationRest::routes();
\Faluss\Platform\Fans\Publications\PublicationImageRest::routes();
