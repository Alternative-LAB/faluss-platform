"""Closed barrier owner context: no network or economic operation in this recipe."""
import copy
import pathlib
import secrets
import time
import uuid


def run_checks(root,check,call,sql,start,finish,parallel,await_file,fixture,close_ref):
    def window(seconds=60):
        now=int(time.time());stamp=lambda t:time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime(t))
        return dict(fresh_from=stamp(now),fresh_until=stamp(now+seconds))
    def state():
        return tuple(sql('SELECT * FROM wp_token_engine_pf_b3b_'+table+' ORDER BY '+order) for table,order in
                     [('barriers','barrier_key,version'),('operations','owner,operation,key_sha256'),('events','event_id')])
    def request(operation='register'):
        _,refs,descriptors=fixture();descriptor=descriptors[0]
        if operation in ('close','lookup-close'):
            assert call('b3b-register',payload=descriptor,key=secrets.token_hex(32))['state']=='active'
        lookup=operation.startswith('lookup-');target=operation.removeprefix('lookup-')
        obj=descriptor if target=='register' else close_ref(refs[0])
        fields=dict(operation='lookup' if lookup else target,action_id=str(uuid.uuid4()),
                    origin_id=descriptor['content']['origin_id'],policy_version=descriptor['content']['policy_version'],
                    lookup_operation=target if lookup else '',object=obj)
        value=dict(action='b3b-lookup' if lookup else 'b3b-'+target,payload=obj,key=secrets.token_hex(32),transport=fields)
        if lookup:value['operation']=target
        return value
    def lookup(value):
        answer=copy.deepcopy(value);target=value['action'].removeprefix('b3b-')
        answer.update(action='b3b-lookup',operation=target)
        answer['transport'].update(operation='lookup',lookup_operation=target)
        return answer

    historic=sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id')
    for operation in ('register','close','lookup-register','lookup-close'):
        value=request(operation);before=state()
        check('B3bc expired '+operation+' refuses before owner mutation',call(**value,**window(-1))==dict(error='pf_context_expired') and state()==before)
        check('B3bc fresh '+operation+' preserves its exact origin and action',
              call(**value,**window())['state']=={'register':'active','close':'closed','lookup-register':'not_found','lookup-close':'not_found'}[operation])
    value=request();key=value['key'];first=call(**value,**window());before=state()
    check('B3bc concurrent identical actions reuse one owner event',all(v==first for v in parallel([dict(value,**window())]*6)) and state()==before)
    check('B3bc fresh lookup binds the original action without a second event',call(**lookup(value),**window())==first and state()==before)
    changed=copy.deepcopy(value);changed['transport']['action_id']=str(uuid.uuid4())
    check('B3bc same key with a changed action is refused',call(**changed,**window())==dict(error='pf_barrier_key_conflict') and state()==before)
    check('B3bc historical request cannot adopt a transport-bound key',
          call('b3b-register',payload=value['payload'],key=key)==dict(error='pf_barrier_key_conflict') and state()==before)
    for operation in ('close','lookup-close'):
        value=request(operation);before=state()
        for field,replacement in [('origin_id',str(uuid.uuid4())),('policy_version','2.0.0')]:
            changed=copy.deepcopy(value);changed['transport'][field]=replacement
            check('B3bc '+operation+' verifies '+field+' from the exact stored descriptor',
                  call(**changed,**window())==dict(error='pf_barrier_context_mismatch') and state()==before)
    value=request('close');first=call(**value,**window());before=state()
    check('B3bc old close lookup stays closed with its same action',call(**lookup(value),**window())==first and state()==before)

    for operation in ('register','close','lookup-register','lookup-close'):
        value=request(operation);holder=request();marker=root/uuid.uuid4().hex;wait=root/uuid.uuid4().hex
        held=start(dict(holder,**window(),fault='b3bc-hold-write',marker=str(marker)));await_file(marker,[held])
        pending=start(dict(value,**window(2),observe=True,wait_marker=str(wait)));await_file(wait,[pending])
        time.sleep(2.2);pathlib.Path(str(marker)+'.release').touch();finish(held)
        check('B3bc '+operation+' expiring behind the owner write mutex is refused',finish(pending)==dict(error='pf_context_expired'))
        if operation in ('register','close'):
            check('B3bc expired '+operation+' has no durable operation',call(**lookup(value),**window())==dict(state='not_found'))
    for operation in ('register','close'):
        value=request(operation);before=state();marker=root/uuid.uuid4().hex
        pending=start(dict(value,**window(2),fault='b3bc-tail',marker=str(marker)));await_file(marker,[pending])
        time.sleep(2.2);pathlib.Path(str(marker)+'.release').touch()
        check('B3bc '+operation+' expiry after event insert rolls back all owner effects',finish(pending)==dict(error='pf_context_expired') and state()==before)
        check('B3bc same action retries after definite rollback',call(**value,**window())['state']==('active' if operation=='register' else 'closed'))
    check('B3bc origin and freshness guards leave the official ledger byte-identical',sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id')==historic)
