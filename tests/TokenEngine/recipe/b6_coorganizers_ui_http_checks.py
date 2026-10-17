"""Native private invitations and explicit responses; no implicit participation or Hub admission."""
from concurrent.futures import ThreadPoolExecutor
from html import unescape
import re
from urllib.parse import parse_qs,urlsplit

from b6_privacy_http_checks import Forms


def run_checks(read,cookies,actors,management,deliver,check):
    path=management['detail'];owner=cookies['creator']
    id=parse_qs(urlsplit(path).query)['session'][0]
    snapshot=lambda:deliver('private-session',actor=actors['creator_user'],session=id)
    def form(action,cookie=owner):
        status,html,_=read(path,cookie)
        if status!=200:raise RuntimeError('Private organizer GET failed; response omitted')
        return Forms(html).action('hof_session_action',action)
    extra=deliver('fixture-extra-creator',actor=actors['admin_user'])
    other=deliver('fixture-cookie',actor=extra['creator_user'])
    before=snapshot();invite=form('invite_organizer');invite['organizer_target']=extra['creator_profile']
    check('B6dl coorganizer guest Fan and unrelated creator cannot inspect or mutate a private invitation',
          all(read(path,cookie)[0]==403 for cookie in (None,cookies['fan'],other))
          and all(read(path,cookie,invite)[0]==403 for cookie in (None,cookies['fan'],other)) and snapshot()==before)
    check('B6dl coorganizer invalid nonce cannot invite even an eligible public creator',
          read(path,owner,dict(invite,hof_session_nonce='invalid'))[0]==403 and snapshot()==before)
    check('B6dl coorganizer GET and invalid public candidate cursor cannot write a role',
          read(path,owner)[0]==200 and read(path+'&coorganizer_cursor=invalid',owner)[0]==400 and snapshot()==before)
    with ThreadPoolExecutor(max_workers=2) as pool:answers=list(pool.map(lambda _:read(path,owner,invite)[0],range(2)))
    invited=snapshot();target=[row for row in invited['roles'] if row['creator_id']==extra['creator_profile']]
    check('B6dl coorganizer concurrent native invitations commit one role and one session revision',
          sorted(answers)==[200,409] and len(target)==1 and target[0]['state']=='invited'
          and int(invited['session']['revision'])==int(before['session']['revision'])+1)
    own=deliver('private-sessions',actor=extra['creator_user']);status,html,_=read(path,other)
    check('B6dl coorganizer invitation is privately discoverable without an organizer or participant power',
          any(row['session_id']==id for row in own) and status==200
          and not any(row.get('hof_session_action') in ('edit','open','invite_organizer') for row in Forms(html).forms)
          and not any(row['role']=='participant' and row['creator_id']==extra['creator_profile'] for row in invited['roles']))
    accept=form('accept_organizer',other)
    forged=dict(accept,hof_session_action='invite_organizer',organizer_target=actors['creator_profile'])
    check('B6dl coorganizer invited role cannot forge an organizer decision with its own valid nonce',
          read(path,other,forged)[0]==403 and snapshot()==invited)
    check('B6dl coorganizer an owner cookie cannot answer an invitation with the other member nonce',
          read(path,owner,accept)[0]==403 and snapshot()==invited)
    with ThreadPoolExecutor(max_workers=2) as pool:answers=list(pool.map(lambda _:read(path,other,accept)[0],range(2)))
    accepted=snapshot();target=[row for row in accepted['roles'] if row['creator_id']==extra['creator_profile']]
    check('B6dl coorganizer explicit native acceptance commits once without opening or participating',
          sorted(answers)==[200,409] and len(target)==1 and target[0]['state']=='accepted' and target[0]['role']=='organizer'
          and accepted['session']['state']=='draft' and int(accepted['session']['revision'])==int(invited['session']['revision'])+1)
    status,html,_=read(path,other)
    check('B6dl coorganizer accepted member can invite but receives no owner rules or lifecycle controls',
          status==200 and any(row.get('hof_session_action')=='invite_organizer' for row in Forms(html).forms)
          and not any(row.get('hof_session_action') in ('edit','open','readmit','cancel','complete') for row in Forms(html).forms))

    declined=deliver('fixture-extra-creator',actor=actors['admin_user'])
    declined_cookie=deliver('fixture-cookie',actor=declined['creator_user'])
    invite=form('invite_organizer',other);invite['organizer_target']=declined['creator_profile']
    assert read(path,other,invite)[0]==200
    assert deliver('suspend',actor=actors['admin_user'],creator_profile=declined['creator_profile'],status='suspended')
    try:
        refusal=form('refuse_organizer',declined_cookie);before=snapshot()
        check('B6dl coorganizer suspended invited creator keeps private refusal but cannot accept',
              not any(row.get('hof_session_action')=='accept_organizer' for row in Forms(read(path,declined_cookie)[1]).forms)
              and read(path,declined_cookie,refusal)[0]==200
              and any(row['creator_id']==declined['creator_profile'] and row['state']=='refused' for row in snapshot()['roles']))
        before=snapshot();forged=form('invite_organizer');forged['organizer_target']=declined['creator_profile']
        check('B6dl coorganizer suspended presentation cannot be selected by forging its technical identity',
              read(path,owner,forged)[0]==409 and snapshot()==before)
    finally:deliver('suspend',actor=actors['admin_user'],creator_profile=declined['creator_profile'],status='active')

    candidates=[deliver('fixture-extra-creator',actor=actors['admin_user'])['creator_profile'] for _ in range(22)]
    found=set();next_path=path;pages=0;before=snapshot()
    while next_path and pages<10:
        status,html,_=read(next_path,owner);assert status==200
        select=re.search(r'<select\b[^>]*id="fu-session-organizer"[^>]*>(.*?)</select>',html,re.S)
        if select:found.update(unescape(value) for value in re.findall(r'<option value="([^"]+)"',select[1]))
        links=[unescape(href) for href in re.findall(r'<a\b[^>]*href="([^"]+)"[^>]*>Créateurs suivants',html)]
        next_path=urlsplit(links[0]).path+'?'+urlsplit(links[0]).query if links else None;pages+=1
    check('B6dl coorganizer native candidate pagination reaches every eligible creator beyond twenty without mutation',
          pages>=2 and next_path is None and set(candidates)<=found and actors['creator_profile'] not in found
          and extra['creator_profile'] not in found and snapshot()==before)
