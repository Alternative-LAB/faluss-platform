"""Executed inside rest-test.py; synthetic data in its explicitly named disposable DB only."""

def new_text(who, text='Texte de recette.', key=None):
    return call(who, 'text-publications', {'text': text, 'category': 'hosted_allowed_content'}, key=key)

def audit_count():
    return int(sql('SELECT COUNT(*) FROM wp_faluss_fans_text_decisions'))

key = str(uuid.uuid4())
before = audit_count()
with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
    results = list(pool.map(lambda _: new_text('racer', key=key), range(4)))
check('four concurrent identical creations return one publication', all(r[0] == 201 for r in results) and len({r[1]['publication_id'] for r in results}) == 1)
check('concurrent replay writes one decision', audit_count() == before + 1)
row = results[0][1]
check('lost response retry returns same publication', new_text('racer', key=key)[1]['publication_id'] == row['publication_id'])
check('different text with same key conflicts', new_text('racer', 'Autre texte.', key)[0] == 409)
check('conflict and retry write no audit', audit_count() == before + 1)
check('invalid key denied', new_text('racer', key='invalid')[0] == 400)
check('missing idempotency header denied', new_text('racer', key=False)[0] == 400)
check('same key is scoped to each creator', new_text('other', key=key)[1]['publication_id'] != row['publication_id'])

# Inject failure after the publication and audit writes: the key must roll back too.
sql("CREATE TRIGGER recipe_fail_request BEFORE INSERT ON wp_faluss_fans_text_requests FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='recipe request failure'")
failed_key = str(uuid.uuid4()); before = audit_count()
rows_before = sql('SELECT COUNT(*) FROM wp_faluss_fans_text_publications')
keys_before = sql('SELECT COUNT(*) FROM wp_faluss_fans_text_requests')
try:
    check('failed idempotency write rolls back audit', new_text('racer', key=failed_key)[0] == 503 and audit_count() == before)
    check('failed idempotency write leaves no orphan or key', sql('SELECT COUNT(*) FROM wp_faluss_fans_text_publications') == rows_before and sql('SELECT COUNT(*) FROM wp_faluss_fans_text_requests') == keys_before)
finally:
    sql('DROP TRIGGER recipe_fail_request')
check('same key retries successfully after rollback', new_text('racer', key=failed_key)[0] == 201)

quota_rows = [new_text('quota')[1] for _ in range(20)]
check('twenty pending creations accepted', all(r.get('state') == 'pending' for r in quota_rows))
check('twenty-first pending creation denied', new_text('quota')[1]['code'] == 'publication_pending_quota')
check('editing pending at capacity denied', call('quota', 'text-publications/' + quota_rows[0]['publication_id'] + '/edit', {'revision': 1, 'text': 'Révision.'})[0] == 429)
check('withdrawal works at quota', call('quota', 'text-publications/' + quota_rows[0]['publication_id'] + '/withdraw', {'revision': 1})[0] == 200)
check('admin rejection works at quota', moderate(quota_rows[1], 'reject', 'needs_revision')[0] == 200)
check('released pending slot can be reused', new_text('quota')[0] == 201)

# Different keys at the pending boundary: only one of four may acquire the last slot.
race_rows = [new_text('racequota')[1] for _ in range(19)]
with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
    results = list(pool.map(lambda _: new_text('racequota'), range(4)))
check('pending quota serialized across concurrent keys', sorted(r[0] for r in results) == [201, 429, 429, 429])
# Release one slot, then race a creation against an edition of an existing pending text.
call('racequota', 'text-publications/' + race_rows[0]['publication_id'] + '/withdraw', {'revision': 1})
with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
    first = pool.submit(new_text, 'racequota')
    second = pool.submit(call, 'racequota', 'text-publications/' + race_rows[1]['publication_id'] + '/edit', {'revision': 1, 'text': 'Édition concurrente.'})
    mixed = [first.result(), second.result()]
check('mixed creation and edit cannot overflow pending cap', mixed[0][0] == 201 and mixed[1][0] in [200, 429] and sql("SELECT COUNT(*) FROM wp_faluss_fans_text_publications WHERE creator_id='" + SESSIONS['racequota']['creator_id'] + "' AND state='pending'") == '20')

# Shared rate allowance across creation and edits; concurrent edits target different rows.
churn_key = str(uuid.uuid4())
status, churn, _ = new_text('churn', key=churn_key)
status, second, _ = new_text('churn')
for _ in range(27):
    status, churn, _ = call('churn', 'text-publications/' + churn['publication_id'] + '/edit', {'revision': int(churn['revision']), 'text': 'Révision financée de recette.'})
    if status != 200:
        raise AssertionError('hourly fixture edits failed')
with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
    futures = [pool.submit(call, 'churn', 'text-publications/' + item['publication_id'] + '/edit', {'revision': int(item['revision']), 'text': 'Dernière place.'}) for item in [churn, second]]
    results = [f.result() for f in futures]
check('creation plus edits share hourly cap under concurrency', sorted(r[0] for r in results) == [200, 429])
check('new creation also blocked by edit quota', new_text('churn')[1]['code'] == 'publication_hourly_quota')
before = audit_count()
check('replay succeeds without consuming quota at hourly cap', new_text('churn', key=churn_key)[0] == 201 and audit_count() == before)
latest = call('churn', 'text-publications/' + churn['publication_id'] + '/private')[1]
check('withdrawal still works at hourly cap', call('churn', 'text-publications/' + churn['publication_id'] + '/withdraw', {'revision': int(latest['revision'])})[0] == 200)
latest = call('admin', 'text-publications/' + second['publication_id'] + '/private')[1]
check('rejection still works at hourly cap', moderate(latest, 'reject', 'needs_revision')[0] == 200)
check('replay never resurrects withdrawn body', new_text('churn', key=churn_key)[1]['body'] == '')

# Advance synthetic audit timestamps, without changing real clocks or production limits.
daily = new_text('daily')[1]
for i in range(99):
    if i % 25 == 0:
        sql("UPDATE wp_faluss_fans_text_decisions SET occurred_at=UTC_TIMESTAMP()-INTERVAL 2 HOUR WHERE publication_id='" + daily['publication_id'] + "'")
    status, daily, _ = call('daily', 'text-publications/' + daily['publication_id'] + '/edit', {'revision': int(daily['revision']), 'text': 'Révision journalière.'})
    if status != 200:
        raise AssertionError('daily fixture failed')
check('daily creation and edit allowance exhausted', new_text('daily')[1]['code'] == 'publication_daily_quota')
check('daily allowance also blocks edit', call('daily', 'text-publications/' + daily['publication_id'] + '/edit', {'revision': int(daily['revision']), 'text': 'Refus.'})[0] == 429)
sql("UPDATE wp_faluss_fans_text_decisions SET occurred_at=UTC_TIMESTAMP()-INTERVAL 25 HOUR WHERE publication_id='" + daily['publication_id'] + "'")
check('expired rolling window releases allowance', new_text('daily')[0] == 201)

# Public pagination: >20 approved texts, another active creator and newest suspended texts.
for who, count in [('owner', 24), ('other', 8), ('racer', 22)]:
    for i in range(count):
        status, item, _ = new_text(who, 'Texte public ' + str(i))
        if status != 201 or moderate(item)[0] != 200:
            raise AssertionError('public pagination fixture failed for ' + who)
call('admin', 'creators/' + SESSIONS['racer']['creator_id'] + '/status', {'status': 'suspended'})

def pages(who, route):
    cursor = None; ids = []; page_count = 0
    while True:
        suffix = '?per_page=20' + (('&cursor=' + urllib.parse.quote(cursor)) if cursor else '')
        status, page, _ = call(who, route + suffix)
        if status != 200:
            raise AssertionError('pagination request failed')
        ids.extend(item['publication_id'] for item in page['items'])
        check('page bounded and full when continued: ' + route, len(page['items']) <= 20 and (page['next_cursor'] is None or len(page['items']) == 20))
        page_count += 1
        cursor = page['next_cursor']
        if not cursor:
            break
        if page_count > 30:
            raise AssertionError('nonterminating pagination')
    check('successive pages contain no duplicates: ' + route, len(ids) == len(set(ids)) and page_count > 1)
    return ids

expected_public = sql("SELECT p.publication_id FROM wp_faluss_fans_text_publications p JOIN wp_faluss_fans_creator_profiles c ON c.creator_id=p.creator_id WHERE p.state='approved' AND c.status='active' ORDER BY p.updated_at DESC,p.publication_id DESC").splitlines()
check('public pagination equals independent SQL oracle', pages('anon', 'text-publications') == expected_public and len(expected_public) == 32)
expected_own = sql("SELECT publication_id FROM wp_faluss_fans_text_publications WHERE creator_id='" + SESSIONS['owner']['creator_id'] + "' ORDER BY updated_at DESC,publication_id DESC").splitlines()
check('creator reaches entire history', pages('owner', 'text-publications/mine') == expected_own)
expected_queue = sql("SELECT publication_id FROM wp_faluss_fans_text_publications WHERE state='pending' ORDER BY updated_at,publication_id").splitlines()
check('admin reaches entire moderation queue', pages('admin', 'text-publications/moderation') == expected_queue)
check('third table is private and transactional', sql("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='wp_faluss_fans_text_requests'") == 'InnoDB')
