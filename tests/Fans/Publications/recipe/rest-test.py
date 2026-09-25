"""Destructive fixtures ONLY on faluss_text_publications_recipe; loopback HTTP, not production/TLS proof."""
import concurrent.futures
import json
import subprocess
import urllib.request
import urllib.error
import time
from pathlib import Path

ROOT = Path('/var/tmp/faluss-text-publications-recipe')
SESSIONS = json.loads(Path('/var/tmp/faluss-text-publications-proof/sessions.json').read_text())
DB = 'faluss_text_publications_recipe'
BASE = 'http://127.0.0.1:8113/wp-json/faluss-fans/v1/'
checks = 0

def check(label, condition):
    global checks
    if not condition:
        raise AssertionError(label)
    checks += 1
    print('PASS ' + label)

def call(who, route, data=None, nonce=True):
    headers = {'Host': 'text.local.test'}
    if who in SESSIONS:
        s = SESSIONS[who]
        headers['Cookie'] = s['cookie_name'] + '=' + s['cookie']
        if nonce:
            headers['X-WP-Nonce'] = s['nonce']
    if data is not None:
        headers['Content-Type'] = 'application/json'
    req = urllib.request.Request(BASE + route, None if data is None else json.dumps(data).encode(), headers)
    try:
        result = urllib.request.urlopen(req, timeout=30)
    except urllib.error.HTTPError as e:
        result = e
    return result.status, json.loads(result.read()), result.headers

def sql(statement):
    return subprocess.check_output(['mariadb', '-N', DB, '-e', statement], text=True).strip()

def create():
    status, row, _ = call('owner', 'text-publications', {'text': 'Texte synthétique autorisé.', 'category': 'hosted_allowed_content'})
    check('active owner creates pending text', status == 201 and row['state'] == 'pending')
    return row

def moderate(row, decision='approve', reason='allowed_text'):
    return call('admin', 'text-publications/' + row['publication_id'] + '/moderate',
                {'revision': int(row['revision']), 'decision': decision, 'reason': reason})

payload = {'text': 'Texte.', 'category': 'hosted_allowed_content'}
for who, nonce in [('anon', True), ('owner', False), ('unlinked', True), ('pending', True), ('admin', True)]:
    status, _, _ = call(who, 'text-publications', payload, nonce)
    check('creation denied for ' + who + (' without nonce' if not nonce else ''), status in [401, 403])
for data in [{**payload, 'category': 'external_adult_delivery_right'}, {**payload, 'creator_id': SESSIONS['other']['creator_id']},
             {**payload, 'media_id': 1}, {**payload, 'access': 'locked'}, {**payload, 'text': '<script>alert(1)</script>'}]:
    check('invalid category/ownership/media/access/HTML rejected', call('owner', 'text-publications', data)[0] == 400)
row = create(); path = 'text-publications/' + row['publication_id']
check('pending detail hidden', call('anon', path)[0] == 404)
check('pending absent from public list', call('anon', 'text-publications')[1] == [])
check('private owner can read', call('owner', path + '/private')[0] == 200)
for suffix, data in [('/private', None), ('/edit', {'revision': 1, 'text': 'Usurpation.'}), ('/withdraw', {'revision': 1}),
                     ('/moderate', {'revision': 1, 'decision': 'approve', 'reason': 'allowed_text'}), ('/decisions', None)]:
    check('other owner denied ' + suffix, call('other', path + suffix, data)[0] == 403)
check('non-admin queue denied', call('owner', 'text-publications/moderation')[0] == 403)
check('admin queue contains pending', len(call('admin', 'text-publications/moderation')[1]) == 1)
check('approval without explicit allowed-text decision denied', moderate(row, reason='')[0] == 400)

# A genuine SQL failure must roll back both publication and journal, on InnoDB.
sql("CREATE TRIGGER recipe_fail_decision BEFORE INSERT ON wp_faluss_fans_text_decisions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='recipe audit failure'")
try:
    check('audit failure rejects approval', moderate(row)[0] == 503)
    check('audit failure keeps public hidden', call('anon', path)[0] == 404)
    check('audit failure preserves pending revision', sql('SELECT CONCAT(state,revision) FROM wp_faluss_fans_text_publications') == 'pending1')
    check('audit failure leaves no orphan creation', call('owner', 'text-publications', payload)[0] == 503 and sql('SELECT COUNT(*) FROM wp_faluss_fans_text_publications') == '1')
finally:
    sql('DROP TRIGGER recipe_fail_decision')
status, row, _ = moderate(row)
check('admin approval succeeds', status == 200 and row['state'] == 'approved')
status, public, headers = call('anon', path)
check('public exact whitelist excludes private data', status == 200 and set(public) == {'publication_id', 'creator_id', 'revision', 'body', 'updated_at'})
check('public no-store', 'no-store' in headers.get('Cache-Control', ''))

# Two independent HTTP workers, same revision. Exactly one write and journal entry.
with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
    futures = [pool.submit(call, 'owner', path + '/edit', {'revision': 2, 'text': text}) for text in ['Révision A.', 'Révision B.']]
    results = [f.result() for f in futures]
check('concurrent edits yield one success and one conflict', sorted(r[0] for r in results) == [200, 409])
check('edit immediately hides approved text', call('anon', path)[0] == 404)
check('one audit record per revision', sql('SELECT COUNT(*) FROM wp_faluss_fans_text_decisions') == '3')
row = next(r[1] for r in results if r[0] == 200)
check('stale moderation denied', moderate({**row, 'revision': 2})[0] == 409)
status, row, _ = moderate(row, 'reject', 'prohibited_content')
check('rejection purges body and remains hidden', status == 200 and row['body'] == '' and call('anon', path)[0] == 404)
status, audit, _ = call('admin', path + '/decisions')
check('audit traces actor reason revision and hash only', status == 200 and audit[0]['reason'] == 'prohibited_content' and int(audit[0]['actor_id']) == 1 and 'body' not in audit[0])
status, row, _ = call('owner', path + '/edit', {'revision': int(row['revision']), 'text': 'Texte corrigé.'})
check('rejected revision can be corrected pending review', status == 200 and row['state'] == 'pending')
status, row, _ = moderate(row)
cid = SESSIONS['owner']['creator_id']
check('admin suspends profile', call('admin', 'creators/' + cid + '/status', {'status': 'suspended'})[0] == 200)
check('suspension hides public detail and listing', call('anon', path)[0] == 404 and call('anon', 'text-publications')[1] == [])
check('suspended owner edit denied', call('owner', path + '/edit', {'revision': int(row['revision']), 'text': 'Texte.'})[0] == 403)
status, withdrawn, _ = call('owner', path + '/withdraw', {'revision': int(row['revision'])})
check('suspended owner can withdraw and purge', status == 200 and withdrawn['state'] == 'withdrawn' and withdrawn['body'] == '')
call('admin', 'creators/' + cid + '/status', {'status': 'active'})
check('reactivation never restores withdrawn text', call('anon', path)[0] == 404 and moderate(withdrawn)[0] == 409)
check('no posts or media created', sql("SELECT COUNT(*) FROM wp_posts WHERE post_type='attachment'") == '0')
check('both text tables InnoDB', sql("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('wp_faluss_fans_text_publications','wp_faluss_fans_text_decisions') AND ENGINE='InnoDB'") == '2')

count = sql('SELECT COUNT(*) FROM wp_faluss_fans_text_decisions')
subprocess.run(['php', '/var/tmp/faluss-v3-wp/wp-cli.phar', '--allow-root', '--path=' + str(ROOT), 'config', 'set', 'FALUSS_PLATFORM_FANS_TEXT_PUBLICATIONS', 'false', '--raw', '--quiet'], check=True)
# PHP configuration file reload is distinct from transactional content revocation.
for _ in range(10):
    if call('anon', 'text-publications')[0] == 404:
        break
    time.sleep(0.5)
check('flag rollback removes routes', call('anon', 'text-publications')[0] == 404 and call('owner', 'text-publications', payload)[0] == 404)
check('flag rollback preserves private audit', sql('SELECT COUNT(*) FROM wp_faluss_fans_text_decisions') == count)
print(str(checks) + ' real WordPress/MariaDB REST checks passed; flag disabled.')
