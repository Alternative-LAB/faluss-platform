"""Disposable WordPress + MariaDB admission recipe. Loopback only; no production data.
Uses a new private database/configuration and generated local accounts. Touch STOP to stop.
"""
import argparse, json, os, pathlib, pwd, secrets, shutil, socket, subprocess, tempfile, time

p = argparse.ArgumentParser()
p.add_argument('--source', required=True)
p.add_argument('--core', required=True)
p.add_argument('--cli', required=True)
p.add_argument('--test', action='store_true')
p.add_argument('--keep', action='store_true')
a = p.parse_args()
root = pathlib.Path(tempfile.mkdtemp(prefix='fans-admission-wp-', dir='/var/tmp'))
wp = root/'wordpress'
wp.mkdir()
for item in pathlib.Path(a.core).iterdir():
    if item.name in ('wp-content', 'wp-config.php', 'router.php') or not (item.name.startswith('wp-') or item.name in ('index.php', 'xmlrpc.php')): continue
    if item.is_dir(): shutil.copytree(item, wp/item.name)
    else: shutil.copy2(item, wp/item.name)
plugin = wp/'wp-content/plugins/faluss-platform'
shutil.copytree(a.source, plugin, ignore=shutil.ignore_patterns('.git', '.cache', 'node_modules'))
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
        result = subprocess.run(['mariadb', '--no-defaults', '--socket='+str(root/'sql.sock'), '-uroot', '-e', 'CREATE DATABASE IF NOT EXISTS admission_recipe'], capture_output=True)
        if result.returncode == 0: break
        time.sleep(.1)
    else: raise RuntimeError('Private database unavailable')
    with socket.socket() as probe: probe.bind(('127.0.0.1', 0)); port = probe.getsockname()[1]
    base = 'http://127.0.0.1:'+str(port)
    values = {'DB_NAME':'admission_recipe', 'DB_USER':'root', 'DB_PASSWORD':'', 'DB_HOST':'localhost:'+str(root/'sql.sock'), 'DB_CHARSET':'utf8mb4', 'DB_COLLATE':'', 'WP_HOME':'https://fans.example.test', 'WP_SITEURL':base, 'WP_HTTP_BLOCK_EXTERNAL':True, 'DISABLE_WP_CRON':True, 'WP_DEBUG':True, 'WP_DEBUG_DISPLAY':False, 'WP_DEBUG_LOG':str(root/'debug.log'), 'FALUSS_PLATFORM_ROLE':'fans', 'FALUSS_PLATFORM_FANS_SSO':True, 'FALUSS_PLATFORM_FANS_CREATOR_PROFILES':True, 'FALUSS_PLATFORM_FANS_EDITORIAL':True, 'FALUSS_PLATFORM_FANS_UI':True}
    for key in ('AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT'): values[key] = secrets.token_urlsafe(48)
    config = '<?php\n'+''.join('define('+json.dumps(k)+','+json.dumps(v)+');\n' for k,v in values.items())
    config += "$table_prefix='wp_';\nif(!defined('ABSPATH')){define('ABSPATH',__DIR__.'/');}\nrequire_once ABSPATH.'wp-settings.php';\n"
    (wp/'wp-config.php').write_text(config); os.chmod(wp/'wp-config.php', 0o600)
    cli('core', 'install', '--url='+base, '--title=Fans local admission recipe', '--admin_user=fixture', '--admin_password='+secrets.token_urlsafe(32), '--admin_email=fixture@example.invalid', '--skip-email')
    cli('theme', 'activate', 'twentytwentyfive')
    mu = wp/'wp-content/mu-plugins'; mu.mkdir()
    shutil.copy2(pathlib.Path(a.source)/'tests/Fans/Profiles/recipe/admission-runtime.php', mu/'admission-fixture.php')
    cli('plugin', 'activate', 'faluss-platform')
    cli('eval-file', str(pathlib.Path(a.source)/'tests/Fans/Profiles/recipe/admission-seed.php'), str(root/'session.json'))
    os.chmod(root/'session.json', 0o600)
    web = subprocess.Popen(['php', '-S', '127.0.0.1:'+str(port), '-t', str(wp)], stdout=log, stderr=log, env=dict(os.environ, PHP_CLI_SERVER_WORKERS='4'), start_new_session=True)
    time.sleep(.5)
    print(json.dumps({'base':base, 'root':str(root), 'wordpress':cli('core', 'version').strip(), 'php':run(['php','-r','echo PHP_VERSION;']).strip()}), flush=True)
    if a.test:
        run(['python3', str(pathlib.Path(a.source)/'tests/Fans/Profiles/recipe/admission-http.py'), '--root', str(root), '--base', base, '--cli', a.cli])
        print((root/'checks.json').read_text(), flush=True)
        if not a.keep: (root/'STOP').touch()
    while not (root/'STOP').exists():
        if web.poll() is not None: raise RuntimeError('Fixture stopped unexpectedly')
        time.sleep(.5)
finally:
    if web is not None:
        import signal
        os.killpg(web.pid, signal.SIGTERM); web.wait(timeout=10)
    if db is not None: db.terminate(); db.wait(timeout=20)
    log.close()
