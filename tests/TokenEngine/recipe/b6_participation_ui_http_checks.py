"""Native voluntary request, organizer decisions and moderator appeal on the disposable pair."""
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime,timedelta,timezone
from urllib.parse import parse_qs,urlsplit
from html import unescape
import re
from b6_privacy_http_checks import Forms


def run_checks(root,read,cookies,actors,deliver,check):
    owner=cookies['creator'];admin=cookies['admin'];new='/app/creator/hof?manage=new'
    assert deliver('fixture-participation-services',actor=actors['admin_user'])==dict(ready=True)
    extra=deliver('fixture-extra-creator',actor=actors['admin_user']);member=deliver('fixture-cookie',actor=extra['creator_user'])
    target=extra['creator_profile']
    def form(path,action,cookie=owner,kind='hof_session_action'):
        status,html,_=read(path,cookie)
        if status!=200:raise RuntimeError('Native participation GET failed; response omitted, status='+str(status))
        return Forms(html).action(kind,action)
    stamp=datetime.fromtimestamp(float((root/'corpus-sql-clock').read_text()),timezone.utc)
    primary=root/'barrier-primary-clock';prior=primary.read_bytes() if primary.exists() else None
    primary.write_text(format(stamp.timestamp(),'.6f'));primary.chmod(0o600)
    try:
        fields=form(new,'create');fields.update(title='Participation native fictive',rules_text='Participation individuelle volontaire selon les règles figées.',
            category='arts',scope='international',country='',territory_ref='',timezone='UTC',
            starts_local=(stamp-timedelta(minutes=1)).strftime('%Y-%m-%dT%H:%M:%S'),ends_local=(stamp+timedelta(hours=1)).strftime('%Y-%m-%dT%H:%M:%S'),starts_offset='',ends_offset='')
        response=read(new,owner,fields,follow=False)
        if response[0]!=303:raise RuntimeError('Native participation session creation failed; response omitted')
        path=response[2]['Location'];id=parse_qs(urlsplit(path).query)['session'][0]
        panel='/app/admin?view=sessions&session='+id;join='/app/creator/hof?manage=join&session='+id
        public='/app/creator/hof/session?session='+id
        snapshot=lambda:deliver('private-session',actor=actors['creator_user'],session=id)
        role=lambda:next((r for r in snapshot()['roles'] if r['role']=='participant' and r['creator_id']==target),None)
        assert read(path,owner,form(path,'submit'))[0]==200
        assert read(panel,admin,form(panel,'approve',admin,'session_review_action'))[0]==200
        assert read(path,owner,form(path,'open'))[0]==200
        before=snapshot()
        check('B6dl participation an eligible creator can enter only from an actual public session',
              read(public,member)[0]==200 and 'manage=join' in read(public,member)[1] and read(join,member)[0]==200 and read(path,member)[0]==403)
        request=form(join,'request_participation',member);request['accept_participation_rules']='yes'
        check('B6dl participation guest Fan and administrator cannot submit a copied creator request',
              all(read(join,c,request)[0]==403 for c in (None,cookies['fan'],admin)) and snapshot()==before)
        check('B6dl participation missing consent invalid nonce and forged frozen rules create no role',
              read(join,member,dict(request,accept_participation_rules=''))[0]==400
              and read(join,member,dict(request,hof_session_nonce='invalid'))[0]==403
              and read(join,member,dict(request,rules_sha256='0'*64))[0]==400 and snapshot()==before)
        check('B6dl participation GET and list display have no request or admission side effect',read(join,member)[0]==200 and read(path,owner)[0]==200 and snapshot()==before)
        with ThreadPoolExecutor(max_workers=2) as pool:responses=list(pool.map(lambda _:read(join,member,request,follow=False)[0],range(2)))
        check('B6dl participation duplicate native requests create one voluntary role and one revision',
              sorted(responses)==[303,409] and role()['state']=='requested' and int(snapshot()['session']['revision'])==int(before['session']['revision'])+1)
        check('B6dl participation the requested role grants only its own private view and no organizer powers',
              read(path,member)[0]==200 and 'admit_participant' not in read(path,member)[1] and 'invite_organizer' not in read(path,member)[1])
        refusal=form(path,'refuse_participant');refusal['participant_target']=target
        baseline=snapshot()
        check('B6dl participation a member cannot forge an organizer decision with a valid own nonce',
              read(path,member,dict(form(path,'withdraw_participation',member),hof_session_action='admit_participant',participant_target=target))[0]==403 and snapshot()==baseline)
        assert read(path,owner,refusal)[0]==200
        appeal=form(path,'appeal_participation',member);appeal['explanation']='Réexamen fictif des critères de participation.'
        assert read(path,member,appeal)[0]==200
        cases=deliver('participation-cases',actor=extra['creator_user']);case=next(r for r in cases if r['session_id']==id)
        case_path='/app/admin?view=participation&case='+case['case_id'];decision=form(case_path,'decide',admin,'participation_case_action')
        decision['case_choice']='admit|criteria_satisfied'
        check('B6dl participation appeal details and moderator decisions are inaccessible to members',
              all(read(case_path,c)[0]==403 and read(case_path,c,decision)[0]==403 for c in (None,cookies['fan'],member,owner)))
        check('B6dl participation an invalid case nonce cannot decide or admit',
              read(case_path,admin,dict(decision,participation_case_nonce='invalid'))[0]==403 and role()['state']=='refused'
              and deliver('participation-case',actor=actors['admin_user'],case=case['case_id'])['state']=='pending')
        fault=root/'b6-ui-drop-response';fault.write_text('2');fault.chmod(0o600)
        response=read(case_path,admin,decision,follow=False)
        check('B6dl participation favourable native appeal persists the actual case and pending primary confirmation',
              response[0]==303 and read(case_path,admin)[0]==200 and role()['state']=='admitted'
              and deliver('participation-case',actor=actors['admin_user'],case=case['case_id'])['result']=='admit')
        resume=form(path,'resume_participation',member);resume['participant_target']=target
        assert read(path,member,resume)[0]==200
        check('B6dl participation primary lookup confirms the same native admission without a second decision',
              'Admission confirmée.' in read(path,member)[1] and role()['state']=='admitted'
              and deliver('participation-case',actor=actors['admin_user'],case=case['case_id'])['revision']=='2')
        before=snapshot();stale=decision.copy();stale['participation_case_nonce']=form(case_path,'resume',admin,'participation_case_action')['participation_case_nonce']
        check('B6dl participation an old decided case cannot perform another native admission',
              read(case_path,admin,stale)[0]==409 and snapshot()==before)
        withdrawal=form(path,'withdraw_participation',member);fault.write_text('2');fault.chmod(0o600)
        response=read(path,member,withdrawal,follow=False)
        check('B6dl participation native withdrawal closes choices while its real close response is uncertain',response[0]==303 and 'fermeture en cours' in read(path,member)[1] and role()['state']=='closing')
        assert read(path,member,dict(form(path,'resume_participation',member),participant_target=target))[0]==200
        check('B6dl participation native same-key recovery settles withdrawal and keeps the appeal history',
              role()['state']=='withdrawn' and deliver('participation-case',actor=actors['admin_user'],case=case['case_id'])['state']=='decided')
        check('B6dl participation malformed pagination cannot expose an unrelated creator role',
              read(path+'&participant_cursor=invalid',owner)[0]==400 and read(path+'&participant_cursor='+target,member)[0]==400)
        old_resume=form(case_path,'resume',admin,'participation_case_action')
        again=form(join,'request_participation',member);again['accept_participation_rules']='yes'
        assert read(join,member,again,follow=False)[0]==303
        old_resume['session_revision']=snapshot()['session']['revision'];before=snapshot()
        check('B6dl participation a historical favourable case cannot resume a later application',
              read(case_path,admin,old_resume)[0]==409 and snapshot()==before
              and 'participation_case_action' not in read(case_path,admin)[1])
        assert read(path,member,form(path,'withdraw_participation',member))[0]==200
        check('B6dl participation withdrawing a pending request needs no fabricated Hub admission',role()['state']=='withdrawn')
        again=form(join,'request_participation',member);again['accept_participation_rules']='yes'
        assert read(join,member,again,follow=False)[0]==303
        admission=form(path,'admit_participant');admission['participant_target']=target
        assert read(path,owner,admission)[0]==200
        admitted=role();old_parent=snapshot()['session']['barrier_version']
        revoke=form(panel,'revoke',admin,'session_review_action');revoke.update(reason='needs_revision',confirm_revoke='yes')
        assert read(panel,admin,revoke)[0]==200
        appeal=form(path,'appeal');appeal['explanation']='Recours fictif sur les règles inchangées de session.'
        assert read(path,owner,appeal)[0]==200
        assert read(panel,admin,form(panel,'approve',admin,'session_review_action'))[0]==200
        assert read(path,owner,form(path,'readmit'))[0]==200
        before=snapshot();status,html,_=read(path,member)
        check('B6dl participation a native session readmission never presents the old admission as confirmed or changes it on GET',
              status==200 and 'Admission confirmée.' not in html and 'Vérifier la confirmation' in html
              and snapshot()==before and int(before['session']['barrier_version'])==int(old_parent)+1 and role()==admitted)
        fault.write_text('2');fault.chmod(0o600)
        resume=form(path,'resume_participation',member);resume['participant_target']=target
        response=read(path,member,resume,follow=False)
        check('B6dl participation native readmission recovery preserves an uncertain retirement without fabricating its confirmation',
              response[0]==303 and 'Admission confirmée.' not in read(path,member)[1] and role()==admitted)
        assert read(path,member,dict(form(path,'resume_participation',member),participant_target=target))[0]==200
        check('B6dl participation native same-action recovery confirms only the future admission under unchanged consent',
              'Admission confirmée.' in read(path,member)[1] and role()['rules_sha256']==admitted['rules_sha256']
              and role()['admitted_at']>=admitted['admitted_at'] and int(role()['revision'])==int(admitted['revision'])+1)
        assert read(path,member,form(path,'withdraw_participation',member))[0]==200
        check('B6dl participation the requalified native participant can still withdraw without a new application',role()['state']=='withdrawn')
        candidates=[]
        for _ in range(21):
            candidate=deliver('fixture-extra-creator',actor=actors['admin_user']);cookie=deliver('fixture-cookie',actor=candidate['creator_user'])
            request=form(join,'request_participation',cookie);request['accept_participation_rules']='yes'
            assert read(join,cookie,request,follow=False)[0]==303
            candidates.append(candidate['creator_profile'])
        seen=[];page=path;pages=0;before=snapshot();last_page=None;last_html=None
        while page is not None:
            status,html,_=read(page,owner);assert status==200;pages+=1;last_page=page;last_html=html
            forms=Forms(html).forms
            seen.extend(f['participant_target'] for f in forms if f.get('hof_session_action')=='refuse_participant')
            match=re.search(r'href="([^"]*participant_cursor[^"]*)">Participants suivants',html)
            if match:
                location=urlsplit(unescape(match.group(1)));page=location.path+'?'+location.query
            else:page=None
            assert pages<=3,'Private participant cursor repeated a page'
        check('B6dl participation keyset pages cover all twenty-one actual requests exactly once without GET mutation',
              pages==2 and sorted(seen)==sorted(candidates) and len(set(seen))==21 and snapshot()==before)
        cursor=parse_qs(urlsplit(last_page).query)['participant_cursor'][0];decisions=[]
        for attributes,body in re.findall(r'<form\b([^>]*)>(.*?)</form>',last_html,re.S):
            fields=Forms('<form>'+body+'</form>').forms[0]
            if fields.get('hof_session_action') not in ('admit_participant','refuse_participant'):continue
            action=re.search(r'action="([^"]+)"',attributes);assert action
            decisions.append((unescape(action.group(1)),fields))
        check('B6dl participation decision forms preserve the exact validated manager page',
              bool(decisions) and all(parse_qs(urlsplit(action).query).get('participant_cursor')==[cursor] for action,_ in decisions))
        action,fields=next(value for value in decisions if value[1]['hof_session_action']=='refuse_participant')
        destination=urlsplit(action);response=read(destination.path+'?'+destination.query,owner,fields,follow=False)
        current=read(response[2].get('Location',''),owner) if response[0]==303 else response
        check('B6dl participation deciding on the second page keeps the queue position and exact target',
              response[0]==303 and parse_qs(urlsplit(response[2]['Location']).query).get('participant_cursor')==[cursor]
              and current[0]==200 and 'Première page' in current[1]
              and next(r for r in snapshot()['roles'] if r['role']=='participant' and r['creator_id']==fields['participant_target'])['state']=='refused')
        ordered=sorted(r['creator_id'] for r in snapshot()['roles'] if r['role']=='participant')
        index=ordered.index(target);offset=(index//20)*20
        owner_path=path if offset==0 else path+'&participant_cursor='+ordered[offset-1]
        return dict(detail=path,owner_detail=owner_path,join=join,public=public,case=case_path,member=member,creator_profile=target,creator_user=extra['creator_user'],
                    primary_clock=str(primary),primary_value=format(stamp.timestamp(),'.6f'))
    finally:
        if prior is None:primary.unlink(missing_ok=True)
        else:primary.write_bytes(prior);primary.chmod(0o600)
