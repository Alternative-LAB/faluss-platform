"""Complete private derived generations, not active rankings or new economic writes."""
import concurrent.futures
import copy
import hashlib
import json
import secrets
import uuid


def run_checks(root,source,cli_path,check,command,fixture,reader,hub):
    origin=fixture['origin'];fans=fixture['fans'];sql=fixture['fan_sql'];table='wp_fans_hof_b4_generation'
    def invoke(action,selected=origin,**extra):
        fixture['set_clock']()
        path=root/(uuid.uuid4().hex+'.json');path.write_text(json.dumps(dict(action='cache-'+action,origin=selected,**extra)));path.chmod(0o600)
        return json.loads(command(['php',cli_path,'--allow-root','--path='+str(fans),'eval-file',
                                  str(source/'tests/TokenEngine/recipe/b3-corpus-inbox-worker.php'),str(path),'--use-include']))
    def lit(value):return "'"+value.replace('\\','\\\\').replace("'","''")+"'"
    def stored():return sql('SELECT document_json,document_sha256 FROM '+table+' ORDER BY origin_id,policy_version')
    check('B4b normal activation has no ranking cache',sql("SHOW TABLES LIKE 'wp_fans_hof_b4_%'")=='')
    check('B4b readiness never creates tables',invoke('ready')==dict(ready=False))
    sql('CREATE TABLE '+table+' (id INT NOT NULL) ENGINE=InnoDB')
    check('B4b partial schema is refused without adoption',invoke('install')==dict(error='model_schema_divergent'))
    sql('DROP TABLE '+table)
    check('B4b explicit two-table InnoDB installation is idempotent',invoke('install')==dict(ready=True)
          and invoke('install')==dict(ready=True) and len(sql("SHOW TABLES LIKE 'wp_fans_hof_b4_%'").splitlines())==2)
    absent=invoke('read')
    if absent!=dict(error='hof_projection_not_reconciled'):
        raise RuntimeError('Expurgated B4 cache diagnostics: '+json.dumps(dict(error=absent.get('error','unexpected result'))))
    check('B4b absent cache never fabricates a reconciled ranking',True)
    first=invoke('rebuild');value=invoke('read');document=value['document']
    check('B4b current corpus persists separate general and category projections',first['state']=='reconciled'
          and value['generation']==first['generation'] and sum(int(x['points']) for x in document['general']['fans'])==2
          and sum(int(x['points']) for x in document['general']['creators'])==2 and set(document['categories'])=={'arts','music','games','learning','lifestyle'})
    check('B4b monthly statements retain net points without rank fields',
          sum(int(x['points']) for month in document['months'].values() for x in month['fans'])==2
          and all(set(row)=={'faluss_id','points'} for month in document['months'].values() for family in ('fans','creators') for row in month[family]))
    before=stored()
    with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:
        answers=list(pool.map(lambda _:invoke('rebuild'),range(6)))
    check('B4b concurrent rebuilds preserve one deterministic generation',all(answer==first for answer in answers) and stored()==before)
    check('B4b insert failure after delete rolls back the entire generation',invoke('rebuild',fault='cache-insert')==dict(error='hof_projection_storage_unavailable')
          and stored()==before and invoke('read')['generation']==first['generation'])
    failed=invoke('rebuild',fault='commit-unknown')
    check('B4b lost local COMMIT acknowledgement is unknown then primary read recovers',
          failed==dict(error='pf_local_corpus_commit_unknown') and invoke('read')['generation']==first['generation'] and stored()==before)
    # Starting the next primary check invalidates the prior current corpus immediately.
    pending=str(uuid.uuid4());reader(pending,1);before=stored()
    check('B4b incomplete new corpus blocks both stale read and rebuild',invoke('read')==dict(error='hof_projection_source_unavailable')
          and invoke('rebuild')==dict(error='hof_projection_source_unavailable') and stored()==before)
    check('B4b complete fresh attestation requires a matching cache rebuild',reader(pending)['state']=='verified'
          and invoke('read')==dict(error='hof_projection_not_reconciled'))
    newer=invoke('rebuild');check('B4b old trigger only rebuilds the current authenticated generation',invoke('read')['generation']==newer['generation'])
    storage_document=dict(document,months=[dict(month=month,**statement) for month,statement in sorted(document['months'].items())])
    old_bytes=json.dumps(storage_document,sort_keys=True,separators=(',',':'))
    sql('UPDATE '+table+' SET document_json='+lit(old_bytes)+',document_sha256='+lit(hashlib.sha256(old_bytes.encode()).hexdigest())+' WHERE origin_id='+lit(origin))
    check('B4b restoring old cache bytes with a matching hash cannot restore old results',invoke('read')==dict(error='hof_projection_not_reconciled'))
    check('B4b cache is reconstructible from current proof after corruption',invoke('rebuild')==newer and invoke('read')['generation']==newer['generation'])
    sql('ALTER TABLE '+table+' ENGINE=MyISAM')
    check('B4b nontransactional cache closes all access',invoke('read')==dict(error='hof_projection_schema_unavailable')
          and invoke('rebuild')==dict(error='hof_projection_schema_unavailable'))
    sql('ALTER TABLE '+table+' ENGINE=InnoDB')
    # No input of raw receipts or caller-chosen quantities can replace a complete corpus.
    empty=str(uuid.uuid4());fixture['admit_origin'](empty)
    check('B4b unknown origin has no implicit zero score',invoke('rebuild',empty)==dict(error='hof_projection_source_unavailable'))
    check('B4b attested empty origin stores empty projections without fabricated participants',reader(str(uuid.uuid4()),selected=empty)['state']=='verified'
          and invoke('rebuild',empty)['state']=='reconciled' and invoke('read',empty)['document']['general']==dict(fans=[],creators=[]))
    policies=fixture['policies'];revoked=copy.deepcopy(policies['fans']);revoked['keys']['recipe-hub-k1']['state']='revoked';fixture['policy']('fans',revoked)
    before=stored()
    check('B4b revoked Hub trust cannot serve or rebuild a cached score','error' in invoke('read') and 'error' in invoke('rebuild') and stored()==before)
    fixture['policy']('fans',policies['fans'])
    check('B4b explicit restored fixture trust permits the still matching generation',invoke('read')['generation']==newer['generation'])
    pack=dict(fixture['proof'],purchase_id='synthetic.'+uuid.uuid4().hex,evidence_id=str(uuid.uuid4()),
              member_faluss_id=str(uuid.uuid4()),purchased_pf='5')
    lot=hub('h1-evidence',payload=pack,key=secrets.token_hex(32))['lot_id']
    assert hub('h2-admit',lot_id=lot,member=pack['member_faluss_id'],key=secrets.token_hex(32))['state']=='admitted'
    fixture['consume'](dict(fixture['intent'],attribution_id=str(uuid.uuid4()),member_faluss_id=pack['member_faluss_id'],purchased_pf='5'))
    def refresh_score(expected):
        assert reader(str(uuid.uuid4()))['state']=='verified'
        assert invoke('read')==dict(error='hof_projection_not_reconciled')
        invoke('rebuild');doc=invoke('read')['document']
        return sum(int(row['points']) for row in doc['general']['fans'])==expected and sum(int(row['points']) for row in doc['general']['creators'])==expected
    check('B4b one confirmed new allocation feeds both families without another consumption',refresh_score(7))
    stale=invoke('read');months=set(stale['document']['months'])
    for revision,state,cancelled,expected in [(2,'partially_cancelled',2,5),(4,'disputed',2,2),(5,'partially_cancelled',2,5),(6,'cancelled',5,2)]:
        correction=dict(pack,source_revision=str(revision),evidence_id=str(uuid.uuid4()),state=state,cancelled_purchased_pf_cumulative=str(cancelled))
        key=secrets.token_hex(32);plan=hub('h4-begin',payload=correction,key=key)
        if revision==2:
            check('B4b incomplete correction cannot produce an exact cache',reader(str(uuid.uuid4()))['state']=='unavailable'
                  and invoke('read')==dict(error='hof_projection_source_unavailable') and invoke('rebuild')==dict(error='hof_projection_source_unavailable'))
        for fragment in range(int(plan['next_fragment'])+1,int(plan['fragment_count'])+1):
            hub('h4-resume',member=pack['member_faluss_id'],plan_id=plan['plan_id'],fragment=str(fragment))
        check('B4b correction revision '+str(revision)+' rebuilds the latest net in every family',refresh_score(expected)
              and set(invoke('read')['document']['months'])==months)
        if revision==4:
            older=dict(pack,source_revision='3',evidence_id=str(uuid.uuid4()),state='partially_cancelled',cancelled_purchased_pf_cumulative='2')
            before=stored()
            check('B4b delayed older correction cannot restore disputed points','error' in hub('h4-begin',payload=older,key=secrets.token_hex(32))
                  and invoke('read')['document']['general']['fans']!=stale['document']['general']['fans'] and stored()==before)
    check('B4b projections add no parallel Fans ledger',sql("SHOW TABLES LIKE 'wp_fans%ledger%'")=='')
