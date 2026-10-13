"""SQL admission after a hypothetically authenticated barrier request; not an HTTP proof."""
import copy
import pathlib
import secrets
import time
import uuid


def run_checks(root,check,call,sql,start,finish,parallel,await_file,fixture):
    table='wp_token_engine_pf_b3bh_nonces'
    def count():return int(sql('SELECT COUNT(*) FROM '+table))
    def window(request,seconds=60):
        now=int(time.time());stamp=lambda t:time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime(t))
        request.update(issued_at=stamp(now),expires_at=stamp(now+seconds));return request
    def request(operation='register'):
        _,_,descriptors=fixture();obj=descriptors[0]
        return window(dict(operation=operation,lookup_operation='register' if operation=='lookup' else '',
                           object=obj,action_id=str(uuid.uuid4()),origin_id=obj['content']['origin_id'],
                           policy_version=obj['content']['policy_version'],operation_key=secrets.token_hex(32),nonce=secrets.token_hex(32)))
    def data(value,**extra):return dict(action='b3b-admit',request=value,digest='a'*64,**extra)
    historic=sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id')
    check('B3ba ordinary bootstrap has no dedicated barrier nonce tables',sql("SHOW TABLES LIKE 'wp_token_engine_pf_b3bh_%'")=='')
    check('B3ba readiness does not install nonce tables',call('b3b-admission-ready')==dict(ready=False))
    sql('CREATE TABLE '+table+' (id INT NOT NULL) ENGINE=InnoDB')
    check('B3ba partial nonce schema is refused',call('b3b-admission-install')==dict(error='model_schema_divergent'))
    sql('DROP TABLE '+table)
    check('B3ba explicit two-table InnoDB installation is idempotent',call('b3b-admission-install')==dict(ready=True)
          and call('b3b-admission-install')==dict(ready=True)
          and len(sql("SHOW TABLES LIKE 'wp_token_engine_pf_b3bh_%'").splitlines())==2)
    value=request();before=count()
    results=parallel([data(value)]*8)
    check('B3ba eight concurrent identical nonces admit exactly once',results.count(dict(admitted=True))==1
          and results.count(dict(error='pf_nonce_replayed'))==7 and count()==before+1)
    changed=copy.deepcopy(value);changed['action_id']=str(uuid.uuid4())
    check('B3ba changed action cannot reuse an admitted nonce',call(**data(changed))==dict(error='pf_nonce_replayed') and count()==before+1)
    changed=copy.deepcopy(value);changed['nonce']=secrets.token_hex(32)
    check('B3ba fresh envelope can retain the same immutable action and operation key',call(**data(changed))==dict(admitted=True))
    for label,extra in [('foreign peer',dict(peer='fixture.other')),('historical permission',dict(permissions=['pf.reserve'])),
                        ('lookup without target permission',dict(permissions=['pf.lookup'])),('lookup without lookup permission',dict(permissions=['pf.ranking.context.register']))]:
        value=request('lookup' if 'lookup' in label else 'register');before=count()
        check('B3ba refuses '+label+' before admission','error' in call(**data(value,**extra)) and count()==before)
    value=request();window(value,-1);before=count()
    check('B3ba expired request creates no nonce',call(**data(value))==dict(error='pf_context_expired') and count()==before)
    value=request();sql('ALTER TABLE '+table+' ENGINE=MyISAM')
    check('B3ba nontransactional nonce schema fails closed',call(**data(value))==dict(error='pf_barrier_transport_unavailable'))
    sql('ALTER TABLE '+table+' ENGINE=InnoDB');before=count()
    check('B3ba failed nonce insertion rolls back admission','error' in call(**data(value,fault='b3bh-insert')) and count()==before)
    check('B3ba exact retry after insertion rollback succeeds',call(**data(value))==dict(admitted=True) and count()==before+1)
    value=request();before=count()
    check('B3ba lost admission COMMIT acknowledgement remains unknown',
          call(**data(value,fault='commit-unknown'))==dict(error='pf_barrier_admission_unknown') and count()==before+1)
    check('B3ba lost acknowledgement cannot readmit the same network nonce',call(**data(value))==dict(error='pf_nonce_replayed') and count()==before+1)
    # Hold after insertion, then expire. This independently tests both wait and final-write expiry.
    value=request();marker=root/uuid.uuid4().hex;before=count()
    held=start(data(value,fault='b3bh-tail',marker=str(marker)));await_file(marker,[held])
    pending=copy.deepcopy(value);window(pending,3);waiting=root/uuid.uuid4().hex
    waiter=start(data(pending,observe=True,wait_marker=str(waiting)));await_file(waiting,[waiter])
    time.sleep(3.2);pathlib.Path(str(marker)+'.release').touch()
    check('B3ba waiting behind the nonce mutex cannot admit an expired request',finish(held)==dict(admitted=True)
          and finish(waiter)==dict(error='pf_context_expired') and count()==before+1)
    value=request();window(value,2);marker=root/uuid.uuid4().hex;before=count()
    held=start(data(value,fault='b3bh-tail',marker=str(marker)));await_file(marker,[held]);time.sleep(2.2);pathlib.Path(str(marker)+'.release').touch()
    check('B3ba expiry after nonce insertion rolls the transaction back',finish(held)==dict(error='pf_context_expired') and count()==before)
    check('B3ba refreshed request retries the same action after definite rollback',call(**data(window(value)))==dict(admitted=True))
    check('B3ba admissions never alter official ledger bytes',sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id')==historic)
