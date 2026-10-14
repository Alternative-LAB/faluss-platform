"""Actual barrier HTTP and complete cache on the same private Fans primary; no simulated ACK."""
import copy
import hashlib
import json
import secrets
import time
import uuid


def run_checks(root,source,cli_path,check,command,fixture,hub_sql,hub,delivery_enabled=False):
    # The prefix's direct owner registration has a different durable key/context; it cannot be adopted.
    origin=str(uuid.uuid4());sql=fixture['fan_sql']
    endpoint=(root/'corpus-endpoint').read_text().replace('/corpus','/barrier')
    def invoke(role,action,**extra):
        path=root/(uuid.uuid4().hex+'.json')
        path.write_text(json.dumps(dict(action=action,origin=origin,endpoint=endpoint,**extra)));path.chmod(0o600)
        node=root/'wordpress' if role=='hub' else fixture['fans']
        return json.loads(command(['php',cli_path,'--allow-root','--path='+str(node),'eval-file',
            str(source/'tests/TokenEngine/recipe/b6-joined-worker.php'),str(path),'--use-include']))
    def deliver(action,**extra):
        path=root/(uuid.uuid4().hex+'.json')
        fixture['set_clock']()
        clock=(root/'corpus-primary-clock').read_text().strip()
        path.write_text(json.dumps(dict(action=action,origin=origin,endpoint=endpoint,at=clock,**extra)));path.chmod(0o600)
        return json.loads(command(['php',cli_path,'--allow-root','--path='+str(fixture['fans']),'eval-file',
            str(source/'tests/TokenEngine/recipe/b6-delivery-worker.php'),str(path),'--use-include']))
    ledger=hub_sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id')
    historical=hub_sql('SELECT * FROM wp_token_engine_ledger ORDER BY id')
    policies={}
    for role in ('hub','fans'):
        policies[role]=copy.deepcopy(fixture['policies'][role])
        policies[role]['permissions']+=['pf.ranking.context.register','pf.ranking.context.close','pf.lookup']
        fixture['policy'](role,policies[role])
    check('B6join explicit same-node fixture installs separate barrier metadata',
        invoke('hub','install')==dict(ready=True) and invoke('fans','install')==dict(ready=True))
    marker=root/'b6-joined-enabled';marker.write_text('fixture-only');marker.chmod(0o600)
    descriptor=invoke('fans','describe')
    fields=dict(operation='register',action_id=str(uuid.uuid4()),origin_id=origin,policy_version='1.0.0',lookup_operation='',object=descriptor)
    prepared=deliver('prepare-origin',descriptor=descriptor) if delivery_enabled else invoke('fans','prepare',fields=fields)
    if delivery_enabled:fields=prepared['fields']
    if 'error' in prepared:
        raise RuntimeError('B6join private preparation refused: '+str(prepared['error']))
    check('B6join the complete immutable action is persisted before any HTTP',
        prepared['fields']==fields and prepared['action_id']==fields['action_id']
        and prepared['phase']=='lookup' and prepared['state']=='opening')
    check('B6join an unacknowledged origin cannot compose a complete cache',
        'error' in invoke('fans','compose',action_id=prepared['action_id']))
    reply=invoke('fans','advance',action_id=prepared['action_id'])
    if reply.get('state')!='acknowledged':
        raise RuntimeError('B6join signed origin diagnostics: '+json.dumps({
            **{key:reply.get(key) for key in ('state','reason','completed_steps')},
            'hub_reason':(root/'b6-barrier-diagnostic').read_text() if (root/'b6-barrier-diagnostic').exists() else 'none'}))
    check('B6join genuine signed HTTP attests the same authoritative origin',reply['state']=='acknowledged')
    ack=invoke('fans','ack',action_id=prepared['action_id'])
    check('B6join attested descriptor and corpus origin are identical',
        ack['fields']['object']==descriptor and ack['result']['state']=='active')
    if delivery_enabled:
        applied=deliver('apply-origin',action_id=fields['action_id'])
        check('B6dl actual local origin opens only from the real signed primary ACK',applied.get('state')=='open')
        check('B6dl historical origin ACK alone never delivers current rankings','error' in deliver('ranking',family='creator'))
        deliver('prepare-read',action_id=fields['action_id'])
        assert deliver('advance-read',action_id=fields['action_id'])['phase']=='current'
    read=str(uuid.uuid4());fixture['set_clock']()
    collected=fixture['inbox']('advance',origin,read_id=read,steps=4)
    for delay in (1,2):
        if collected.get('state')!='pending' or collected.get('reason')!='pf_transport_unknown':break
        prior=fixture['inbox']('prepare',origin,read_id=read);time.sleep(delay)
        collected=fixture['inbox']('advance',origin,read_id=read,steps=4)
        assert fixture['inbox']('prepare',origin,read_id=read)['read_key']==prior['read_key']
    check('B6join the genuine origin owns a complete signed empty corpus',
        collected.get('state')=='verified' and fixture['inbox']('current',origin)['current']['manifest']['fact_count']=='0')
    fixture['inbox']('cache-rebuild',origin)
    # One fictitious official consumption, not one debit per derived projection.
    purchase=dict(fixture['proof'],purchase_id='synthetic.'+uuid.uuid4().hex,evidence_id=str(uuid.uuid4()),
        member_faluss_id=str(uuid.uuid4()),purchased_pf='3')
    lot=hub('h1-evidence',payload=purchase,key=secrets.token_hex(32))['lot_id']
    hub('h2-admit',lot_id=lot,member=purchase['member_faluss_id'],key=secrets.token_hex(32))
    fixture['set_clock']();assert fixture['inbox']('refresh',origin,read_id=read,steps=1)['state']=='verified'
    fixture['inbox']('cache-rebuild',origin);pack_only=fixture['inbox']('cache-read',origin)['document']
    check('B6join an admitted pack alone creates no Fan or Creator point',
        pack_only['general']['fans']==[] and pack_only['general']['creators']==[])
    if delivery_enabled:
        check('B6dl complete pack-only corpus delivers real empty rankings',deliver('ranking',family='creator')['items']==[] and deliver('ranking',family='fan')['items']==[])
    intent=copy.deepcopy(fixture['intent']);intent.update(attribution_id=str(uuid.uuid4()),
        member_faluss_id=purchase['member_faluss_id'],purchased_pf='3')
    intent['ranking_context'].update(origin_id=origin,sessions=[])
    intent['context_sha256']=hashlib.sha256(json.dumps(intent['ranking_context'],sort_keys=True,separators=(',',':')).encode()).hexdigest()
    for reference in hub('b3b-refs',payload=intent)['references']:
        if reference['content']['kind']=='origin':continue
        hub('b3b-register',payload=dict(descriptor,content=reference['content']),key=secrets.token_hex(32))
    receipt_count=int(hub_sql('SELECT COUNT(*) FROM wp_token_engine_pf_b3r_receipts'))
    fixture['consume'](intent)
    fixture['set_clock']()
    check('B6join a new confirmed fact closes the earlier entire signed cache',
        fixture['inbox']('refresh',origin,read_id=read,steps=1)['state']=='unavailable'
        and 'error' in fixture['inbox']('cache-read',origin))
    read=str(uuid.uuid4());assert fixture['inbox']('advance',origin,read_id=read,steps=4)['state']=='verified'
    fixture['inbox']('cache-rebuild',origin);value=fixture['inbox']('cache-read',origin)['document']
    check('B6join one official consumption feeds both exact Fan and Creator projections',
        sum(int(x['points']) for x in value['general']['fans'])==3
        and sum(int(x['points']) for x in value['general']['creators'])==3
        and int(hub_sql('SELECT COUNT(*) FROM wp_token_engine_pf_b3r_receipts'))==receipt_count+1)
    if delivery_enabled:
        actors=deliver('seed',member_faluss_id=intent['member_faluss_id'],creator_faluss_id=intent['creator_faluss_id'],category=intent['ranking_context']['creator_category'])
        check('B6dl creator profile UUID differs from the attested Identity UUID',actors['creator_profile']!=intent['creator_faluss_id'])
        month=next(iter(value['months']))
        check('B6dl actual current creator and fan delivery uses approved identities',
            deliver('ranking',family='creator')['items']==[dict(name='Atelier de recette B6',points='3',position='1')]
            and deliver('ranking',family='fan')['items']==[dict(name='Fan de recette B6',points='3',position='1')])
        check('B6dl historical category projection stays tied to the attested context',
            deliver('ranking',family='creator',category=intent['ranking_context']['creator_category'])['items'][0]['points']=='3')
        check('B6dl private statement is the current linked member only',
            deliver('statement',actor=actors['fan_user'],family='fan',month=month)['points']=='3'
            and deliver('statement',actor=actors['creator_user'],family='fan',month=month)['points']=='0'
            and deliver('statement',actor=actors['creator_user'],family='creator',month=month)['points']=='3')
        check('B6dl guest cannot read a private statement',deliver('statement',family='fan',month=month)==dict(error='hof_linked_member_required'))
        deliver('consent',actor=actors['fan_user'],family='fan',enabled=False)
        check('B6dl withdrawal suppresses public identity without erasing the private statement',
            deliver('ranking',family='fan')['items']==[] and deliver('statement',actor=actors['fan_user'],family='fan',month=month)['points']=='3')
        deliver('suspend',creator_profile=actors['creator_profile'],status='suspended')
        check('B6dl suspension hides creator but preserves corrected private history',
            deliver('ranking',family='creator')['items']==[] and deliver('statement',actor=actors['creator_user'],family='creator',month=month)['points']=='3')
        deliver('suspend',creator_profile=actors['creator_profile'],status='active')
        check('B6dl readmission rechecks the approved presentation',deliver('ranking',family='creator')['items'][0]['points']=='3')
    correction=dict(purchase,source_revision='2',evidence_id=str(uuid.uuid4()),state='partially_cancelled',cancelled_purchased_pf_cumulative='1')
    plan=hub('h4-begin',payload=correction,key=secrets.token_hex(32))
    check('B6join an incomplete owner correction suppresses the complete old cache',
        fixture['inbox']('refresh',origin,read_id=read,steps=1)['state']=='unavailable')
    if delivery_enabled:
        check('B6dl incomplete authoritative correction closes public and private delivery',
            'error' in deliver('ranking',family='creator') and 'error' in deliver('statement',actor=actors['fan_user'],family='fan',month=month))
    for fragment in range(int(plan['next_fragment'])+1,int(plan['fragment_count'])+1):
        hub('h4-resume',member=purchase['member_faluss_id'],plan_id=plan['plan_id'],fragment=str(fragment))
    read=str(uuid.uuid4());assert fixture['inbox']('advance',origin,read_id=read,steps=4)['state']=='verified'
    fixture['inbox']('cache-rebuild',origin);value=fixture['inbox']('cache-read',origin)['document']
    check('B6join the latest attested correction rebuilds both families without second consumption',
        sum(int(x['points']) for x in value['general']['fans'])==2
        and sum(int(x['points']) for x in value['general']['creators'])==2
        and int(hub_sql('SELECT COUNT(*) FROM wp_token_engine_pf_b3r_receipts'))==receipt_count+1)
    if delivery_enabled:
        check('B6dl latest correction reaches current delivery without restoring withdrawn identity',
            deliver('ranking',family='creator')['items'][0]['points']=='2' and deliver('ranking',family='fan')['items']==[]
            and deliver('statement',actor=actors['fan_user'],family='fan',month=month)['points']=='2')
        deliver('prepare-read',action_id=fields['action_id'])
        check('B6dl primary recheck uncertainty closes the entire delivery','error' in deliver('ranking',family='creator'))
        assert deliver('advance-read',action_id=fields['action_id'])['phase']=='current'
    ledger=hub_sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id')
    sql('CREATE TABLE wp_b6_joined_probe (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB')
    before=fixture['inbox']('cache-read',origin)
    rejected=invoke('fans','compose',action_id=prepared['action_id'],reject=True)
    check('B6join rejected application rolls back the real shared transaction',
        rejected==dict(error='pf_fixture_apply_refused') and sql('SELECT COUNT(*) FROM wp_b6_joined_probe')=='0'
        and fixture['inbox']('cache-read',origin)==before)
    accepted=invoke('fans','compose',action_id=prepared['action_id'])
    check('B6join authenticated barrier and exact cache commit in one owner transaction',
        accepted==dict(state='reconciled',generation=before['generation'],transaction='1')
        and sql('SELECT COUNT(*) FROM wp_b6_joined_probe')=='1')
    sql('DELETE FROM wp_b6_joined_probe')
    revoked=copy.deepcopy(policies['fans']);revoked['keys']['recipe-hub-k1']['state']='revoked';fixture['policy']('fans',revoked)
    check('B6join revoked current Hub trust suppresses the entire composed delivery',
        'error' in invoke('fans','compose',action_id=prepared['action_id']) and sql('SELECT COUNT(*) FROM wp_b6_joined_probe')=='0')
    fixture['policy']('fans',policies['fans'])
    close=dict(fields,operation='close',action_id=str(uuid.uuid4()),object={
        name:ack['result'][name] for name in ('barrier_key','version','content_sha256')}|dict(reason='origin_closed'))
    closing=invoke('fans','prepare',fields=close)
    check('B6join local closing suppresses composed delivery before network acknowledgement',
        'error' in invoke('fans','compose',action_id=prepared['action_id']) and sql('SELECT COUNT(*) FROM wp_b6_joined_probe')=='0')
    if delivery_enabled:check('B6dl local closing suppresses the real delivery before acknowledgement','error' in deliver('ranking',family='creator'))
    check('B6join the same signed close reaches its primary attested state',
        invoke('fans','advance',action_id=closing['action_id'])['state']=='acknowledged')
    check('B6join closed origin cannot deliver an old cache or old register ACK',
        'error' in invoke('fans','compose',action_id=prepared['action_id']) and sql('SELECT COUNT(*) FROM wp_b6_joined_probe')=='0')
    if delivery_enabled:check('B6dl closed origin never restores an old signed ranking','error' in deliver('ranking',family='creator'))
    check('B6join actual composition preserves both ledgers without a Fans ledger',
        hub_sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id')==ledger
        and hub_sql('SELECT * FROM wp_token_engine_ledger ORDER BY id')==historical
        and sql("SHOW TABLES LIKE 'wp_fans%ledger%'")=='')
