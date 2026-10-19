"""Actual official-ledger confirmation versus the approved territorial primary closure."""
import base64
import hashlib
import json
import secrets
import time
import uuid


def run_checks(root,check,call,sql,start,finish,await_file,proof,fixture):
    contract='hub.purchased-pf.ranking-barriers/1.2.0';reason='territory_admission_revoked'
    historical_max=sql('SELECT COALESCE(MAX(id),0) FROM wp_token_engine_pf_ledger')
    historical=sql('SELECT * FROM wp_token_engine_pf_ledger WHERE id<='+historical_max+' ORDER BY id')
    claims=sql('SELECT * FROM wp_token_engine_ledger ORDER BY id')
    def window(seconds=60):
        stamp=int(time.time());utc=lambda value:time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime(value))
        return dict(fresh_from=utc(stamp),fresh_until=utc(stamp+seconds))
    def fresh():
        evidence,_=proof();data,_=fixture(evidence['member_faluss_id'],admit=False)
        session=data['ranking_context']['sessions'][0]
        session.update(scope='national',country='FR',territory_policy_revision=str(uuid.uuid4()),territory_admission_revision='7')
        data['context_sha256']=hashlib.sha256(json.dumps(data['ranking_context'],sort_keys=True,separators=(',',':')).encode()).hexdigest()
        refs=call('b3b-refs',payload=data)['references']
        for reference in refs:
            content=reference['content'];first=content.get('starts_at',session['starts_at'])
            if content['kind']=='admission':first=max(first,content['admitted_at'])
            assert call('b3b-register',payload=dict(content=content,valid_from=first,valid_until=session['ends_at']),key=secrets.token_hex(32))['state']=='active'
        assert call('b3r-reserve',payload=data,key=secrets.token_hex(32))['state']=='reserved'
        ref=next(value for value in refs if value['content']['kind']=='admission')
        obj={name:ref[name] for name in ('barrier_key','version','content_sha256')}|dict(reason=reason)
        fields=dict(operation='close',lookup_operation='',action_id=str(uuid.uuid4()),origin_id=data['ranking_context']['origin_id'],policy_version='1.0.0',object=obj)
        closing=dict(action='b3b-revoke-territory',payload=obj,key=secrets.token_hex(32),transport=fields,contract=contract)
        return data,secrets.token_hex(32),closing
    def lookup(closing):
        return dict(closing,action='b3b-lookup-territory',transport=dict(closing['transport'],operation='lookup',lookup_operation='close'))
    def debit_count(data):return sql("SELECT COUNT(*) FROM wp_token_engine_pf_ledger WHERE faluss_id='"+data['member_faluss_id']+"' AND direction='debit'")
    def receipt(result):return json.loads(base64.urlsafe_b64decode(result['receipt']['payload_base64url']+'==='))
    data,key,closing=fresh();closed=call(**closing,**window());before=debit_count(data)
    check('B3btr territorial closure before confirmation refuses every selected projection without debit',
          closed.get('state')=='closed' and call('b3r-confirm',payload=data,key=key)==dict(error='pf_barrier_not_admitted')
          and debit_count(data)==before=='0')
    assert call('b3r-release',payload=data,key=secrets.token_hex(32))['state']=='released'
    data,key,closing=fresh();marker=root/uuid.uuid4().hex
    confirmer=start(dict(action='b3r-confirm',payload=data,key=key,fault='b3r-hold-counter',marker=str(marker)))
    await_file(marker,[confirmer]);closer=start(dict(closing,**window()));time.sleep(.3)
    waiting=closer.poll() is None;(root/(marker.name+'.release')).touch()
    confirmed,closed=finish(confirmer),finish(closer)
    check('B3btr a genuine confirmation under official locks precedes the concurrent territorial effective close',
          waiting and confirmed.get('state')=='confirmed' and closed.get('state')=='closed'
          and closed.get('reason')==reason and closed['effective_at']>=receipt(confirmed)['confirmed_at'] and debit_count(data)=='1')
    check('B3btr the prior attested fact stays recoverable without a second debit after territorial closure',
          call('b3r-lookup',payload=data,key=key,operation='confirm')==confirmed
          and call(**lookup(closing),**window())==closed and debit_count(data)=='1')
    data,key,closing=fresh();_,_,holder=fresh();marker=root/uuid.uuid4().hex;wait=root/uuid.uuid4().hex
    held=start(dict(holder,**window(),fault='b3bc-hold-write',marker=str(marker)));await_file(marker,[held])
    pending=start(dict(closing,**window(2),observe=True,wait_marker=str(wait)));await_file(wait,[pending])
    time.sleep(2.2);(root/(marker.name+'.release')).touch();finish(held)
    check('B3btr expired territorial context after owner mutex wait cannot create a closure',
          finish(pending)==dict(error='pf_context_expired') and call(**lookup(closing),**window())==dict(state='not_found'))
    confirmed=call('b3r-confirm',payload=data,key=key)
    check('B3btr a refused expired closure does not falsely deny a still valid official confirmation',
          confirmed.get('state')=='confirmed' and debit_count(data)=='1')
    check('B3btr territorial races preserve earlier official ledger bytes and historical claims',
          sql('SELECT * FROM wp_token_engine_pf_ledger WHERE id<='+historical_max+' ORDER BY id')==historical
          and sql('SELECT * FROM wp_token_engine_ledger ORDER BY id')==claims)
