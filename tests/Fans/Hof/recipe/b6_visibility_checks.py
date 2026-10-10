"""Actual WordPress consent/editorial filtering, synthetic ranked input: not Hub/public delivery."""
from b1_checks import actors,call,data,check,checks,root,sql,state,start,finish
import copy
import json
import time
import uuid

baseline=len(checks)
ids=['00000001-1111-4111-8111-111111111111','00000002-1111-4111-8111-111111111111',
     '00000003-1111-4111-8111-111111111111']
rows=[dict(faluss_id=id,points=str(points),position=str(index+1),reached_at='2026-10-05 10:00:00.123456',consumption_order=str(index+1))
      for index,(id,points) in enumerate(zip(ids,[20,12,8]))]
mapping={ids[0]:actors['unlinked'],ids[1]:actors['member'],ids[2]:actors['other']}
before=copy.deepcopy(rows)
def projection(family='fan',mapping=mapping):return data('guest','visible_projection',ranked=rows,mapping=mapping,family=family)
def own(actor):return data(actor,'own')
def consent(actor,enabled):return data(actor,'consent',family='fan',enabled=enabled,revision=int(own(actor)['revision']))
def approve(actor,name):
    submitted=data(actor,'submit',alias=name,revision=int(own(actor)['revision']))
    return data('admin','decide',member=actors[actor],revision=int(submitted['revision']),decision='approve',reason='allowed_alias')

check('B6v private default unlinked and revoked identities produce no display row',projection()==[])
data('member','submit',alias='Approved fan A',revision=int(own('member')['revision']))
consent('member',True)
check('B6v consent alone never publishes a pending name or hidden rank',projection()==[])
data('admin','decide',member=actors['member'],revision=int(own('member')['revision']),decision='approve',reason='allowed_alias')
approve('other','Approved fan B');consent('other',True)
shown=projection()
check('B6v visible subset has unique consecutive places without technical identities',shown==[
    dict(name='Approved fan A',points='12',position='1'),dict(name='Approved fan B',points='8',position='2')])
check('B6v actual visibility filtering leaves the canonical input unchanged',rows==before)
data('member','submit',alias='Pending secret revision',revision=int(own('member')['revision']))
check('B6v pending revision keeps only the previously approved alias',projection()==shown)
data('admin','decide',member=actors['member'],revision=int(own('member')['revision']),decision='reject',reason='needs_revision')
check('B6v rejected text never leaks into a display row',projection()==shown)
consent('member',False)
check('B6v consent withdrawal removes the row and closes the private rank gap',projection()==[dict(name='Approved fan B',points='8',position='1')])
consent('member',True)
check('B6v renewed explicit consent restores only approved identity in corrected order',projection()==shown)
data('admin','decide',member=actors['member'],revision=int(own('member')['revision']),decision='revoke',reason='prohibited_content')
check('B6v moderator alias revocation hides the score without rewriting ranked facts',projection()==[dict(name='Approved fan B',points='8',position='1')] and rows==before)

creator=state['profiles']['member'];creator_mapping={ids[1]:creator}
check('B6v removed creator presentation stays absent despite retained consent',projection('creator',creator_mapping)==[])
data('member','editorial_submit',revision=3)
check('B6v newly submitted creator identity stays hidden before editorial approval',projection('creator',creator_mapping)==[])
data('admin','editorial_decide',creator=creator,revision=4,decision='approve',reason='allowed_editorial')
check('B6v active approved consenting creator is shown through the existing editorial contract',projection('creator',creator_mapping)==[dict(name='Local creator fixture',points='12',position='1')])
check('B6v additive approved name read requires an already active caller transaction',data('guest','editorial_name_outside',creator=creator) is None)
check('B6v approved creator visibility preserves its caller transaction and rollback',
      data('guest','visibility_transaction',family='creator',creator=creator)==dict(name='Local creator fixture',transaction_preserved=True,probe_rows_after_rollback='0'))
check('B6v approved fan visibility preserves its caller transaction and rollback',
      data('guest','visibility_transaction',family='fan',member=actors['other'])==dict(name='Approved fan B',transaction_preserved=True,probe_rows_after_rollback='0'))
guard=root/uuid.uuid4().hex;marker=root/uuid.uuid4().hex;consent_revision=int(own('member')['revision'])
held=start('guest','visibility_transaction',family='creator',creator=creator,hold=str(guard));writer=None
def wait_for(predicate,process):
    deadline=time.monotonic()+12
    while not predicate():
        if process.poll() is not None or time.monotonic()>deadline:raise RuntimeError('Private visibility checkpoint unavailable')
        time.sleep(.05)
try:
    wait_for(lambda:guard.with_name(guard.name+'.ready').exists(),held)
    writer=start('member','consent',family='creator',enabled=False,revision=consent_revision,visibility_started=str(marker))
    wait_for(lambda:marker.exists(),writer)
    wait_for(lambda:int(sql("SELECT COUNT(*) FROM information_schema.innodb_trx WHERE trx_state='LOCK WAIT'"))>0,writer)
    check('B6v concurrent withdrawal waits for the current locked visibility read',writer.poll() is None)
finally:
    guard.with_name(guard.name+'.release').touch()
    if held.poll() is None:held.wait(timeout=20)
held_result=finish(held)
withdrawn=finish(writer)
check('B6v after rollback the waiting withdrawal commits and the next read is hidden',
      held_result['data']==dict(name='Local creator fixture',transaction_preserved=True,probe_rows_after_rollback='0')
      and withdrawn['data']['creator_public']=='0' and projection('creator',creator_mapping)==[])
data('member','consent',family='creator',enabled=True,revision=int(own('member')['revision']))
data('admin','admit',creator=creator,status='suspended')
check('B6v suspension hides creator immediately without changing the input score',projection('creator',creator_mapping)==[] and rows==before)
check('B6v suspended creator remains hidden without committing the caller transaction',
      data('guest','visibility_transaction',family='creator',creator=creator)==dict(name=None,transaction_preserved=True,probe_rows_after_rollback='0'))
data('admin','admit',creator=creator,status='active')
check('B6v readmission rechecks consent and current approved presentation',len(projection('creator',creator_mapping))==1)
data('member','consent',family='creator',enabled=False,revision=int(own('member')['revision']))
check('B6v creator ranking consent remains separate and revocable',projection('creator',creator_mapping)==[])
check('B6v revoked creator consent remains hidden inside the preserved transaction',
      data('guest','visibility_transaction',family='creator',creator=creator)==dict(name=None,transaction_preserved=True,probe_rows_after_rollback='0'))
data('other','withdraw',revision=int(own('other')['revision']))
check('B6v all names withdrawn leaves a real empty list without invented participants',projection()==[])
check('B6v display filtering creates no ledger or score table',sql("SHOW TABLES LIKE 'wp_fans%ledger%'")=='' and sql("SHOW TABLES LIKE 'wp_fans_hof_scores'")=='')
(root/'checks.json').write_text(json.dumps(dict(scope='B6 display filtering only: actual private WordPress state, synthetic ranked rows; no primary Hub proof or delivery route',
    checks=checks,count=len(checks),b6_visibility_total=len(checks)-baseline),indent=2))
