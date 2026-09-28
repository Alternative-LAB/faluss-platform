"""Disposable loopback WordPress/MariaDB recipe; never a production command."""
import concurrent.futures
import json
import os
import pathlib
import signal
import socket
import subprocess
import time
import urllib.error
import urllib.request
import uuid

ROOT = pathlib.Path('/var/tmp/faluss-store-archive-recipe')
PROOF = pathlib.Path('/var/tmp/faluss-store-archive-proof')
WP = ['php', '/var/tmp/faluss-v3-wp/wp-cli.phar', '--allow-root', '--path=' + str(ROOT)]
BASE = 'http://127.0.0.1:8118/?rest_route=/faluss-fans/v1/store'
FLAGS = ['FALUSS_PLATFORM_FANS_STORE_CATALOG', 'FALUSS_PLATFORM_FANS_CREATOR_PROFILES', 'FALUSS_PLATFORM_FANS_SSO']
checks = 0

def wp(*args):
    return subprocess.check_output(WP + list(args), text=True).strip()

def sql(query):
    return subprocess.check_output(['mariadb', '-N', 'faluss_store_archive_recipe', '-e', query], text=True).strip()

def check(value, label):
    global checks
    assert value, label
    checks += 1

def request(path, actor=None, method='GET', data=None, extra=None, nonce=True):
    headers = {'Host': 'store.local.test'}
    if actor:
        session = fixture['sessions'][actor]
        headers['Cookie'] = session['cookie_name'] + '=' + session['cookie']
        if nonce:
            headers['X-WP-Nonce'] = session['nonce']
    if extra:
        headers.update(extra)
    if data is not None:
        headers['Content-Type'] = 'application/json'
    req = urllib.request.Request(BASE + path, headers=headers, method=method,
        data=None if data is None else json.dumps(data).encode())
    try:
        response = urllib.request.urlopen(req, timeout=15)
    except urllib.error.HTTPError as error:
        response = error
    return response.status, json.loads(response.read()), dict(response.headers)

assert wp('config', 'get', 'DB_NAME') == 'faluss_store_archive_recipe'
assert ROOT.resolve() == ROOT
fixture = json.loads((PROOF / 'sessions.json').read_text())
server = None
log = None
try:
    for flag in FLAGS:
        wp('config', 'set', flag, 'true', '--raw')
    log = (PROOF / 'server.log').open('w')
    server = subprocess.Popen(['php', '-S', '127.0.0.1:8118', 'router.php'], cwd=ROOT,
        env={**os.environ, 'PHP_CLI_SERVER_WORKERS': '4'}, start_new_session=True,
        stdout=log, stderr=subprocess.STDOUT)
    for _ in range(40):
        try:
            with socket.create_connection(('127.0.0.1', 8118), timeout=0.2): break
        except OSError: time.sleep(0.1)
    adult = fixture['ids']['adult'][0]
    hosted = fixture['ids']['hosted'][0]
    status, categories, _ = request('/categories')
    check(status == 200 and [x['category'] for x in categories] == ['hosted_allowed_content'], 'category removed')
    for path in ['/products', '/products&search=external_adult_delivery_right',
                 '/products&include_archived=true&visibility=hidden', '/products&category=hosted_allowed_content']:
        status, items, headers = request(path)
        check(status == 200 and len(items) == 20, 'archived records do not occupy first page')
        check(all(x['product_id'] in fixture['ids']['hosted'] for x in items), 'public list contains only permitted records')
        check('X-WP-Total' not in headers, 'no archive-count leak')
    check(request('/products&category=external_adult_delivery_right')[0] == 400, 'archived filter rejected')
    check(request('/products&category%5B%5D=hosted_allowed_content')[0] == 400, 'forged array filter rejected')
    for product in fixture['ids']['adult']:
        check(request('/products/' + product)[0] == 404, 'old detail hidden')
        check(request('/products/' + product + '/purchase', method='POST', data={'category': 'hosted_allowed_content'})[0] == 403, 'old forged purchase denied')
    check(request('/products/' + hosted)[0] == 200, 'allowed detail retained')
    check(request('/products/' + hosted + '/purchase', method='POST')[0] == 503, 'hosted purchase still closed')
    for actor in [None, 'creator']:
        for path in ['/admin/archive', '/admin/archive/' + adult]:
            check(request(path, actor)[0] in (401, 403), 'private archive denied')
    check(request('/admin/archive', 'admin', nonce=False)[0] in (401, 403), 'admin nonce required')
    check(request('/admin/archive', 'admin', extra={'X-WP-Nonce': 'forged'})[0] == 403, 'forged nonce rejected')
    seen = []
    cursor = None
    for expected in [20, 5]:
        status, page, headers = request('/admin/archive' + ('&cursor=' + cursor if cursor else ''), 'admin')
        check(status == 200 and len(page['items']) == expected, 'admin archive pagination')
        check('private' in headers['Cache-Control'] and 'no-store' in headers['Cache-Control'], 'private response not cacheable')
        check(all(x['archived'] and 'request_key' not in x for x in page['items']), 'private archive shape')
        seen.extend(x['product_id'] for x in page['items'])
        cursor = page['next_cursor']
    check(cursor is None and seen == fixture['ids']['adult'], 'archive complete without duplicate')
    check(request('/admin/archive&cursor=forged', 'admin')[0] == 400, 'bad cursor rejected')
    check(request('/admin/archive/' + hosted, 'admin')[0] == 404, 'normal listing not archive')
    body = {'creator_id': fixture['creator_id'], 'category': 'external_adult_delivery_right', 'visibility': 'visible', 'archived': False}
    def forbidden_create(_):
        return request('/products', 'admin', 'POST', body, {'Idempotency-Key': str(uuid.uuid4())})[0]
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
        check(list(pool.map(forbidden_create, range(4))) == [403] * 4, 'concurrent adult creates denied')
    for method in ['PUT', 'PATCH', 'POST']:
        check(request('/products/' + adult, 'admin', method, body)[0] in (404, 405), 'no republication or category mutation route')
    for old_category in ['hosted_allowed_content', 'external_adult_delivery_right']:
        sql("UPDATE wp_faluss_fans_store_catalog SET category='%s', visibility='visible' WHERE product_id='%s'" % (old_category, adult))
        check(request('/products/' + adult)[0] == 404, 'archive marker survives stored category change')
        check(request('/products/' + adult + '/purchase', method='POST')[0] == 403, 'archive purchase survives stored category change')
        check(request('/admin/archive/' + adult, 'admin')[1]['archived'], 'archive remains consultable')
    old_key = fixture['original'][0]['request_key']
    sql("UPDATE wp_faluss_fans_store_catalog SET category='hosted_allowed_content' WHERE product_id='%s'" % adult)
    check(request('/products', 'admin', 'POST', {'creator_id': fixture['creator_id'], 'category': 'hosted_allowed_content'}, {'Idempotency-Key': old_key})[0] == 403, 'old key cannot revive archive')
    sql("UPDATE wp_faluss_fans_store_catalog SET category='external_adult_delivery_right' WHERE product_id='%s'" % hosted)
    check(request('/products/' + hosted)[0] == 404, 'forged hosted to adult hidden')
    check(request('/products/' + hosted + '/purchase', method='POST')[0] == 403, 'forged hosted to adult denied')
    sql("UPDATE wp_faluss_fans_creator_profiles SET status='suspended' WHERE creator_id='%s'" % fixture['creator_id'])
    check(request('/admin/archive/' + adult, 'admin')[0] == 200, 'suspended creator archive remains administratively readable')
    check(request('/products')[1] == [], 'suspended products hidden')
    # Restore synthetic probes and compare all original columns with pre-migration records.
    for product in fixture['original']:
        sql("UPDATE wp_faluss_fans_store_catalog SET category='%s',visibility='%s' WHERE product_id='%s'" % (product['category'], product['visibility'], product['product_id']))
    fields = ['product_id', 'request_key', 'creator_id', 'category', 'visibility', 'created_at']
    rows = [line.split('\t') for line in sql('SELECT '+','.join(fields)+' FROM wp_faluss_fans_store_catalog ORDER BY product_id').splitlines()]
    check(rows == [[x[f] for f in fields] for x in fixture['original']], 'all 50 original records preserved')
    check(sql('SELECT COUNT(*) FROM wp_faluss_fans_store_catalog WHERE archived=1') == '25', '25 persistent markers unchanged')
finally:
    for flag in FLAGS:
        wp('config', 'set', flag, 'false', '--raw')
        check(wp('eval', "echo constant('" + flag + "') === false ? 'closed' : 'open';") == 'closed', 'local flag closed')
    if server:
        try:
            check(request('/products')[0] == 404 and request('/admin/archive', 'admin')[0] == 404, 'routes closed after rollback')
        finally:
            os.killpg(server.pid, signal.SIGTERM)
            server.wait(timeout=10)
    if log: log.close()
    for user in [1, fixture['user_id']]:
        wp('user', 'session', 'destroy', str(user), '--all')
    (PROOF / 'sessions.json').unlink()
    time.sleep(0.3)
    with socket.socket() as probe:
        check(probe.connect_ex(('127.0.0.1', 8118)) != 0, 'loopback server stopped')
print('PASS %d real HTTP/database checks; all flags false; server stopped; sessions revoked.' % checks)
