"""Signed completion over physically isolated loopback; no central SSO or real peers."""
import concurrent.futures
import datetime
import secrets
import uuid


def run_checks(root,check,call,sql,fixture):
    contract='hub.purchased-pf.ranking-barriers/1.1.0'
    http=fixture['http'];sign=fixture['sign'];accept=fixture['accept'];events=fixture['events'];nonces=fixture['nonces']
    clock=root/'barrier-primary-clock'
    def primary(descriptor,offset=1):
        end=datetime.datetime.fromisoformat(descriptor['valid_until']).replace(tzinfo=datetime.timezone.utc)
        clock.write_text(format(end.timestamp()+offset,'.6f'));clock.chmod(0o600)
    def request(fields,key):return sign(fields,key,contract=contract)
    def exchange(fields,key):
        sealed=request(fields,key);status,wire,_=http(sealed['wire'])
        if status!=200:raise RuntimeError('Private completion request refused: '+str(status))
        return accept(fields,sealed,wire,contract=contract),sealed,wire
    def create(kind='session'):
        clock.unlink(missing_ok=True)
        _,refs,descriptors=fixture['fixture']()
        ref=next(row for row in refs if row['content']['kind']==kind)
        descriptor=next(row for row in descriptors if row['content']==ref['content'])
        opening=fixture['fields'](descriptor);opening_key=secrets.token_hex(32)
        sealed=sign(opening,opening_key);status,wire,_=http(sealed['wire'])
        assert status==200 and accept(opening,sealed,wire)['result']['state']=='active'
        fields=dict(opening,operation='close',action_id=str(uuid.uuid4()),
            object={name:ref[name] for name in ('barrier_key','version','content_sha256')}|dict(reason='session_completed'))
        return fields,secrets.token_hex(32),descriptor,opening,opening_key
    historic=sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id')
    try:
        fields,key,descriptor,opening,opening_key=create();before=events();count=nonces()
        for name,extra in [('outer version',dict(request=dict(contract='hub.purchased-pf.ranking-barriers/1.0.0'))),
            ('context version',dict(context=dict(contract='hub.purchased-pf.ranking-barriers/1.0.0'))),
            ('unknown version',dict(request=dict(contract='hub.purchased-pf.ranking-barriers/9.0.0'))),
            ('audience',dict(request=dict(audience='fixture.other'))),('unknown key',dict(request_key='unknown'))]:
            sealed=sign(fields,key,contract=contract,**extra)
            check('B3beh signed '+name+' mismatch refuses before nonce admission',http(sealed['wire'])[0]==403 and events()==before and nonces()==count)
        check('B3beh altered completion signature cannot close or admit a nonce',
            http(fixture['bad_signature'](request(fields,key)['wire']))[0]==403 and events()==before and nonces()==count)
        policy=fixture['policies']['hub']
        fixture['policy']('hub',dict(policy,permissions=['pf.confirm']))
        check('B3beh economic permission cannot authorize session completion',http(request(fields,key)['wire'])[0]==403 and events()==before and nonces()==count)
        fixture['policy']('hub',policy)
        primary(descriptor,-1);early,_,_=exchange(fields,key)
        check('B3beh primary time before frozen deadline returns signed refusal without closing',
            early==dict(outcome='refused',result=dict(reason='pf_barrier_completion_not_due')) and events()==before)
        primary(descriptor)
        look=dict(fields,operation='lookup',lookup_operation='close');absent,_,_=exchange(look,key)
        check('B3beh primary lookup absence never claims completion',absent==dict(outcome='ok',result=dict(state='not_found')) and events()==before)
        sealed=[request(fields,key) for _ in range(8)]
        with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:answers=list(pool.map(lambda value:http(value['wire']),sealed))
        results=[accept(fields,request,answer[1],contract=contract) for request,answer in zip(sealed,answers) if answer[0]==200]
        check('B3beh eight fresh signed close nonces commit one effective completion',
            len(results)==8 and all(row==results[0] for row in results) and results[0]['result']['reason']=='session_completed'
            and results[0]['result']['state']=='closed' and events()==before+1)
        check('B3beh completion nonce replay adds no event',http(sealed[0]['wire'])[0]==403 and events()==before+1)
        recovered,reply_request,reply=exchange(look,key)
        check('B3beh signed primary lookup preserves action key and original instant',recovered==results[0] and events()==before+1)
        altered=fixture['worker']('hub',dict(action='alter-response',wire=reply,response=dict(contract='hub.purchased-pf.ranking-barriers/1.0.0')))['wire']
        check('B3beh signed reply of another version cannot authorize completion',
            'error' in accept(look,reply_request,altered,contract=contract))
        check('B3beh response signature and digest remain mandatory',
            'error' in accept(look,reply_request,fixture['bad_signature'](reply),contract=contract)
            and 'error' in accept(look,reply_request,reply,contract=contract,digest='b'*64))
        old=dict(opening,operation='lookup',lookup_operation='register');old_sealed=sign(old,opening_key)
        status,old_wire,_=http(old_sealed['wire'])
        check('B3beh old 1.0 registration lookup reports closed without reopening',
            status==200 and accept(old,old_sealed,old_wire)['result']['state']=='closed' and events()==before+1)
        check('B3beh foreign origin lookup cannot adopt a completed sparse reference',
            exchange(dict(look,origin_id=str(uuid.uuid4())),key)[0]['outcome']=='refused' and events()==before+1)
        for kind in ('origin','admission'):
            wrong,wrong_key,other,_,_=create(kind);primary(other);before=events()
            check('B3beh '+kind+' reference returns signed refusal rather than completion',
                exchange(wrong,wrong_key)[0]==dict(outcome='refused',result=dict(reason='pf_barrier_completion_requires_session')) and events()==before)
        fields,key,descriptor,_,_=create();primary(descriptor);before=events()
        sealed=request(fields,key);(root/'barrier-http-fault').write_text('drop-after-commit')
        status,body,_=http(sealed['wire'])
        check('B3beh lost completion HTTP body follows exactly one committed event',status==200 and body=='' and events()==before+1)
        look=dict(fields,operation='lookup',lookup_operation='close');recovered,_,_=exchange(look,key)
        check('B3beh lost response recovers by primary lookup without a new key or second event',
            recovered['result']['state']=='closed' and recovered['result']['reason']=='session_completed'
            and exchange(fields,key)[0]==recovered and events()==before+1)
        check('B3beh 1.1 completion HTTP preserves all official ledger bytes',sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id')==historic)
    finally:clock.unlink(missing_ok=True)
