"""Disposable WordPress/MariaDB/Chrome Fans UI recipe. Run as WSL root."""

import http.client
import http.server
import json
import os
import secrets
import shutil
import socket
import ssl
import subprocess
import tempfile
import threading
import time
from pathlib import Path

REPO = Path(__file__).resolve().parents[4]
CORE = Path('/var/tmp/faluss-v3-wp/wordpress')
WP_CLI = Path('/var/tmp/faluss-v3-wp/wp-cli.phar')
DB = 'faluss_fans_ui_v2_recipe'
DB_USER = 'faluss_ui_v2_recipe'
HTTP_PORT = 8767
HTTPS_PORT = 8444
NODE = os.environ.get('FANS_UI_NODE', '/mnt/c/Users/dylan/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node.exe')
NODE_PATH = os.environ.get('FANS_UI_NODE_PATH', r'C:\Users\dylan\.cache\codex-runtimes\codex-primary-runtime\dependencies\node\node_modules')


def run(*args, **kwargs):
    result = subprocess.run(args, check=False, text=True, **kwargs)
    if result.returncode != 0:
        raise RuntimeError('Recipe command failed: ' + (result.stderr or '').strip()[:500])
    return result


def sql(statement):
    return run('mariadb', '-e', statement, capture_output=True).stdout


class Proxy(http.server.BaseHTTPRequestHandler):
    def log_message(self, *_args):
        pass

    def request(self):
        length = int(self.headers.get('Content-Length', '0'))
        body = self.rfile.read(length) if length else None
        conn = http.client.HTTPConnection('127.0.0.1', HTTP_PORT, timeout=40)
        headers = {key: value for key, value in self.headers.items()
                   if key.lower() not in {'connection', 'content-length', 'transfer-encoding'}}
        conn.request(self.command, self.path, body, headers)
        response = conn.getresponse()
        data = response.read()
        self.send_response(response.status)
        for key, value in response.getheaders():
            if key.lower() not in {'connection', 'content-length', 'transfer-encoding', 'server', 'date'}:
                self.send_header(key, value)
        self.send_header('Content-Length', str(len(data)))
        self.end_headers()
        if self.command != 'HEAD':
            try:
                self.wfile.write(data)
            except (BrokenPipeError, ConnectionResetError):
                pass
        conn.close()

    do_GET = request
    do_POST = request
    do_HEAD = request


def main():
    if os.geteuid() != 0 or not CORE.is_dir() or not WP_CLI.is_file():
        raise RuntimeError('Run as WSL root with the pinned local WordPress assets.')
    if sql(f"SHOW DATABASES LIKE '{DB}'").strip():
        raise RuntimeError('The named disposable database already exists.')
    for port in (HTTP_PORT, HTTPS_PORT):
        with socket.socket() as sock:
            if sock.connect_ex(('127.0.0.1', port)) == 0:
                raise RuntimeError(f'Local port {port} is in use.')

    root = Path(tempfile.mkdtemp(prefix='faluss-ui-v2-', dir='/var/tmp'))
    if root.resolve().parent != Path('/var/tmp'):
        raise RuntimeError('Unexpected disposable root.')
    db_created = user_created = False
    php_server = None
    proxy = None
    try:
        password = secrets.token_hex(24)
        sql(f'CREATE DATABASE `{DB}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')
        db_created = True
        sql(f"CREATE USER '{DB_USER}'@'localhost' IDENTIFIED BY '{password}'")
        user_created = True
        sql(f"GRANT ALL ON `{DB}`.* TO '{DB_USER}'@'localhost'")
        def ignore(directory, _entries):
            if Path(directory) == CORE:
                return {'wp-config.php'}
            if Path(directory).name == 'wp-content':
                return {'uploads', 'mu-plugins'}
            if Path(directory).name == 'plugins':
                return {'faluss-platform', 'elementor', 'akismet'}
            return set()

        shutil.copytree(CORE, root, dirs_exist_ok=True, symlinks=True, ignore=ignore)
        (root / 'wp-content/plugins/faluss-platform').symlink_to(REPO, target_is_directory=True)
        (root / 'wp-content/mu-plugins').mkdir(exist_ok=True)
        (root / 'wp-content/mu-plugins/offline.php').write_text(
            "<?php add_filter('pre_http_request', static fn () => new WP_Error('offline', 'Local recipe only.'));\n"
        )
        wp = ['php', str(WP_CLI), '--allow-root', '--path=' + str(root)]
        run(*wp, 'config', 'create', '--dbname=' + DB, '--dbuser=' + DB_USER,
            '--dbpass=' + password, '--dbhost=localhost', '--skip-check', capture_output=True)
        for name, value, raw in [
            ('FALUSS_PLATFORM_ROLE', 'fans', False),
            ('FALUSS_PLATFORM_FANS_SSO', 'true', True),
            ('FALUSS_PLATFORM_FANS_CREATOR_PROFILES', 'true', True),
            ('FALUSS_PLATFORM_FANS_UI', 'true', True),
            ('FALUSS_PLATFORM_FANS_FOLLOWERS', 'false', True),
            ('FALUSS_PLATFORM_FANS_STORE_CATALOG', 'false', True),
            ('WP_AUTO_UPDATE_CORE', 'false', True),
            ('DISABLE_WP_CRON', 'true', True),
            ('FALUSS_FANS_SSO_CLIENT_ID', 'fans-ui-local-recipe', False),
            ('FALUSS_FANS_SSO_CLIENT_SECRET', secrets.token_urlsafe(32), False),
        ]:
            run(*wp, 'config', 'set', name, value, *(['--raw'] if raw else []), '--quiet', capture_output=True)
        base = f'https://127.0.0.1:{HTTPS_PORT}'
        admin_password = secrets.token_hex(24)
        run(*wp, 'core', 'install', '--url=' + base, '--title=Fans UI Local Recipe',
            '--admin_user=recipe_admin', '--admin_password=' + admin_password,
            '--admin_email=recipe_admin@example.test', '--skip-email', capture_output=True)
        run(*wp, 'rewrite', 'structure', '/%postname%/', capture_output=True)
        run(*wp, 'plugin', 'activate', 'faluss-platform', capture_output=True)
        ready = run(*wp, 'eval',
                    "echo (int) Faluss\\Platform\\Fans\\Sso\\FansSsoSchema::ready() . ':' . "
                    "(int) Faluss\\Platform\\Fans\\Profiles\\CreatorProfileSchema::ready() . ':' . "
                    "(int) Faluss\\Platform\\Fans\\Ui\\FansUiModule::available();",
                    capture_output=True).stdout.strip()
        if ready != '1:1:1':
            raise RuntimeError('WordPress modules are not ready: ' + ready)
        run(*wp, 'eval-file', str(REPO / 'tests/Fans/Ui/recipe/real-wp-fixture.php'), capture_output=True)
        fixture_path = root / 'fans-ui-recipe-fixture.json'
        fixture = json.loads(fixture_path.read_text())
        fixture['admin'] = {'login': 'recipe_admin', 'password': admin_password}
        fixture_path.write_text(json.dumps(fixture))
        fixture_path.chmod(0o600)

        (root / 'router.php').write_text("""<?php
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = '443';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (is_string($path) && is_file(__DIR__ . $path)) { return false; }
require __DIR__ . '/index.php';
""")
        php_server = subprocess.Popen(['php', '-d', 'opcache.enable=0', '-S',
                                       f'127.0.0.1:{HTTP_PORT}', 'router.php'],
                                      cwd=root, env={**os.environ, 'PHP_CLI_SERVER_WORKERS': '4'},
                                      stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        cert = root / 'cert.pem'
        key = root / 'key.pem'
        run('openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
            '-subj', '/CN=127.0.0.1', '-keyout', str(key), '-out', str(cert),
            capture_output=True)
        proxy = http.server.ThreadingHTTPServer(('127.0.0.1', HTTPS_PORT), Proxy)
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        context.load_cert_chain(cert, key)
        proxy.socket = context.wrap_socket(proxy.socket, server_side=True)
        threading.Thread(target=proxy.serve_forever, daemon=True).start()
        time.sleep(1)
        script = subprocess.check_output(['wslpath', '-w', str(REPO / 'tests/Fans/Ui/recipe/real-wp-browser.cjs')], text=True).strip()
        env = {**os.environ, 'NODE_PATH': NODE_PATH,
               'WSLENV': ':'.join(filter(None, [os.environ.get('WSLENV'), 'NODE_PATH']))}
        run(NODE, script, input=fixture_path.read_text(), env=env)
        print('PASS disposable WordPress 7.1.2 / MariaDB 11.8.6 / Chrome recipe; local flags only.', flush=True)
    finally:
        if proxy:
            proxy.shutdown()
            proxy.server_close()
        if php_server:
            php_server.terminate()
            try:
                php_server.wait(timeout=5)
            except subprocess.TimeoutExpired:
                php_server.kill()
                php_server.wait()
        if root.resolve().parent == Path('/var/tmp'):
            shutil.rmtree(root)
        if user_created:
            sql(f"DROP USER '{DB_USER}'@'localhost'")
        if db_created:
            sql(f'DROP DATABASE `{DB}`')


if __name__ == '__main__':
    main()
