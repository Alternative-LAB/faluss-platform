"""Native session owner/moderator HTTP forms on the isolated pair, with real cookies and CAS."""
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime
from html import unescape
import re
from urllib.parse import parse_qs, urlsplit
from zoneinfo import ZoneInfo

from b6_privacy_http_checks import Forms


def run_checks(read,cookies,actors,session,deliver,check):
    entry='/app/creator/hof?manage=sessions';new='/app/creator/hof?manage=new'
    creator=cookies['creator'];admin=cookies['admin']
    extra=deliver('fixture-extra-creator',actor=actors['admin_user'])
    other=deliver('fixture-cookie',actor=extra['creator_user'])
    own=lambda:deliver('private-sessions',actor=actors['creator_user'])
    snapshot=lambda id:deliver('private-session',actor=actors['creator_user'],session=id)
    def owner_form(path,action):
        status,html,_=read(path,creator)
        if status!=200:raise RuntimeError('Session owner GET refused; no private response logged')
        return Forms(html).action('hof_session_action',action)
    def review_form(path,action):
        status,html,_=read(path,admin)
        if status!=200:raise RuntimeError('Session moderator GET refused; no private response logged')
        return Forms(html).action('session_review_action',action)
    def visible(html):
        return unescape(re.sub(r'<[^>]+>',' ',re.sub(r'<(?:script|style)\b[^>]*>.*?</(?:script|style)>','',html,flags=re.S)))
    def edit_fields(path,row,**changes):
        fields=owner_form(path,'edit');fields.update(values)
        for part in ('starts','ends'):
            local=datetime.strptime(row[part+'_at'],'%Y-%m-%d %H:%M:%S.%f').replace(tzinfo=ZoneInfo('UTC')).astimezone(ZoneInfo(row['timezone']))
            fields[part+'_local']=local.strftime('%Y-%m-%dT%H:%M:%S')
            fields[part+'_offset']=local.strftime('%z')[:3]+':'+local.strftime('%z')[3:]
        fields.update(changes);return fields

    baseline=own();original=snapshot(session['session_id'])['session']
    initial=read(entry,creator)
    check('B6dl session UI owner session list is private and GET does not change session records',
          initial[0]==200 and 'Mes sessions' in initial[1] and 'no-store' in initial[2].get('Cache-Control','') and own()==baseline)
    check('B6dl session UI guest Fan and unlinked administrator cannot enter creator session management',
          all(read(entry,cookie)[0]==403 for cookie in (None,cookies['fan'],admin)))
    check('B6dl session UI a different linked creator starts with no unrelated private session',
          read(entry,other)[0]==200 and original['title'] not in read(entry,other)[1])
    check('B6dl session UI invalid management mode and private cursor are refused without writes',
          read('/app/creator/hof?manage=unknown',creator)[0]==400
          and read(entry+'&sessions_cursor=invalid',creator)[0]==400 and own()==baseline)
    values=dict(title='Session native de recette',rules_text='Participation volontaire. Règles fictives soumises à examen.',
                category='arts',scope='international',country='',territory_ref='',timezone='Europe/Paris',starts_offset='',ends_offset='')
    for part in ('starts','ends'):
        stamp=datetime.strptime(original[part+'_at'],'%Y-%m-%d %H:%M:%S.%f').replace(tzinfo=ZoneInfo('UTC')).astimezone(ZoneInfo('Europe/Paris'))
        values[part+'_local']=stamp.strftime('%Y-%m-%dT%H:%M:%S')
    fields=owner_form(new,'create');fields.update(values,hof_session_nonce='invalid')
    check('B6dl session UI invalid create nonce cannot write a draft',read(new,creator,fields)[0]==403 and own()==baseline)
    fields=owner_form(new,'create');fields.update(values)
    check('B6dl session UI another linked identity cannot reuse the owner create nonce',read(new,other,fields)[0]==403 and own()==baseline)
    mixed=dict(fields,admission_action='apply')
    check('B6dl session UI ambiguous session and admission POST is refused before either action',read(new,creator,mixed)[0]==400 and own()==baseline)
    check('B6dl session UI a session POST on another screen cannot mutate governance',read('/app/creator/progression',creator,fields)[0]==400 and own()==baseline)
    fields.update(owner_creator_id=extra['creator_profile'],session_id=session['session_id'])
    status,_,headers=read(new,creator,fields,follow=False)
    location=headers.get('Location','');selection=parse_qs(urlsplit(location).query)
    check('B6dl session UI native create redirects with 303 to its own relative private detail',
          status==303 and location.startswith('/app/creator/hof?') and selection.get('manage')==['session'] and len(selection.get('session',[]))==1)
    id=selection['session'][0];path=location;panel='/app/admin?view=sessions&session='+id
    created=snapshot(id);row=created['session']
    check('B6dl session UI forged owner and target fields cannot replace the linked creator or existing session',
          row['owner_creator_id']==actors['creator_profile'] and row['state']=='draft'
          and snapshot(session['session_id'])['session']==original and created['review'] is None)
    check('B6dl session UI unrelated creator cannot read or mutate the private draft',
          read(path,other)[0]==403 and read(path,other,dict(fields,hof_session_action='edit',session_revision=row['revision']))[0]==403)
    html=read(path,creator)[1]
    check('B6dl session UI owner draft renders actual rules without visible technical identity',
          row['title'] in html and 'hof_session_nonce' in html and not re.search(r'\b[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\b',visible(html)))
    check('B6dl session UI draft and unsubmitted rules never enter public session discovery',
          id not in [item['id'] for item in deliver('sessions')['items']]
          and row['title'] not in read('/app/fan/hof')[1])
    invalid=edit_fields(path,row,title='Texte conservé de recette',rules_text='<refused>')
    response=read(path,creator,invalid)
    check('B6dl session UI invalid edit keeps the submitted text and persisted draft unchanged',
          response[0]==400 and 'Texte conservé de recette' in response[1] and snapshot(id)==created)
    gap=edit_fields(path,row,starts_local='2026-03-29T02:30:00',ends_local='2026-03-29T04:00:00',starts_offset='',ends_offset='')
    response=read(path,creator,gap)
    check('B6dl session UI native nonexistent local time is refused without inventing a UTC instant',
          response[0]==400 and 'Cette heure n’existe pas' in response[1] and snapshot(id)==created)
    ambiguous=edit_fields(path,row,starts_local='2026-10-25T02:30:00',ends_local='2026-10-25T04:00:00',starts_offset='',ends_offset='')
    response=read(path,creator,ambiguous)
    check('B6dl session UI native repeated local hour requires a real explicit offset choice',
          response[0]==400 and 'Choisissez une occurrence' in response[1] and '+02:00' in response[1] and '+01:00' in response[1] and snapshot(id)==created)
    ambiguous['starts_offset']='+01:00'
    check('B6dl session UI chosen local occurrence persists its exact UTC instant',
          read(path,creator,ambiguous)[0]==200 and snapshot(id)['session']['starts_at']=='2026-10-25 01:30:00.000000')
    row=snapshot(id)['session'];edit=edit_fields(path,row,title=values['title']+' révisée')
    with ThreadPoolExecutor(max_workers=2) as pool:
        responses=list(pool.map(lambda _:read(path,creator,edit),range(2)))
    row=snapshot(id)['session'];loser=next((result for result in responses if result[0]==409),None)
    check('B6dl session UI concurrent owner edits accept one revision and retain losing input without a fresh edit form',
          sorted(result[0] for result in responses)==[200,409] and int(row['revision'])==int(edit['session_revision'])+1
          and loser is not None and 'Votre saisie, non enregistrée' in loser[1] and not any(form.get('hof_session_action')=='edit' for form in Forms(loser[1]).forms))
    fields=owner_form(path,'submit');assert read(path,creator,fields)[0]==200
    pending=snapshot(id)
    check('B6dl session UI native submit journals one pending rules fingerprint without opening the draft',
          pending['review']['state']=='pending' and pending['session']['state']=='draft' and pending['history'][-1]['action']=='submit')
    check('B6dl session UI review panel denies guest Fan and creator even with a session reference',
          all(read(panel,cookie)[0]==403 for cookie in (None,cookies['fan'],creator,other)))
    decision=review_form(panel,'approve')
    check('B6dl session UI moderator nonce is bound to the exact session and rejects tampering without writes',
          read(panel,admin,dict(decision,session_review_nonce='invalid'))[0]==403
          and read(panel,admin,dict(decision,session_id=session['session_id']))[0]==403 and snapshot(id)==pending)
    check('B6dl session UI invalid moderator reason and history cursor cannot partially record a decision',
          read(panel,admin,dict(decision,reason='invented'))[0]==400
          and read(panel+'&history_cursor=invalid',admin,decision)[0]==400 and snapshot(id)==pending)
    with ThreadPoolExecutor(max_workers=2) as pool:
        responses=list(pool.map(lambda _:read(panel,admin,decision)[0],range(2)))
    approved=snapshot(id)
    check('B6dl session UI concurrent moderator approval commits one journal revision',
          sorted(responses)==[200,409] and approved['review']['state']=='approved'
          and len(approved['history'])==len(pending['history'])+1)
    check('B6dl session UI approved rules alone do not publish a draft or invent a Hub opening',
          approved['session']['state']=='draft' and row['title'] not in read('/app/fan/hof')[1])
    stale_revoke=review_form(panel,'revoke');stale_revoke.update(reason='needs_revision',confirm_revoke='yes')
    row=approved['session'];assert read(path,creator,edit_fields(path,row,title=values['title']+' finale'))[0]==200
    changed=snapshot(id)
    check('B6dl session UI edited rules invalidate an old approval and stale moderator decision',
          'nouvelle soumission' in read(panel,admin)[1] and read(panel,admin,stale_revoke)[0]==409 and snapshot(id)==changed)
    assert read(path,creator,owner_form(path,'submit'))[0]==200
    reject=review_form(panel,'reject');reject['reason']='needs_revision'
    assert read(panel,admin,reject)[0]==200
    rejected=snapshot(id)
    appeal=owner_form(path,'appeal');appeal['explanation']='Demande de réexamen fictive et motivée.'
    check('B6dl session UI native rejection is motivated and opens the owner appeal form',
          rejected['review']['state']=='rejected' and 'Motif : règles à revoir.' in read(path,creator)[1])
    assert read(path,creator,appeal)[0]==200
    appealed=snapshot(id)
    response=read(path,creator,appeal)
    check('B6dl session UI appeal is journaled once and stale replay preserves its text without a duplicate',
          appealed['review']['state']=='appealed' and response[0]==409 and appeal['explanation'] in response[1] and snapshot(id)==appealed)
    assert read(panel,admin,review_form(panel,'approve'))[0]==200
    revoke=review_form(panel,'revoke');revoke['reason']='needs_revision'
    before=snapshot(id)
    check('B6dl session UI moderator revocation needs explicit confirmation',read(panel,admin,revoke)[0]==400 and snapshot(id)==before)
    revoke['confirm_revoke']='yes';assert read(panel,admin,revoke)[0]==200
    revoked=snapshot(id)
    check('B6dl session UI native revocation preserves review history and remains absent from public sessions',
          revoked['review']['state']=='rejected' and revoked['history'][-1]['action']=='revoke'
          and revoked['session']['state']=='draft' and revoked['session']['title'] not in read('/app/fan/hof')[1])
    # Restore a pending session for real browser screenshots, never a publicly opened one.
    appeal=owner_form(path,'appeal');appeal['explanation']='Recours fictif conservé pour la recette visuelle.'
    assert read(path,creator,appeal)[0]==200
    legacy='/faluss-fans/creator/hof?'+urlsplit(path).query
    old_get=read(legacy,creator,follow=False);old_post=read(legacy,creator,appeal,follow=False)
    check('B6dl session UI historical GET and POST retain the private selection with safe redirect semantics',
          old_get[0]==302 and old_post[0]==307 and parse_qs(urlsplit(old_get[2].get('Location','')).query)==parse_qs(urlsplit(path).query)
          and parse_qs(urlsplit(old_post[2].get('Location','')).query)==parse_qs(urlsplit(path).query))
    assert deliver('suspend',actor=actors['admin_user'],creator_profile=actors['creator_profile'],status='suspended')
    try:
        before=snapshot(id);response=read(path,creator)
        check('B6dl session UI suspended creator retains private history without an editable draft',
              response[0]==200 and 'Recours en attente' in response[1]
              and not any(form.get('hof_session_action')=='edit' for form in Forms(response[1]).forms) and snapshot(id)==before)
    finally:deliver('suspend',actor=actors['admin_user'],creator_profile=actors['creator_profile'],status='active')
    recorded=snapshot(id);zone=deliver('presentation-timezone',actor=actors['admin_user'],zone='Europe/Paris')
    try:
        expected=datetime.strptime(recorded['session']['starts_at'],'%Y-%m-%d %H:%M:%S.%f').replace(tzinfo=ZoneInfo('UTC')).astimezone(ZoneInfo('Europe/Paris')).strftime('%d/%m/%Y à %H:%M:%S')
        html=read(panel,admin)[1]
        check('B6dl session UI moderation displays the configured WordPress timezone without rewriting UTC instants',
              'Horaires affichés dans le fuseau WordPress : Europe/Paris.' in html and expected in html and snapshot(id)==recorded)
    finally:deliver('presentation-timezone',actor=actors['admin_user'],zone=zone['previous'])
    return dict(list=entry,new=new,detail=path,panel=panel)
