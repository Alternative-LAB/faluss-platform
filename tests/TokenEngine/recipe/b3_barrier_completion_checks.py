"""Explicit completion on a disposable primary; no HTTP, score or economic write."""
import copy
import datetime
import hashlib
import json
import os
import secrets
import signal
import time
import uuid


def run_checks(root,check,call,sql,start,finish,parallel,await_file,helpers):
    fixture,_ = helpers
    contract = 'hub.purchased-pf.ranking-barriers/1.1.0'
    prefix = 'wp_token_engine_pf_b3b_'
    economic = lambda: tuple(sql('SELECT * FROM '+table+' ORDER BY '+order) for table,order in [
        ('wp_token_engine_pf_ledger','id'),('wp_token_engine_pf_h2c_consumptions','attribution_id'),
        ('wp_token_engine_pf_h2c_journal','event_id'),('wp_token_engine_pf_h3_receipts','attribution_id'),
        ('wp_token_engine_ledger','id')])
    historical = economic()
    state = lambda: tuple(sql('SELECT * FROM '+prefix+table+' ORDER BY '+order) for table,order in [
        ('barriers','barrier_key,version'),('operations','owner,operation,key_sha256'),('events','event_id')])
    now = lambda: datetime.datetime.fromisoformat(sql('SELECT UTC_TIMESTAMP(6)')).replace(tzinfo=datetime.timezone.utc)
    stamp = lambda value: value.strftime('%Y-%m-%d %H:%M:%S.%f')

    def window(seconds=60):
        current=int(time.time()); text=lambda t:time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime(t))
        return dict(fresh_from=text(current),fresh_until=text(current+seconds))

    def create(seconds=3600,kind='session',all_dimensions=False):
        payload,_,_=fixture()
        end=(now()+datetime.timedelta(seconds=seconds)).replace(microsecond=0)
        payload['ranking_context']['sessions'][0]['ends_at']=stamp(end)
        payload['context_sha256']=hashlib.sha256(json.dumps(payload['ranking_context'],separators=(',',':'),sort_keys=True).encode()).hexdigest()
        refs=call('b3b-refs',payload=payload)['references']; ref=next(r for r in refs if r['content']['kind']==kind)
        for item in refs if all_dimensions else [ref]:
            content=item['content']; first=content.get('starts_at',stamp(end-datetime.timedelta(hours=1)))
            if content['kind']=='admission': first=max(first,content['admitted_at'])
            descriptor=dict(content=content,valid_from=first,valid_until=content.get('ends_at',stamp(end)))
            assert call('b3b-register',payload=descriptor,key=secrets.token_hex(32))['state']=='active'
        obj={k:ref[k] for k in ('barrier_key','version','content_sha256')}|dict(reason='session_completed')
        fields=dict(operation='close',action_id=str(uuid.uuid4()),origin_id=ref['content']['origin_id'],
                    policy_version=ref['content']['policy_version'],lookup_operation='',object=obj)
        value=dict(action='b3b-complete',payload=obj,key=secrets.token_hex(32),contract=contract,transport=fields)
        return value,end,payload

    def lookup(value):
        result=copy.deepcopy(value);result['action']='b3b-lookup-completion'
        result['transport'].update(operation='lookup',lookup_operation='close')
        return result

    value,end,_=create();before=state()
    check('B3be missing authenticated context cannot complete a session',
          call('b3b-complete',payload=value['payload'],key=value['key'])==dict(error='pf_barrier_context_mismatch') and state()==before)
    check('B3be historical 1.0 close and lookup still reject completion',
          call('b3b-close',payload=value['payload'],key=value['key'])==dict(error='pf_barrier_invalid_reason')
          and call('b3b-lookup',operation='close',payload=value['payload'],key=value['key'])==dict(error='pf_barrier_invalid_reason') and state()==before)
    check('B3be completion requires its dedicated close permission',
          call(**value,**window(),permissions=['pf.confirm'])==dict(error='pf_permission_denied') and state()==before)
    check('B3be completion lookup requires both close and lookup permissions',
          call(**lookup(value),**window(),permissions=['pf.ranking.context.close'])==dict(error='pf_permission_denied') and state()==before)
    check('B3be completion rejects another peer before any mutation',
          call(**value,**window(),peer='fixture.other')==dict(error='pf_invalid_peer') and state()==before)
    check('B3be primary one second before frozen deadline refuses closure',
          call(**value,**window(),primary_timestamp=end.timestamp()-1)==dict(error='pf_barrier_completion_not_due') and state()==before)
    for field,replacement in [('origin_id',str(uuid.uuid4())),('policy_version','2.0.0')]:
        changed=copy.deepcopy(value);changed['transport'][field]=replacement
        check('B3be completion binds stored '+field,
              call(**changed,**window(),primary_timestamp=end.timestamp())==dict(error='pf_barrier_context_mismatch') and state()==before)
    check('B3be due primary lookup reports absence without closing or adding an event',
          call(**lookup(value),**window(),primary_timestamp=end.timestamp())==dict(state='not_found') and state()==before)
    first=call(**value,**window(),primary_timestamp=end.timestamp())
    check('B3be exact frozen primary deadline completes with its primary instant',
          first.get('state')=='closed' and first.get('reason')=='session_completed' and first.get('effective_at')==stamp(end))
    after=state()
    check('B3be eight concurrent completion replays preserve one operation event and instant',
          all(v==first for v in parallel([dict(value,**window(),primary_timestamp=end.timestamp()+1)]*8)) and state()==after)
    check('B3be lookup of the same action key returns the original completion',
          call(**lookup(value),**window(),primary_timestamp=end.timestamp()+2)==first and state()==after)
    changed=copy.deepcopy(value);changed['transport']['action_id']=str(uuid.uuid4())
    check('B3be same key cannot adopt a different action',
          call(**changed,**window(),primary_timestamp=end.timestamp()+2)==dict(error='pf_barrier_key_conflict') and state()==after)
    changed=copy.deepcopy(value);changed['key']=secrets.token_hex(32)
    check('B3be fresh key cannot circumvent a completed version',
          call(**changed,**window(),primary_timestamp=end.timestamp()+2)==dict(error='pf_barrier_stable_key_or_version_required') and state()==after)
    for kind in ('origin','country','creator','admission'):
        wrong,end,_=create(kind=kind);before=state()
        check('B3be completion cannot close a '+kind+' barrier',
              call(**wrong,**window(),primary_timestamp=end.timestamp())==dict(error='pf_barrier_completion_requires_session') and state()==before)
    # Early cancellation retains the old semantics and is never promoted to completion.
    value,end,_=create();early=copy.deepcopy(value);early['payload']['reason']='session_cancelled'
    result=call('b3b-close',payload=early['payload'],key=secrets.token_hex(32))
    check('B3be explicit early cancellation remains historical and adds no winner',
          result.get('reason')=='session_cancelled' and result.get('state')=='closed' and 'winner' not in result)
    before=state()
    check('B3be a cancelled session cannot later become completed',
          call(**value,**window(),primary_timestamp=end.timestamp())==dict(error='pf_barrier_stable_key_or_version_required') and state()==before)

    value,end,_=create();holder,_,_=create();marker,waiting=root/uuid.uuid4().hex,root/uuid.uuid4().hex
    held=start(dict(holder,**window(),primary_timestamp=end.timestamp(),fault='b3bc-hold-write',marker=str(marker)));await_file(marker,[held])
    pending=start(dict(value,**window(2),primary_timestamp=end.timestamp(),observe=True,wait_marker=str(waiting)));await_file(waiting,[pending])
    time.sleep(2.2);(root/(marker.name+'.release')).touch();finish(held)
    check('B3be expired context behind the owner mutex never completes',finish(pending)==dict(error='pf_context_expired'))
    check('B3be expired completion has no operation on the primary',
          call(**lookup(value),**window(),primary_timestamp=end.timestamp())==dict(state='not_found'))
    before=state();marker=root/uuid.uuid4().hex
    pending=start(dict(value,**window(2),primary_timestamp=end.timestamp(),fault='b3bc-tail',marker=str(marker)));await_file(marker,[pending])
    time.sleep(2.2);(root/(marker.name+'.release')).touch()
    check('B3be context expiry after event insert rolls back completion and audit together',
          finish(pending)==dict(error='pf_context_expired') and state()==before)

    for fault in ('b3b-event-insert','commit-unknown','before-commit','after-commit'):
        value,end,_=create();before=state();args=dict(value,**window(),primary_timestamp=end.timestamp(),fault=fault)
        if fault.endswith('commit'):
            marker=root/uuid.uuid4().hex;process=start(dict(args,marker=str(marker)))
            await_file(marker,[process]);os.killpg(process.pid,signal.SIGKILL);process.wait(timeout=10)
        else:
            expected='pf_barrier_commit_unknown' if fault=='commit-unknown' else 'h2_storage_unavailable'
            check('B3be '+fault+' returns an honest uncertain or failed result',call(**args)==dict(error=expected))
        committed=fault in ('commit-unknown','after-commit')
        recovered=call(**lookup(value),**window(),primary_timestamp=end.timestamp()+1)
        check('B3be primary recovery resolves '+fault+' with the same action and key',
              (recovered.get('state')=='closed' if committed else recovered==dict(state='not_found') and state()==before))
        if committed:
            after=state()
            check('B3be '+fault+' replay cannot add a second event',
                  call(**value,**window(),primary_timestamp=end.timestamp()+2)==recovered and state()==after)
        else:
            check('B3be definite '+fault+' rollback permits the original action retry',
                  call(**value,**window(),primary_timestamp=end.timestamp()+1).get('state')=='closed')

    value,end,payload=create(seconds=12,all_dimensions=True)
    marker,release=root/uuid.uuid4().hex,root/uuid.uuid4().hex
    holder=start(dict(action='b3b-hold',payload=payload,marker=str(marker),release=str(release)));await_file(marker,[holder])
    closer=start(dict(value,**window()));time.sleep(.3)
    check('B3be completion waits for the in-flight selected owner transaction',closer.poll() is None)
    time.sleep(max(0,end.timestamp()-time.time())+.15);release.touch()
    selected,completed=finish(holder),finish(closer)
    check('B3be completion samples primary time after the row wait at the frozen deadline',
          selected.get('checked') and selected['confirmed_at']<stamp(end)
          and completed.get('state')=='closed' and completed.get('effective_at','')>=stamp(end))
    check('B3be completed session rejects further selected contributions',
          call('b3b-check',payload=payload)==dict(error='pf_barrier_not_admitted'))
    check('B3be completion leaves official PF ALB claims receipts and journals byte identical',economic()==historical)
