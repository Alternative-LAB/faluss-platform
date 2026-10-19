"""Actual SSO/profile/consent primary bindings, not Hub freshness or public delivery."""
from b1_checks import actors, call, data, check, checks, root, sql, start, finish, state
import json
import subprocess
import time
import uuid


def run_checks():
    baseline = len(checks)
    member = actors['member']
    creator = state['profiles']['member']
    links = json.loads(sql("SELECT JSON_OBJECT('subject',faluss_id,'created',created_at,'proved',last_proved_at) FROM wp_faluss_fans_identity_links WHERE wp_user_id="+str(member)))
    subject = links['subject']
    unknown = str(uuid.uuid4())

    def binding(subject=subject, **values):
        return data('guest', 'identity_transaction', member=member, subject=subject, **values)

    def own():
        return data('member', 'own')

    check('B6id Faluss identity and local creator UUID are distinct', subject != creator)
    check('B6id caller transaction is mandatory for all binding reads',
          data('guest', 'identity_outside', member=member, subject=subject) ==
          dict(member=None, subject=None, creator=None, error='hof_primary_transaction_required'))
    first = binding()
    check('B6id stored identity resolves to the real linked local member and active creator',
          first['member'] == member and first['subject'] == subject and first['creator'] == creator)
    check('B6id binding does not publish a withdrawn alias or absent creator consent',
          first['fan_name'] is None and first['creator_name'] is None)
    check('B6id matching no identity creates no account and returns no email fallback', binding(unknown)['member'] is None)
    check('B6id malformed technical identity is refused', binding('member@example.invalid')['member'] is None)
    check('B6id local creator ID is never treated as the account identity', binding(creator)['member'] is None)
    check('B6id primary connection must be shared with module contracts',
          data('guest', 'identity_wrong_connection', member=member, subject=subject)['error'] == 'hof_primary_connection_required')
    check('B6id binding preserves its caller transaction and rollback',
          first['transaction_preserved'] and first['probe_rows_after_rollback'] == '0')

    submitted = data('member', 'submit', alias='Approved recipe fan', revision=int(own()['revision']))
    data('member', 'consent', family='fan', enabled=True, revision=int(submitted['revision']))
    check('B6id an unapproved alias remains private despite consent and correct binding', binding()['fan_name'] is None)
    data('admin', 'decide', member=member, revision=int(own()['revision']), decision='approve', reason='allowed_alias')
    data('member', 'consent', family='creator', enabled=True, revision=int(own()['revision']))
    check('B6id approved identities and separate consent resolve through their owners',
          binding()['fan_name'] == 'Approved recipe fan' and binding()['creator_name'] == 'Local creator fixture')

    ranked = [dict(faluss_id=subject, points='12', position='1', reached_at='2026-10-05 10:00:00.123456', consumption_order='1')]
    check('B6id display projection needs no client supplied identity mapping',
          binding(ranked=ranked, family='creator')['display'] == [dict(name='Local creator fixture', points='12', position='1')])
    check('B6id display output contains no technical identity or tie break chronology',
          binding(ranked=ranked, family='fan')['display'] == [dict(name='Approved recipe fan', points='12', position='1')])

    data('member', 'editorial_submit', revision=5)
    check('B6id pending editorial revision retains only the previously approved presentation', binding()['creator_name'] == 'Local creator fixture')
    data('admin', 'editorial_decide', creator=creator, revision=6, decision='reject', reason='needs_revision')
    check('B6id rejected revision never replaces approved identity', binding()['creator_name'] == 'Local creator fixture')
    data('admin', 'admit', creator=creator, status='suspended')
    check('B6id suspended profile cannot resolve a public creator, fan binding remains separate',
          binding()['creator'] is None and binding()['fan_name'] == 'Approved recipe fan')
    data('admin', 'admit', creator=creator, status='active')
    check('B6id readmission uses the current active profile and approved presentation', binding()['creator_name'] == 'Local creator fixture')
    data('member', 'consent', family='creator', enabled=False, revision=int(own()['revision']))
    check('B6id current consent withdrawal hides creator without removing its binding',
          binding()['creator_name'] is None and binding()['creator'] == creator)

    for mutation in ['administrator', 'editor', 'multiple_roles', 'missing_role', 'duplicate_role', 'malformed_role', 'delete_account', 'unlink']:
        value = binding(mutate=mutation)
        check('B6id primary binding refuses '+mutation+' and retains caller rollback',
              value['member'] is None and value['subject'] is None and value['creator'] is None
              and value['fan_name'] is None and value['transaction_preserved'] and value['probe_rows_after_rollback'] == '0')
        if mutation in ['administrator', 'editor', 'multiple_roles', 'missing_role', 'duplicate_role', 'malformed_role', 'delete_account']:
            check('B6id cached WordPress member cannot authorize '+mutation, value['cached_member'] is True)
        check('B6id rolled back '+mutation+' does not change the stored binding', binding()['member'] == member)

    try:
        sql('ALTER TABLE wp_usermeta ENGINE=MyISAM')
        check('B6id nontransactional account metadata fails closed without auto repair', binding()['member'] is None)
    finally:
        sql('ALTER TABLE wp_usermeta ENGINE=InnoDB')
    try:
        sql('SET GLOBAL read_only=1')
        check('B6id a replica cannot authorize the primary delivery transaction',
              call('guest', 'identity_transaction', member=member, subject=subject).get('error') == 'hof_primary_transaction_required')
    finally:
        sql('SET GLOBAL read_only=0')

    guard = root/uuid.uuid4().hex
    held = start('guest', 'identity_transaction', member=member, subject=subject, hold=str(guard))
    writer = None

    def wait_for(predicate, process):
        deadline = time.monotonic()+20
        while not predicate():
            if process.poll() is not None or time.monotonic() > deadline:
                raise RuntimeError('Private identity checkpoint unavailable; process_exit='+str(process.poll()))
            time.sleep(.05)

    try:
        wait_for(lambda: guard.with_name(guard.name+'.ready').exists(), held)
        checkpoint = json.loads(guard.with_name(guard.name+'.ready').read_text())
        check('B6id concurrent reader resolved the real member inside its active transaction',
              checkpoint == dict(member_resolved=True, transaction_active=True))
        writer = subprocess.Popen(['mariadb', '--no-defaults', '--socket='+str(root/'sql.sock'), '-uroot', 'admission_recipe', '-e',
                                   'DELETE FROM wp_faluss_fans_identity_links WHERE wp_user_id='+str(member)],
                                  stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        wait_for(lambda: int(sql("SELECT COUNT(*) FROM information_schema.innodb_trx WHERE trx_state='LOCK WAIT'")) > 0, writer)
        check('B6id concurrent unlink waits for the held primary binding', writer.poll() is None)
    finally:
        guard.with_name(guard.name+'.release').touch()
        if held.poll() is None:
            held.wait(timeout=20)
        if writer is not None:
            writer.communicate(timeout=20)
    check('B6id concurrent unlink commits after rollback and the next binding is absent',
          finish(held)['data']['member'] == member and writer is not None and writer.returncode == 0 and binding()['member'] is None)
    sql("INSERT INTO wp_faluss_fans_identity_links (wp_user_id,faluss_id,created_at,last_proved_at) VALUES ("
        +str(member)+",'"+subject+"','"+links['created']+"','"+links['proved']+"')")
    check('B6id fixture link restoration alone does not restore withdrawn creator consent',
          binding()['creator'] == creator and binding()['creator_name'] is None)
    return len(checks)-baseline
