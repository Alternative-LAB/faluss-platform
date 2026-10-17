"""Approved 1.2 private wire and durable inbox on the existing disposable pair."""
from concurrent.futures import ThreadPoolExecutor
from copy import deepcopy
import hashlib
import json
import secrets
import uuid


def run_checks(root,check,hub_sql,network):
    contract='hub.purchased-pf.ranking-barriers/1.2.0';reason='territory_admission_revoked'
    local=network['recovery'];invoke=local['invoke'];recover=local['recover'];advance=local['advance'];events=network['events']
    sign=network['sign'];http=network['http'];accept=network['accept'];table=local['table'];literal=local['literal'];sql=local['sql']
    ledger=hub_sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id')
    def fresh(kind='admission',territorial=True):
        _,refs,objects=network['fixture']()
        descriptor=deepcopy(next(value for value in objects if value['content']['kind']==kind))
        if kind=='admission' and territorial:
            descriptor['content'].update(territory_policy_revision=str(uuid.uuid4()),territory_admission_revision='7')
        opening=network['fields'](descriptor)
        assert local['prepare'](opening)['state']=='opening' and advance(opening)['state']=='acknowledged'
        reference=json.loads(sql('SELECT fields_json FROM '+table+'actions WHERE action_id='+literal(opening['action_id'])))['object']['content']
        raw=json.dumps(reference,sort_keys=True,separators=(',',':')).encode()
        identity={key:reference[key] for key in ('origin_id','kind','object_id','subject_id')}
        closing=dict(opening,operation='close',action_id=str(uuid.uuid4()),object=dict(
            barrier_key=hashlib.sha256(json.dumps(identity,sort_keys=True,separators=(',',':')).encode()).hexdigest(),
            version=reference['version'],content_sha256=hashlib.sha256(raw).hexdigest(),reason=reason))
        return opening,closing,descriptor
    def prepare(fields,**extra):return invoke('prepare',fields['origin_id'],fields=fields,contract=contract,**extra)
    def exchange(fields,key,**extra):
        sealed=sign(fields,key,contract=contract,**extra);status,wire,_=http(sealed['wire'])
        assert status==200,'Private territorial response refused: '+str(status)
        return accept(fields,sealed,wire,contract=contract)
    def stored(fields):return sql('SELECT fields_json FROM '+table+'actions WHERE action_id='+literal(fields['action_id']))
    opening,closing,descriptor=fresh();before=events();count=network['nonces']();key=secrets.token_hex(32)
    for version in ('1.0.0','1.1.0'):
        old='hub.purchased-pf.ranking-barriers/'+version
        sealed=sign(closing,key,contract=contract,request=dict(contract=old),context=dict(contract=old))
        check('B3bt '+version+' rejects territorial reason without nonce or event',
              http(sealed['wire'])[0]==403 and network['nonces']()==count and events()==before
              and invoke('prepare',closing['origin_id'],fields=closing,contract=old)==dict(error='pf_barrier_invalid_reason'))
    for label,extra in [('audience',dict(request=dict(audience='fixture.other'))),('unknown key',dict(request_key='unknown')),
                        ('context action',dict(context=dict(action_id=str(uuid.uuid4()))))]:
        sealed=sign(closing,key,contract=contract,**extra)
        check('B3bt '+label+' cannot admit a territorial request',http(sealed['wire'])[0]==403 and network['nonces']()==count and events()==before)
    sealed=sign(closing,key,contract=contract)
    check('B3bt altered territorial signature fails before nonce admission',
          http(network['bad_signature'](sealed['wire']))[0]==403 and network['nonces']()==count and events()==before)
    for label,fields in [('origin',dict(closing,origin_id=str(uuid.uuid4()))),
                         ('version',dict(closing,object=dict(closing['object'],version='2'))),
                         ('digest',dict(closing,object=dict(closing['object'],content_sha256='b'*64)))]:
        check('B3bt a forged '+label+' cannot adopt a sparse territorial reference',
              exchange(fields,key)['outcome']=='refused' and events()==before)
    for kind in ('origin','country','creator','session','admission'):
        wrong_open,wrong,_=fresh(kind,False);before=events()
        check('B3bt '+kind+' without territorial admission is rejected by Hub and Fans',
              exchange(wrong,secrets.token_hex(32))==dict(outcome='refused',result=dict(reason='pf_barrier_territory_requires_admission'))
              and prepare(wrong)==dict(error='pf_barrier_territory_requires_admission')
              and recover(wrong_open)['state']=='active' and events()==before)
    before=events()
    check('B3bt failed durable action insert rolls back local territorial closing',
          prepare(closing,fault='action-insert')==dict(error='pf_local_barrier_storage') and recover(opening)['state']=='active' and events()==before)
    check('B3bt lost local territorial COMMIT keeps the one versioned action',
          prepare(closing,fault='commit-unknown')==dict(error='pf_local_barrier_commit_unknown')
          and recover(closing)['state']=='closing' and recover(closing)['contract']==contract)
    saved=recover(closing);raw=stored(closing)
    check('B3bt territorial prepare replay preserves exact bytes and stable key',
          prepare(closing)['operation_key']==saved['operation_key'] and json.loads(raw)==dict(contract=contract,fields=closing))
    assert advance(closing,1)['state']=='pending'
    (root/'barrier-http-fault').write_text('drop-after-commit');lost=advance(closing,1)
    check('B3bt lost primary territorial close response leaves local choices closed under the same action',
          lost.get('reason')=='pf_transport_unknown' and recover(closing)['state']=='closing'
          and recover(closing)['operation_key']==saved['operation_key'] and events()==before+1)
    done=advance(closing,1,monitor=True)
    check('B3bt primary territorial lookup resolves one close outside every local transaction',
          done['state']=='acknowledged' and done['local_state']=='closed' and events()==before+1
          and done['http_trace']==[dict(transaction='0',mutex_free='1',registered='1')] and stored(closing)==raw)
    # The same old action can never close a future version after reapproval.
    future=deepcopy(descriptor);future['content'].update(version='2',territory_admission_revision='8',
        admitted_at=hub_sql('SELECT UTC_TIMESTAMP(6)'))
    future['valid_from']=max(future['content']['starts_at'],future['content']['admitted_at'])
    new_open=network['fields'](future);assert local['prepare'](new_open)['state']=='opening'
    assert advance(new_open)['state']=='acknowledged';after=events()
    check('B3bt reapproval creates only a new future admission and old close cannot rewrite it',
          recover(new_open)['state']=='active' and advance(closing)['completed_steps']=='0' and events()==after
          and recover(opening)['state']=='closed' and stored(closing)==raw)
    opening,closing,_=fresh();before=events();key=secrets.token_hex(32)
    sealed=[sign(closing,key,contract=contract) for _ in range(6)]
    with ThreadPoolExecutor(max_workers=6) as pool:responses=list(pool.map(lambda value:http(value['wire']),sealed))
    answers=[accept(closing,request,response[1],contract=contract) for request,response in zip(sealed,responses) if response[0]==200]
    check('B3bt concurrent territorial closings commit one exact primary instant and event',
          len(answers)==6 and all(answer==answers[0] for answer in answers) and answers[0]['result']['state']=='closed'
          and answers[0]['result']['reason']==reason and events()==before+1)
    check('B3bt territorial transport preserves historical official ledger and has no parallel ledger',
          hub_sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id')==ledger and sql("SHOW TABLES LIKE 'wp_fans%ledger%'")=='')
