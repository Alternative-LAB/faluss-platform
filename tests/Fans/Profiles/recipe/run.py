"""No sites, ports or existing database: isolated MariaDB Unix socket + real PHP services.
WordPress session/REST primitives are test adapters; this is NOT a WordPress/SSO recipe.
"""
import concurrent.futures, json, os, pathlib, pwd, subprocess, tempfile, time, sys

ROOT = pathlib.Path(tempfile.mkdtemp(prefix='fans-editorial-', dir='/var/tmp'))
REPO = pathlib.Path(__file__).resolve().parents[4]
(ROOT / 'isolated-fixture').touch()
(ROOT / 'images').mkdir(mode=0o700)
ENV = dict(os.environ, FANS_EDITORIAL_FIXTURE=str(ROOT))
log = open(ROOT / 'mariadb.log', 'w')
db = None
web = None
try:
    subprocess.run(['mariadb-install-db', '--no-defaults', '--datadir='+str(ROOT/'db'), '--auth-root-authentication-method=normal'], check=True, stdout=log, stderr=log)
    db = subprocess.Popen(['mariadbd', '--no-defaults', '--user='+pwd.getpwuid(os.geteuid()).pw_name,
        '--datadir='+str(ROOT/'db'), '--socket='+str(ROOT/'sql.sock'), '--pid-file='+str(ROOT/'sql.pid'),
        '--skip-networking', '--innodb-buffer-pool-size=64M'], stdout=log, stderr=log)
    for _ in range(100):
        ready = subprocess.run(['mariadb', '--no-defaults', '--socket='+str(ROOT/'sql.sock'), '-uroot', '-e', 'SELECT 1'], capture_output=True)
        if ready.returncode == 0: break
        if db.poll() is not None: raise RuntimeError((ROOT/'mariadb.log').read_text())
        time.sleep(.1)
    else: raise RuntimeError('Isolated database did not start')
    subprocess.run(['mariadb', '--no-defaults', '--socket='+str(ROOT/'sql.sock'), '-uroot', '-e', 'CREATE DATABASE fans_editorial_test CHARACTER SET utf8mb4 COLLATE utf8mb4_bin'], check=True)
    command = ['php', str(pathlib.Path(__file__).with_name('sql.php'))]
    subprocess.run(command+['setup'], env=ENV, cwd=REPO, check=True)
    subprocess.run(command, env=ENV, cwd=REPO, check=True)
    with concurrent.futures.ThreadPoolExecutor(2) as pool:
        futures = [pool.submit(subprocess.check_output, command+['race','7'], env=ENV, text=True) for _ in range(2)]
        statuses = sorted(f.result().strip() for f in futures)
    assert statuses == ['200','409'], statuses
    print('PASS real concurrent CAS: one 200, one 409', flush=True)
    print('SQL fixture proof: '+str(ROOT), flush=True)
    if '--web' in sys.argv:
        subprocess.run(['php', str(pathlib.Path(__file__).with_name('visual-seed.php'))], env=ENV, cwd=REPO, check=True)
        web = subprocess.Popen(['php', '-S', '127.0.0.1:8768', str(pathlib.Path(__file__).with_name('web.php'))],
            env=dict(ENV, FANS_EDITORIAL_HTTP='http://127.0.0.1:8768'), cwd=REPO, stdout=log, stderr=log)
        print('Isolated UI ready: http://127.0.0.1:8768 (stop runner after browser proof)', flush=True)
        web.wait()
finally:
    if web is not None: web.terminate(); web.wait(timeout=10)
    if db is not None:
        db.terminate()
        try: db.wait(timeout=15)
        except subprocess.TimeoutExpired: db.kill(); db.wait()
    log.close()
