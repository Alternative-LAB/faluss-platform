"""Caller-owned corpus/cache transaction, not an authenticated barrier or public source."""
import concurrent.futures
import copy
import json
import time
import uuid


def run_checks(root,source,cli_path,check,command,fixture,reader):
    origin=fixture['origin'];sql=fixture['fan_sql'];worker=source/'tests/TokenEngine/recipe/b3-corpus-inbox-worker.php'
    def invoke(action='cache-compose',**extra):
        fixture['set_clock']();path=root/(uuid.uuid4().hex+'.json')
        path.write_text(json.dumps(dict(action=action,origin=origin,**extra)));path.chmod(0o600)
        return json.loads(command(['php',cli_path,'--allow-root','--path='+str(fixture['fans']),
                                  'eval-file',str(worker),str(path),'--use-include']))
    def count():return int(sql('SELECT COUNT(*) FROM wp_corpus_tx_probe'))
    def reset():sql('DELETE FROM wp_corpus_tx_probe')
    def lit(value):return "'"+value.replace('\\','\\\\').replace("'","''")+"'"
    sql('CREATE TABLE wp_corpus_tx_probe (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB')
    try:
        ordinary=invoke('cache-read');expected=ordinary['generation']
        for scope in ('no-lock','no-transaction','wrong-origin'):
            check('B6tx '+scope+' cannot enter the composed corpus callback',
                  invoke(scope=scope,other_origin=str(uuid.uuid4()))==dict(error='pf_corpus_barrier_transaction_required') and count()==0)
        value=invoke(rollback=True)
        check('B6tx caller transaction remains active and rolls back its callback write',
              value==dict(state='reconciled',generation=expected,transaction='1',outer_transaction='1') and count()==0)
        value=invoke()
        check('B6tx caller COMMIT persists its callback with the exact current cache',
              value['generation']==expected and value['transaction']=='1' and count()==1
              and invoke('cache-read')['generation']==expected)
        reset()
        check('B6tx refused callback rolls back without an implicit inner commit',
              invoke(scope='reject')==dict(error='pf_fixture_apply_refused') and count()==0)
        for scope,reason in [('recursive','pf_corpus_barrier_transaction_required'),('ordinary-nested','nested_transaction_refused')]:
            check('B6tx '+scope+' cannot nest or leak caller metadata',invoke(scope=scope)==dict(error=reason) and count()==0)
        check('B6tx lost outer COMMIT is unknown and persisted metadata is recoverable on primary',
              invoke(fault='commit-unknown')==dict(error='pf_fixture_outer_commit_unknown') and count()==1
              and invoke('cache-read')['generation']==expected)
        reset()
        policies=fixture['policies'];revoked=copy.deepcopy(policies['fans']);revoked['keys']['recipe-hub-k1']['state']='revoked'
        fixture['policy']('fans',revoked)
        check('B6tx revoked current Hub trust cannot enter the callback','error' in invoke() and count()==0)
        fixture['policy']('fans',policies['fans'])
        before=sql('SELECT HEX(document_json),document_sha256 FROM wp_fans_hof_b4_generation WHERE origin_id='+lit(origin))
        sql('UPDATE wp_fans_hof_b4_generation SET document_sha256='+lit('a'*64)+' WHERE origin_id='+lit(origin))
        check('B6tx a mismatched derived cache refuses delivery inside the caller transaction',
              invoke()==dict(error='hof_projection_not_reconciled') and count()==0)
        invoke('cache-rebuild')
        check('B6tx reconstruction restores only the exact current derived generation',
              sql('SELECT HEX(document_json),document_sha256 FROM wp_fans_hof_b4_generation WHERE origin_id='+lit(origin))==before)
        marker=root/'b6-corpus-tx-held';release=root/'b6-corpus-tx-release';read=str(uuid.uuid4())
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            held=pool.submit(invoke,marker=str(marker),release=str(release))
            deadline=time.monotonic()+12
            while not marker.exists() and time.monotonic()<deadline:time.sleep(.025)
            assert marker.exists(),'Fixture composed reader did not acquire its locks'
            waiting=pool.submit(reader,read,1)
            deadline=time.monotonic()+5;observed=False
            while time.monotonic()<deadline:
                # Another private connection really waits on the origin mutex, not a mocked delay.
                if int(sql("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB=DATABASE() AND STATE='User lock'")):
                    observed=True;break
                time.sleep(.025)
            check('B6tx corpus replacement waits on the composed reader origin mutex',observed and not waiting.done())
            release.write_text('release');release.chmod(0o600)
            first=held.result(timeout=12);pending=waiting.result(timeout=12)
        check('B6tx replacement proceeds after caller COMMIT without changing its prior atomic result',
              first['generation']==expected and first['transaction']=='1' and pending['read_id']==read and count()==1)
        reset()
        check('B6tx collecting corpus closes composed delivery rather than returning the older cache',
              invoke()==dict(error='hof_projection_source_unavailable') and count()==0)
        assert reader(read)['state']=='verified'
        check('B6tx a fresh fence requires cache reconciliation before composed delivery',
              invoke()==dict(error='hof_projection_not_reconciled') and count()==0)
        invoke('cache-rebuild');value=invoke(rollback=True)
        check('B6tx reconciled fresh generation becomes readable without losing caller rollback',
              value['state']=='reconciled' and value['transaction']=='1' and value['generation']!=expected and count()==0)
        check('B6tx composition adds no parallel Fan economic ledger',sql("SHOW TABLES LIKE 'wp_fans%ledger%'")=='')
    finally:
        fixture['policy']('fans',fixture['policies']['fans']);sql('DROP TABLE IF EXISTS wp_corpus_tx_probe')
