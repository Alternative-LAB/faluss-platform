"""Two isolated WordPress installations, private MariaDB, HTTPS loopback only.
No DNS/hosts changes. Port 443 must be free; STOP terminates only these processes.
Runtime files contain synthetic credentials and must never be published.
"""
import argparse, json, os, pathlib, secrets, shutil, socket, subprocess, tempfile, time
import ssl, http.server, http.client, threading, signal

p = argparse.ArgumentParser()
for name in ('source', 'core', 'cli'): p.add_argument('--'+name, required=True)
a = p.parse_args(); source = pathlib.Path(a.source)
root = pathlib.Path(tempfile.mkdtemp(prefix='fans-session-wp-', dir='/var/tmp')); root.chmod(0o700)
processes = []; db = None; log = open(root/'runtime.log', 'w')
def run(args):
    result = subprocess.run(args, capture_output=True, text=True)
    if result.returncode: raise RuntimeError('Fixture command failed: '+result.stderr[-1200:])
    return result.stdout
def cli(site, *args): return run(['php', a.cli, '--allow-root', '--path='+str(root/site), *args])
def port():
    with socket.socket() as probe: probe.bind(('127.0.0.1', 0)); return probe.getsockname()[1]
try:
    # Fail before setup if this recipe would collide with another local service.
    with socket.socket() as probe: probe.bind(('127.0.0.1', 443))
    run(['mariadb-install-db','--no-defaults','--datadir='+str(root/'db'),'--auth-root-authentication-method=normal'])
    db=subprocess.Popen(['mariadbd','--no-defaults','--user='+str(os.getuid()),'--datadir='+str(root/'db'),'--socket='+str(root/'sql.sock'),'--pid-file='+str(root/'sql.pid'),'--skip-networking'],stdout=log,stderr=log)
    for _ in range(100):
        r=subprocess.run(['mariadb','--no-defaults','--socket='+str(root/'sql.sock'),'-uroot','-e','CREATE DATABASE IF NOT EXISTS faluss_fans_me_recipe; CREATE DATABASE IF NOT EXISTS faluss_fans_recipe'],capture_output=True)
        if r.returncode == 0: break
        time.sleep(.1)
    else: raise RuntimeError('Private database unavailable')
    run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(root/'key.pem'),'-out',str(root/'cert.pem'),'-days','2','-subj','/CN=fans.local.test','-addext','subjectAltName=DNS:faluss.me,DNS:fans.local.test'])
    (root/'key.pem').chmod(0o600)
    secret=secrets.token_urlsafe(32); (root/'secret').write_text(secret); (root/'secret').chmod(0o600)
    ports={'faluss.me':port(),'fans.local.test':port()}
    for site,host in [('me','faluss.me'),('fans','fans.local.test')]:
        wp=root/site; wp.mkdir()
        for item in pathlib.Path(a.core).iterdir():
            if item.name in ('wp-content','wp-config.php','router.php') or not (item.name.startswith('wp-') or item.name in ('index.php','xmlrpc.php')): continue
            if item.is_dir(): shutil.copytree(item,wp/item.name)
            else: shutil.copy2(item,wp/item.name)
        shutil.copytree(source,wp/'wp-content/plugins/faluss-platform',ignore=shutil.ignore_patterns('.git','.cache','node_modules'))
        shutil.copytree(pathlib.Path(a.core)/'wp-content/themes/twentytwentyfive',wp/'wp-content/themes/twentytwentyfive')
        values={'DB_NAME':'faluss_fans_me_recipe' if site=='me' else 'faluss_fans_recipe','DB_USER':'root','DB_PASSWORD':'','DB_HOST':'localhost:'+str(root/'sql.sock'),'DB_CHARSET':'utf8mb4','DB_COLLATE':'','WP_HOME':'https://'+host,'WP_SITEURL':'https://'+host,'DISABLE_WP_CRON':True,'WP_DEBUG':True,'WP_DEBUG_DISPLAY':False,'WP_DEBUG_LOG':str(root/(site+'-debug.log')),'FALUSS_PLATFORM_ROLE':site,'FALUSS_PLATFORM_IDENTITY':site=='me','FALUSS_PLATFORM_FANS_SSO':site=='fans','FALUSS_PLATFORM_FANS_UI':site=='fans','FALUSS_FANS_SSO_CLIENT_ID':'fans-local-recipe','FALUSS_FANS_SSO_CLIENT_SECRET':secret}
        for key in ('AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT'): values[key]=secrets.token_urlsafe(48)
        config='<?php\n'+''.join('define('+json.dumps(k)+','+json.dumps(v)+');\n' for k,v in values.items())
        config+="$_SERVER['HTTPS']='on'; $_SERVER['SERVER_PORT']=443;\n$table_prefix='wp_';\nif(!defined('ABSPATH'))define('ABSPATH',__DIR__.'/');\nrequire_once ABSPATH.'wp-settings.php';\n"
        (wp/'wp-config.php').write_text(config); (wp/'wp-config.php').chmod(0o600)
        mu=wp/'wp-content/mu-plugins'; mu.mkdir()
        transport="<?php\nadd_filter('pre_wp_mail', '__return_false');\nadd_filter('pre_http_request', static function($pre,$args,$url){return "+('true' if site=='fans' else 'false')+" && $url==='https://faluss.me/oauth/token' ? $pre : new WP_Error('loopback_only');},10,3);\n"
        transport+="add_action('http_api_curl', static function($handle){curl_setopt($handle,CURLOPT_RESOLVE,['faluss.me:443:127.0.0.1']);curl_setopt($handle,CURLOPT_PROXY,'');});\nadd_filter('http_request_args',static function($args){$args['sslcertificates']="+json.dumps(str(root/'cert.pem'))+";return $args;});\n"
        (mu/'loopback-only.php').write_text(transport)
        cli(site,'core','install','--url=https://'+host,'--title=Isolated session recipe','--admin_user=fixture','--admin_password='+secrets.token_urlsafe(32),'--admin_email=fixture@example.invalid','--skip-email')
        cli(site,'theme','activate','twentytwentyfive'); cli(site,'plugin','activate','faluss-platform')
        cli(site,'rewrite','structure','/%postname%/'); cli(site,'rewrite','flush')
        (wp/'router.php').write_text("<?php $p=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH); if($p!=='/' && is_file(__DIR__.$p))return false; require __DIR__.'/index.php';")
        processes.append(subprocess.Popen(['php','-d','opcache.enable_cli=0','-S','127.0.0.1:'+str(ports[host]),'-t',str(wp),str(wp/'router.php')],stdout=subprocess.DEVNULL,stderr=log,env=dict(os.environ,PHP_CLI_SERVER_WORKERS='4'),start_new_session=True))
    fixture=(source/'tests/Fans/Sso/recipe/fans-me-fixture.php').read_text().replace('/var/tmp/faluss-fans-http',str(root))
    (root/'seed.php').write_text(fixture); cli('me','eval-file',str(root/'seed.php'))
    cli('fans','post','create','--post_type=page','--post_status=publish','--post_title=SSO local','--post_name=sso-local','--post_content=[faluss_fans_sso_button]')
    class Proxy(http.server.BaseHTTPRequestHandler):
        def do_GET(self): self.forward()
        def do_POST(self): self.forward()
        def log_message(self,*args): pass
        def forward(self):
            host=self.headers.get('Host','').split(':')[0]
            if host not in ports: self.send_error(400); return
            connection=http.client.HTTPConnection('127.0.0.1',ports[host],timeout=30)
            connection.request(self.command,self.path,self.rfile.read(int(self.headers.get('Content-Length','0'))),dict(self.headers))
            response=connection.getresponse(); content=response.read(); self.send_response(response.status)
            for key,value in response.getheaders():
                if key.lower() not in ('connection','transfer-encoding','content-length'): self.send_header(key,value)
            self.send_header('Content-Length',str(len(content)))
            self.end_headers(); self.wfile.write(content); connection.close()
    proxy=http.server.ThreadingHTTPServer(('127.0.0.1',443),Proxy)
    tls=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); tls.load_cert_chain(root/'cert.pem',root/'key.pem'); proxy.socket=tls.wrap_socket(proxy.socket,server_side=True)
    threading.Thread(target=proxy.serve_forever,daemon=True).start()
    print(json.dumps({'root':str(root),'wordpress':cli('fans','core','version').strip(),'php':run(['php','-r','echo PHP_VERSION;']).strip(),'transport':'HTTPS loopback only'}),flush=True)
    while not (root/'STOP').exists(): time.sleep(.5)
finally:
    for process in processes: os.killpg(process.pid,signal.SIGTERM); process.wait(timeout=10)
    if db is not None: db.terminate(); db.wait(timeout=20)
    log.close()
