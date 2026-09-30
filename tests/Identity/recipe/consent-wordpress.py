"""Disposable Identity consent WordPress recipe. Loopback only; no production data.
Uses a new private database/configuration and generated local accounts. Touch STOP to stop.
"""
import argparse, json, os, pathlib, pwd, secrets, shutil, socket, subprocess, tempfile, time
import ssl, http.server, http.client, threading

p = argparse.ArgumentParser()
p.add_argument('--source', required=True)
p.add_argument('--core', required=True)
p.add_argument('--cli', required=True)

a = p.parse_args()
root = pathlib.Path(tempfile.mkdtemp(prefix='identity-consent-wp-', dir='/var/tmp'))
wp = root/'wordpress'
wp.mkdir()
for item in pathlib.Path(a.core).iterdir():
    if item.name in ('wp-content', 'wp-config.php', 'router.php') or not (item.name.startswith('wp-') or item.name in ('index.php', 'xmlrpc.php')): continue
    if item.is_dir(): shutil.copytree(item, wp/item.name)
    else: shutil.copy2(item, wp/item.name)
plugin = wp/'wp-content/plugins/faluss-platform'
shutil.copytree(a.source, plugin, ignore=shutil.ignore_patterns('.git', '.cache'))
shutil.copytree(pathlib.Path(a.core)/'wp-content/themes/twentytwentyfive', wp/'wp-content/themes/twentytwentyfive')

db = web = None
log = open(root/'runtime.log', 'w')
def run(args):
    result = subprocess.run(args, capture_output=True, text=True)
    if result.returncode: raise RuntimeError(result.stderr[-1500:])
    return result.stdout
def cli(*args): return run(['php', a.cli, '--allow-root', '--path='+str(wp), *args])
try:
    run(['mariadb-install-db', '--no-defaults', '--datadir='+str(root/'db'), '--auth-root-authentication-method=normal'])
    db = subprocess.Popen(['mariadbd', '--no-defaults', '--user='+pwd.getpwuid(os.geteuid()).pw_name, '--datadir='+str(root/'db'), '--socket='+str(root/'sql.sock'), '--pid-file='+str(root/'sql.pid'), '--skip-networking'], stdout=log, stderr=log)
    for _ in range(100):
        result = subprocess.run(['mariadb', '--no-defaults', '--socket='+str(root/'sql.sock'), '-uroot', '-e', 'CREATE DATABASE IF NOT EXISTS button_recipe'], capture_output=True)
        if result.returncode == 0: break
        time.sleep(.1)
    else: raise RuntimeError('Private database unavailable')
    with socket.socket() as probe: probe.bind(('127.0.0.1', 0)); port = probe.getsockname()[1]
    with socket.socket() as probe: probe.bind(('127.0.0.1', 0)); tls_port = probe.getsockname()[1]
    base = 'https://127.0.0.1:'+str(tls_port)
    values = {'DB_NAME':'button_recipe', 'DB_USER':'root', 'DB_PASSWORD':'', 'DB_HOST':'localhost:'+str(root/'sql.sock'), 'DB_CHARSET':'utf8mb4', 'DB_COLLATE':'', 'WP_HOME':base, 'WP_SITEURL':base, 'WP_HTTP_BLOCK_EXTERNAL':True, 'DISABLE_WP_CRON':True, 'WP_DEBUG':True, 'WP_DEBUG_DISPLAY':False, 'WP_DEBUG_LOG':str(root/'debug.log'), 'FALUSS_PLATFORM_ROLE':'me', 'FALUSS_PLATFORM_IDENTITY':True}
    for key in ('AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT'): values[key] = secrets.token_urlsafe(48)
    config = '<?php\n'+''.join('define('+json.dumps(k)+','+json.dumps(v)+');\n' for k,v in values.items())
    config += "$_SERVER['HTTPS']='on';\n$table_prefix='wp_';\nif(!defined('ABSPATH')){define('ABSPATH',__DIR__.'/');}\nrequire_once ABSPATH.'wp-settings.php';\n"
    (wp/'wp-config.php').write_text(config); os.chmod(wp/'wp-config.php', 0o600)
    cli('core', 'install', '--url='+base, '--title=Identity · recette locale', '--admin_user=fixture', '--admin_password='+secrets.token_urlsafe(32), '--admin_email=fixture@example.invalid', '--skip-email')
    cli('theme', 'activate', 'twentytwentyfive')
    mu = wp/'wp-content/mu-plugins'; mu.mkdir()
    shutil.copy2(pathlib.Path(a.source)/'tests/Identity/recipe/consent-runtime.php', mu/'access-fixture.php')
    cli('plugin', 'activate', 'faluss-platform')
    cli('eval-file', str(pathlib.Path(a.source)/'tests/Identity/recipe/consent-seed.php'), str(root/'session.json'))
    os.chmod(root/'session.json', 0o600)
    web = subprocess.Popen(['php', '-S', '127.0.0.1:'+str(port), '-t', str(wp)], stdout=log, stderr=log)
    run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(root/'local.key'),'-out',str(root/'local.crt'),'-days','1','-subj','/CN=127.0.0.1'])
    os.chmod(root/'local.key',0o600)
    class Proxy(http.server.BaseHTTPRequestHandler):
        def do_GET(self): self.forward()
        def do_POST(self): self.forward()
        def log_message(self, *args): pass
        def forward(self):
            connection=http.client.HTTPConnection('127.0.0.1',port,timeout=30)
            body=self.rfile.read(int(self.headers.get('Content-Length','0')))
            connection.request(self.command,self.path,body,dict(self.headers))
            response=connection.getresponse()
            self.send_response(response.status)
            for key,value in response.getheaders():
                if key.lower() not in ('connection','transfer-encoding'): self.send_header(key,value)
            self.end_headers();self.wfile.write(response.read());connection.close()
    proxy=http.server.ThreadingHTTPServer(('127.0.0.1',tls_port),Proxy)
    tls=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);tls.load_cert_chain(root/'local.crt',root/'local.key')
    proxy.socket=tls.wrap_socket(proxy.socket,server_side=True)
    threading.Thread(target=proxy.serve_forever,daemon=True).start()
    print(json.dumps({'base':base, 'root':str(root), 'wordpress':cli('core', 'version').strip(), 'php':run(['php','-r','echo PHP_VERSION;']).strip()}), flush=True)
    while not (root/'STOP').exists():
        if web.poll() is not None: raise RuntimeError('Fixture stopped unexpectedly')
        time.sleep(.5)
finally:
    if web is not None: web.terminate(); web.wait(timeout=10)
    if db is not None: db.terminate(); db.wait(timeout=20)
    log.close()
