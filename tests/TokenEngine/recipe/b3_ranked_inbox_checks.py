"""Durable Fans-owned 0.3 metadata, using fictitious owner receipts and controller HTTP."""
import copy
import hashlib
import json
import os
import secrets
import signal
import subprocess
import uuid


def run_checks(root,source,cli_path,check,command,workers,await_file,fixture):
    fans=fixture['fans'];prefix='wp_fans_pf_b3r_'
    def sql(query):
        return command(['mariadb','--no-defaults','--socket='+str(root/'sql.sock'),'-uroot','--batch','--skip-column-names','fans_pf_recipe','-e',query]).strip()
    def start(value):
        path=root/(uuid.uuid4().hex+'.json');path.write_text(json.dumps(value));path.chmod(0o600)
        process=subprocess.Popen(['php',cli_path,'--allow-root','--path='+str(fans),'eval-file',
                                  str(source/'tests/TokenEngine/recipe/b3-ranked-inbox-worker.php'),str(path),'--use-include'],
                                 stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True,start_new_session=True)
        workers.append(process);return process
    def finish(process):
        out,_=process.communicate(timeout=40)
        if process.returncode:raise RuntimeError('Private ranked inbox worker failed.')
        return json.loads(out)
    def call(action,**values):return finish(start(dict(action=action,**values)))
    def counts():return tuple(int(sql('SELECT COUNT(*) FROM '+prefix+table)) for table in ('intents','keys','receipts'))
    def new_intent():
        evidence,_=fixture['proof']();intent,_=fixture['fixture'](evidence['member_faluss_id']);return intent
    check('B3ri ordinary Fans activation creates no ranked inbox',sql("SHOW TABLES LIKE '"+prefix+"%'")=='')
    check('B3ri readiness never installs metadata',call('ready')==dict(ready=False))
    sql('CREATE TABLE '+prefix+'keys (id INT NOT NULL) ENGINE=InnoDB')
    check('B3ri partial schema cannot be adopted',call('install')==dict(error='model_schema_divergent'))
    sql('DROP TABLE '+prefix+'keys')
    check('B3ri explicit installation verifies four new InnoDB tables',call('install')==dict(ready=True)
          and call('install')==dict(ready=True) and len(sql("SHOW TABLES LIKE '"+prefix+"%'").splitlines())==4)
    intent=new_intent();before=counts()
    check('B3ri unknown lookup cannot invent intention or operation key',
          call('prepare',intent=intent,operation='confirm',lookup=True)==dict(error='pf_local_key_absent') and counts()==before)
    tasks=[start(dict(action='prepare',intent=intent,operation='reserve')) for _ in range(6)];answers=[finish(p) for p in tasks]
    reserve=answers[0]['key']
    check('B3ri six concurrent preparations persist one immutable intention and key',all(answer==dict(key=reserve) for answer in answers)
          and counts()==(before[0]+1,before[1]+1,before[2]))
    check('B3ri new PHP process recovers the exact full intention for its member',
          call('recover',attribution=intent['attribution_id'],member=intent['member_faluss_id'])==dict(intent=intent))
    check('B3ri recovery refuses another member without disclosing the intention',
          call('recover',attribution=intent['attribution_id'],member=str(uuid.uuid4()))==dict(error='pf_local_intent_absent'))
    changed=copy.deepcopy(intent);changed['ranking_context']['category_revision']='3'
    changed['context_sha256']=hashlib.sha256(json.dumps(changed['ranking_context'],sort_keys=True,separators=(',',':')).encode()).hexdigest()
    check('B3ri a prepared attribution cannot change its category context',
          call('prepare',intent=changed,operation='confirm')==dict(error='pf_local_intent_conflict'))
    confirm=call('prepare',intent=intent,operation='confirm')['key'];release=call('prepare',intent=intent,operation='release')['key']
    check('B3ri operation keys remain distinct and stable',len({reserve,confirm,release})==3
          and call('prepare',intent=intent,operation='confirm',lookup=True)==dict(key=confirm))
    sql('ALTER TABLE '+prefix+'keys ENGINE=MyISAM')
    check('B3ri nontransactional metadata closes preparation',call('prepare',intent=intent,operation='confirm')==dict(error='pf_protocol_schema_unavailable'))
    sql('ALTER TABLE '+prefix+'keys ENGINE=InnoDB')
    bad=new_intent();before=counts()
    check('B3ri key insertion failure rolls back the new intention',call('prepare',intent=bad,operation='reserve',fault='key-insert')==dict(error='pf_local_storage_unavailable') and counts()==before)
    for fault in ('before-commit','after-commit','commit-unknown'):
        data=new_intent();before=counts()
        if fault=='commit-unknown':
            check('B3ri lost local COMMIT acknowledgement remains unknown',
                  call('prepare',intent=data,operation='reserve',fault=fault)==dict(error='pf_local_commit_unknown'))
        else:
            marker=root/uuid.uuid4().hex;process=start(dict(action='prepare',intent=data,operation='reserve',fault=fault,marker=str(marker)))
            await_file(marker,[process]);os.killpg(process.pid,signal.SIGKILL);process.wait(timeout=10)
        known=sql("SELECT operation_key FROM "+prefix+"keys WHERE attribution_id='"+data['attribution_id']+"'")
        check('B3ri '+fault+' preserves atomic intention and key visibility',
              bool(known)==(fault!='before-commit') and counts()==(before if fault=='before-commit' else (before[0]+1,before[1]+1,before[2])))
        answer=call('prepare',intent=data,operation='reserve')
        check('B3ri '+fault+' recovery never replaces a committed key',
              len(answer['key'])==64 and (not known or answer['key']==known))

    before_debits=fixture['debits']()
    check('B3ri durable key precedes signed owner reservation',fixture['exchange'](intent,'reserve',reserve)==dict(outcome='ok',result=dict(state='reserved')) and fixture['debits']()==before_debits)
    request=fixture['sign'](intent,'confirm',confirm);(root/'ranked-http-fault').write_text('drop-after-commit')
    status,body,_=fixture['http'](request['wire'])
    check('B3ri lost owner HTTP body retains the already durable local key',status==200 and body==''
          and call('prepare',intent=intent,operation='confirm',lookup=True)==dict(key=confirm) and fixture['debits']()==before_debits+1)
    result=fixture['exchange'](intent,'lookup',confirm,'confirm');receipt=result['result']['receipt'];before=counts()
    altered=copy.deepcopy(receipt);value=altered['signature_base64url'];altered['signature_base64url']=('A' if value[0]!='A' else 'B')+value[1:]
    check('B3ri bad receipt signature never persists a private proof',call('receive',intent=intent,receipt=altered)==dict(error='pf_signature_invalid') and counts()==before)
    check('B3ri receipt insertion failure preserves the intention and stable key',
          call('receive',intent=intent,receipt=receipt,fault='receipt-insert')==dict(error='pf_local_storage_unavailable') and counts()==before)
    check('B3ri lost local receipt COMMIT acknowledgement remains unknown',
          call('receive',intent=intent,receipt=receipt,fault='commit-unknown')==dict(error='pf_local_commit_unknown'))
    tasks=[start(dict(action='receive',intent=intent,receipt=receipt)) for _ in range(6)]
    check('B3ri concurrent receipt replay cannot duplicate the committed proof',all(finish(p)==dict(inserted=False) for p in tasks)
          and counts()==(before[0],before[1],before[2]+1) and fixture['debits']()==before_debits+1)
    where=" WHERE attribution_id='"+intent['attribution_id']+"'"
    envelope=sql('SELECT envelope_json FROM '+prefix+'receipts'+where);digest=sql('SELECT envelope_sha256 FROM '+prefix+'receipts'+where)
    check('B3ri stored original signature and complete context survive restart',json.loads(envelope)==receipt and hashlib.sha256(envelope.encode()).hexdigest()==digest)
    sql("UPDATE "+prefix+"receipts SET envelope_sha256='"+'0'*64+"'"+where)
    check('B3ri corrupt persisted proof refuses replay without silent repair',call('receive',intent=intent,receipt=receipt)==dict(error='pf_receipt_conflict'))
    sql("UPDATE "+prefix+"receipts SET envelope_sha256='"+digest+"'"+where)
    check('B3ri restored fixture proof stays idempotent without another debit',call('receive',intent=intent,receipt=receipt)==dict(inserted=False) and fixture['debits']()==before_debits+1)
    changed=copy.deepcopy(intent);changed['creator_faluss_id']=str(uuid.uuid4());before=counts()
    check('B3ri a valid signature for a different full intention is refused','error' in call('receive',intent=changed,receipt=receipt) and counts()==before)
    policy=copy.deepcopy(fixture['policies']['fans']);policy['keys']['recipe-hub-k1']['state']='revoked';fixture['policy']('fans',policy)
    check('B3ri revoked signing key cannot repopulate the inbox',call('receive',intent=intent,receipt=receipt)==dict(error='pf_key_not_trusted') and counts()==before)
    fixture['policy']('fans',fixture['policies']['fans'])
    intent_digest=sql('SELECT payload_sha256 FROM '+prefix+'intents'+where)
    sql("UPDATE "+prefix+"intents SET payload_sha256='"+'0'*64+"'"+where)
    check('B3ri corrupted intention closes primary recovery',call('recover',attribution=intent['attribution_id'],member=intent['member_faluss_id'])==dict(error='pf_local_intent_conflict'))
    sql("UPDATE "+prefix+"intents SET payload_sha256='"+intent_digest+"'"+where)
