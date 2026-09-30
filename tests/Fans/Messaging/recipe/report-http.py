"""Real report REST isolation, recourse and failed audit rollback."""
import runpy,pathlib,uuid,json
# Reuse helpers only, without repeating the conversation scenario.
source=pathlib.Path(__file__).with_name('message-http.py').read_text().split('# The image recipe')[0]
ns={};exec(compile(source,'message-http-helpers','exec'),ns)
call,check,sql,CREATOR=[ns[k] for k in ['call','check','sql','CREATOR']]
r=call(18,'messages/requests',dict(creator_id=CREATOR,body='Signalement HTTP synthétique',key=str(uuid.uuid4())))[1]
thread=r['thread_id'];message=r['message_id'];path='messages/'+thread+'/report'
for user in [0,19,1,20,42]: check('report permission '+str(user),call(user,path,dict(message_id=message,reason='spam'))[0] in [403,404])
check('report CSRF required',call(17,path,dict(message_id=message,reason='spam'),False)[0]==403)
check('report cannot inject proof',call(17,path,dict(message_id=message,reason='spam',body='forged'))[0]==400)
case=call(17,path,dict(message_id=message,reason='spam'))[1]['case_id'];route='message-reports/'+case
for user in [0,17,18,19,1]: check('proof dedicated permission '+str(user),call(user,route)[0]==403)
check('proof nonce required',call(42,route,nonce=False)[0]==403)
proof=call(42,route);check('moderator gets selected real message',proof[0]==200 and proof[1]['body']=='Signalement HTTP synthétique' and 'no-store' in proof[2]['Cache-Control'])
check('participant list never returns proof','Signalement HTTP' not in json.dumps(call(18,'message-reports/mine')[1]))
decision=dict(revision=1,action='no_action',reason='Message examiné.',recourse_complete=False,days=0)
check('ordinary admin cannot decide',call(1,route+'/decision',decision)[0]==403)
check('moderator provisional decision',call(42,route+'/decision',decision)[0]==200)
check('third member cannot appeal',call(20,route+'/appeal',dict(revision=2,reason='No'))[0]==404)
check('reporter appeal',call(17,route+'/appeal',dict(revision=2,reason='Motivation du recours'))[0]==200)
check('no finalization over pending appeal',call(42,route+'/decision',dict(decision,revision=3,action='finalize',recourse_complete=True))[0]==409)
check('review appeal then decide',call(42,route+'/decision',dict(decision,revision=3))[0]==200)
check('finalize requires explicit confirmation',call(42,route+'/decision',dict(decision,revision=4,action='finalize'))[0]==409)
check('finalize after recourse',call(42,route+'/decision',dict(decision,revision=4,action='finalize',recourse_complete=True))[0]==200)
sql("UPDATE test_faluss_fans_dm_reports SET final_at=UTC_TIMESTAMP()-INTERVAL 12 MONTH WHERE case_id='"+case+"'")
check('expired proof returns no bytes',call(42,route)[0]==410 and sql('SELECT COUNT(*) FROM test_faluss_fans_dm_reports')=='0')
print('PASS report HTTP permissions, minimization, recourse and exact expiry',flush=True)
