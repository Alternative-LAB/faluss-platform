"""Destructive ownership fixture ONLY for the named disposable local WordPress DB.
Run as root under Linux; HTTP workers run as www-data, not root. No production access.
"""
import json, os, pwd, signal, socket, subprocess, time, urllib.request, urllib.error
from pathlib import Path
ROOT = Path('/var/tmp/faluss-text-publications-recipe')
REPO = Path(__file__).resolve().parents[4]
BASE = Path('/var/tmp/faluss-image-owner-cases')
TMP = Path('/var/tmp/faluss-image-owner-upload-tmp')
WP = ['php', '/var/tmp/faluss-v3-wp/wp-cli.phar', '--allow-root', '--path='+str(ROOT)]
assert os.geteuid() == 0
assert subprocess.check_output(WP+['config','get','DB_NAME'],text=True).strip() == 'faluss_text_publications_recipe'
assert (ROOT/'wp-content/plugins/faluss-platform').resolve() == REPO
php_uid = pwd.getpwnam('www-data').pw_uid
php_gid = pwd.getpwnam('www-data').pw_gid
other_uid = pwd.getpwnam('nobody').pw_uid
assert php_uid not in [0, other_uid]
for directory,owner in [(BASE,0),(TMP,php_uid)]:
    directory.mkdir(exist_ok=True)
    assert directory.resolve() == directory and not directory.is_symlink()
    os.chown(directory,owner,0); directory.chmod(0o755 if directory == BASE else 0o700)
old_root = subprocess.check_output(WP+['config','get','FALUSS_FANS_IMAGE_PRIVATE_ROOT'],text=True).strip()
old_db_user = subprocess.check_output(WP+['config','get','DB_USER'],text=True).strip()
assert subprocess.check_output(['mariadb','-N','-e',"SELECT COUNT(*) FROM mysql.user WHERE User='www-data' AND Host='localhost'"],text=True).strip() == '0'
server = None; paths = []; checks = 0; db_user_created = False
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl): return None
urllib.request.install_opener(urllib.request.build_opener(NoRedirect()))
def config(name,value,raw=False):
    subprocess.run(WP+['config','set',name,value,'--quiet']+(['--raw'] if raw else []),check=True)
def check(label,condition):
    global checks
    if not condition: raise AssertionError(label)
    checks += 1; print('PASS '+label,flush=True)
def demote():
    os.setgroups([]); os.setgid(php_gid); os.setuid(php_uid)
def call(who,route,body=None,content_type='application/json'):
    account=sessions[who]
    request=urllib.request.Request('http://127.0.0.1:8113/wp-json/faluss-fans/v1/'+route,body,{'Host':'text.local.test','Cookie':account['cookie_name']+'='+account['cookie'],'X-WP-Nonce':account['nonce'],'Content-Type':content_type})
    try: result=urllib.request.urlopen(request,timeout=30)
    except urllib.error.HTTPError as e: result=e
    data=result.read()
    return result.status, data if result.headers.get_content_type()=='image/png' else json.loads(data)
try:
    config('FALUSS_PLATFORM_FANS_IMAGES','true',True)
    subprocess.run(WP+['eval-file',str(REPO/'tests/Fans/Images/recipe/fixture.php')],check=True)
    sessions=json.loads(Path('/var/tmp/faluss-image-proof/sessions.json').read_text())
    png=subprocess.check_output(['php','-r','$i=imagecreatetruecolor(2,2); imagepng($i);'])
    subprocess.run(['mariadb','-e',"CREATE USER 'www-data'@'localhost' IDENTIFIED VIA unix_socket"],check=True)
    db_user_created = True
    subprocess.run(['mariadb','-e',"GRANT ALL ON faluss_text_publications_recipe.* TO 'www-data'@'localhost'"],check=True)
    config('DB_USER','www-data')
    server=subprocess.Popen(['php','-d','opcache.enable=0','-d','upload_tmp_dir='+str(TMP),'-S','127.0.0.1:8113','router.php'],cwd=ROOT,env={**os.environ,'PHP_CLI_SERVER_WORKERS':'4'},stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,start_new_session=True,preexec_fn=demote)
    for _ in range(30):
        try:
            with socket.create_connection(('127.0.0.1',8113),timeout=1): break
        except OSError: time.sleep(.2)
    check('HTTP process runs under unprivileged PHP UID', Path('/proc/'+str(server.pid)).stat().st_uid == php_uid)
    cases=[('root-0755',0,0o755,True),('php-0755',php_uid,0o755,True),('other-0755',other_uid,0o755,False),('other-sticky',other_uid,0o1777,False),('root-sticky',0,0o1777,True),('php-sticky',php_uid,0o1777,True),('root-writable',0,0o777,False),('deep-other-0755',other_uid,0o755,False)]
    for name,owner,mode,allowed in cases:
        parent=BASE/name; parent.mkdir(); paths.append(parent); os.chown(parent,owner,0); parent.chmod(mode)
        if name.startswith('deep-'):
            parent=parent/'trusted-child'; parent.mkdir(); paths.append(parent); os.chown(parent,php_uid,php_gid); parent.chmod(0o755)
        leaf=parent/'private'; leaf.mkdir(); paths.append(leaf); os.chown(leaf,php_uid,php_gid); leaf.chmod(0o700)
        config('FALUSS_FANS_IMAGE_PRIVATE_ROOT',str(leaf))
        body=b'--owner-case\r\nContent-Disposition: form-data; name="image"; filename="image.png"\r\nContent-Type: image/png\r\n\r\n'+png+b'\r\n--owner-case--\r\n'
        status,item=call('owner','images',body,'multipart/form-data; boundary=owner-case')
        check(name+' admission', status == (201 if allowed else 503))
        if allowed:
            check(name+' admin bytes remain available',call('admin','images/'+item['image_id']+'/bytes')[0]==200)
            check(name+' withdrawal cleans private file',call('owner','images/'+item['image_id']+'/withdraw',json.dumps({'revision':1}).encode())[0]==200 and list(leaf.iterdir())==[])
        else:
            check(name+' leaves no file',item['code']=='private_storage_required' and list(leaf.iterdir())==[])
    config('FALUSS_PLATFORM_FANS_IMAGES','false',True)
    check('flag rollback removes routes',call('admin','images')[0]==404)
    print(str(checks)+' real WordPress ownership controls passed.',flush=True)
finally:
    config('FALUSS_PLATFORM_FANS_IMAGES','false',True)
    config('FALUSS_FANS_IMAGE_PRIVATE_ROOT',old_root)
    config('DB_USER',old_db_user)
    if server is not None:
        os.killpg(server.pid,signal.SIGTERM); server.wait(timeout=10)
    if db_user_created: subprocess.run(['mariadb','-e',"DROP USER 'www-data'@'localhost'"],check=True)
    # No recursive removal: leave non-empty fixtures for diagnosis if a test failed.
    for path in reversed(paths):
        if path.exists() and not path.is_symlink() and not list(path.iterdir()): path.rmdir()
    for path in [BASE,TMP]:
        if not list(path.iterdir()): path.rmdir()
    print('Local flag false, original private root restored, server group stopped.',flush=True)
