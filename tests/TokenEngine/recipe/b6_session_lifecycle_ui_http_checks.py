"""Native lifecycle POSTs through the approved real signed bridge, on disposable nodes only."""
from datetime import datetime,timedelta,timezone
import json
from urllib.parse import parse_qs,urlsplit
from b6_privacy_http_checks import Forms


def run_checks(root,read,cookies,actors,deliver,check,set_clock):
    owner=cookies['creator'];admin=cookies['admin'];new='/app/creator/hof?manage=new'
    snapshot=lambda id:deliver('private-session',actor=actors['creator_user'],session=id)
    bindings=lambda id:deliver('private-session-bindings',actor=actors['creator_user'],session=id)
    def form(path,action,moderator=False):
        status,html,_=read(path,admin if moderator else owner)
        if status!=200:raise RuntimeError('Native lifecycle GET refused; private response omitted')
        return Forms(html).action('session_review_action' if moderator else 'hof_session_action',action)
    def prepare(title,duration_seconds=1500,verify_unapproved=False):
        stamp=datetime.fromtimestamp(float((root/'corpus-sql-clock').read_text()),timezone.utc)
        fields=form(new,'create');fields.update(title=title,rules_text='Règles fictives de cycle natif. Participation volontaire.',
            category='arts',scope='international',country='',territory_ref='',timezone='UTC',
            starts_local=(stamp-timedelta(minutes=1)).strftime('%Y-%m-%dT%H:%M:%S'),ends_local=(stamp+timedelta(seconds=duration_seconds)).strftime('%Y-%m-%dT%H:%M:%S'),starts_offset='',ends_offset='')
        response=read(new,owner,fields,follow=False)
        if response[0]!=303:raise RuntimeError('Native lifecycle draft creation failed; response omitted')
        path=response[2]['Location'];id=parse_qs(urlsplit(path).query)['session'][0];panel='/app/admin?view=sessions&session='+id
        assert read(path,owner,form(path,'submit'))[0]==200
        if verify_unapproved:
            pending=snapshot(id);forged=form(path,'edit');forged['hof_session_action']='open'
            check('B6dl lifecycle a crafted opening cannot bypass the exact pending rules review',
                  read(path,owner,forged)[0]==409 and snapshot(id)==pending and bindings(id)==[])
        assert read(panel,admin,form(panel,'approve',True))[0]==200
        return id,path,panel

    id,path,panel=prepare('Cycle natif fictif — reprise',verify_unapproved=True)
    baseline=snapshot(id);opening=form(path,'open')
    check('B6dl lifecycle invalid opening nonce changes neither rules nor lifecycle binding',
          read(path,owner,dict(opening,hof_session_nonce='invalid'))[0]==403 and snapshot(id)==baseline and bindings(id)==[])
    check('B6dl lifecycle Fan cannot initiate a creator opening even with a copied form',
          read(path,cookies['fan'],opening)[0]==403 and snapshot(id)==baseline and bindings(id)==[])
    before_get=bindings(id);assert read(path,owner)[0]==200
    check('B6dl lifecycle GET never prepares a network action or changes a session',snapshot(id)==baseline and bindings(id)==before_get)
    fault=root/'b6-ui-drop-response';fault.write_text('2');fault.chmod(0o600)
    response=read(path,owner,opening);uncertain=snapshot(id);first=bindings(id)
    check('B6dl lifecycle lost real Hub register response leaves native opening pending honestly',
          response[0]==202 and uncertain['session']['state']=='opening' and len(first)==1 and first[0]['applied_at']=='')
    check('B6dl lifecycle pending page offers a protected same-action resume instead of a new opening',
          form(path,'resume')['session_revision']==uncertain['session']['revision']
          and not any(row.get('hof_session_action')=='open' for row in Forms(response[1]).forms))
    assert read(path,owner,form(path,'resume'))[0]==200
    current=snapshot(id);second=bindings(id)
    check('B6dl lifecycle primary lookup recovers the original registered action and stable key',
          current['session']['state']=='open' and len(second)==1 and second[0]['action_id']==first[0]['action_id']
          and second[0]['operation_key_sha256']==first[0]['operation_key_sha256'] and second[0]['applied_at']!='')
    check('B6dl lifecycle stale opening form cannot allocate another action or rewrite frozen rules',
          read(path,owner,opening)[0]==409 and bindings(id)==second and snapshot(id)==current)
    # A crafted early completion must still be rejected when its button is absent.
    early=form(path,'cancel');early.update(hof_session_action='complete',hof_session_confirmation='yes')
    check('B6dl lifecycle completion before frozen deadline is refused before entering closing',
          read(path,owner,early)[0]==409 and snapshot(id)==current and bindings(id)==second)
    cancel=form(path,'cancel')
    check('B6dl lifecycle cancellation requires explicit confirmation',read(path,owner,cancel)[0]==400 and snapshot(id)==current)
    cancel['hof_session_confirmation']='yes';fault.write_text('2');fault.chmod(0o600)
    response=read(path,owner,cancel);closing=snapshot(id);third=bindings(id)
    check('B6dl lifecycle lost committed close response leaves local choices closed with a pending result',
          response[0]==202 and closing['session']['state']=='closing' and len(third)==2 and closing['session']['closure_action']=='cancelled')
    assert read(path,owner,form(path,'resume'))[0]==200
    closed=snapshot(id);fourth=bindings(id)
    check('B6dl lifecycle primary close lookup preserves both action identities and reaches cancelled once',
          closed['session']['state']=='cancelled' and [row['action_id'] for row in third]==[row['action_id'] for row in fourth]
          and [row['operation_key_sha256'] for row in third]==[row['operation_key_sha256'] for row in fourth])
    # The application performs a distinct approved primary read, not an inferred historical ACK.
    public=deliver('session',session=id)
    check('B6dl lifecycle native closure coordinates the separate primary freshness before public delivery',
          public.get('id')==id and public['state']=='cancelled' and public['items']==[])

    id,path,panel=prepare('Cycle natif fictif — suspension')
    assert read(path,owner,form(path,'open'))[0]==200
    revoke=form(panel,'revoke',True);revoke.update(reason='needs_revision',confirm_revoke='yes')
    fault.write_text('2');fault.chmod(0o600)
    response=read(panel,admin,revoke);pending=snapshot(id);saved=bindings(id)
    check('B6dl lifecycle moderator keeps its committed revocation distinct from uncertain closure',
          response[0]==202 and pending['review']['state']=='rejected' and pending['session']['state']=='closing'
          and 'La décision est enregistrée' in response[1] and pending['session']['closure_action']=='suspended')
    resume=form(panel,'resume',True)
    check('B6dl lifecycle administrator resume rejects invalid nonce without changing committed review',
          read(panel,admin,dict(resume,session_review_nonce='invalid'))[0]==403 and snapshot(id)==pending and bindings(id)==saved)
    assert read(panel,admin,resume)[0]==200
    suspended=snapshot(id);recovered=bindings(id)
    check('B6dl lifecycle administrator recovers the same close without another moderation decision',
          suspended['session']['state']=='suspended' and suspended['history']==pending['history']
          and [row['action_id'] for row in saved]==[row['action_id'] for row in recovered]
          and [row['operation_key_sha256'] for row in saved]==[row['operation_key_sha256'] for row in recovered])
    check('B6dl lifecycle closed or suspended session cannot be reopened by a stale transport form',
          read(panel,admin,resume)[0]==409 and snapshot(id)==suspended and bindings(id)==recovered)

    premature=form(path,'refresh');premature['hof_session_action']='readmit'
    check('B6dl lifecycle readmission cannot bypass revoked rules approval',
          read(path,owner,premature)[0]==409 and snapshot(id)==suspended and bindings(id)==recovered)
    appeal=form(path,'appeal');appeal['explanation']='Recours fictif pour la réadmission aux mêmes règles.'
    assert read(path,owner,appeal)[0]==200
    assert read(panel,admin,form(panel,'approve',True))[0]==200
    assert read(path,owner,form(path,'readmit'))[0]==200
    readmitted=snapshot(id);new_bindings=bindings(id)
    previous={row['action_id']:row['operation_key_sha256'] for row in recovered}
    check('B6dl lifecycle approved readmission attests a new version while preserving every previous key',
          readmitted['session']['state']=='open' and int(readmitted['session']['barrier_version'])==int(suspended['session']['barrier_version'])+1
          and readmitted['session']['frozen_sha256']==suspended['session']['frozen_sha256'] and len(new_bindings)==len(recovered)+1
          and all(previous[row['action_id']]==row['operation_key_sha256'] for row in new_bindings if row['action_id'] in previous)
          and deliver('session',session=id).get('id')==id)
    check('B6dl lifecycle an old closure form cannot suspend a readmitted version',
          read(panel,admin,resume)[0]==409 and snapshot(id)==readmitted and bindings(id)==new_bindings)
    cancel=form(path,'cancel');cancel['hof_session_confirmation']='yes';assert read(path,owner,cancel)[0]==200

    id,path,panel=prepare('Cycle natif fictif — fraîcheur')
    fault.write_text('3');fault.chmod(0o600)
    response=read(path,owner,form(path,'open'));opened=snapshot(id);stable=bindings(id)
    check('B6dl lifecycle confirmed opening does not invent current publication after a lost primary read',
          response[0]==202 and opened['session']['state']=='open' and 'La transition est confirmée' in response[1]
          and 'error' in deliver('session',session=id))
    assert read(path,owner,form(path,'refresh'))[0]==200
    check('B6dl lifecycle explicit native freshness recovery changes neither session nor operation key',
          snapshot(id)==opened and bindings(id)==stable and deliver('session',session=id).get('id')==id)
    cancel=form(path,'cancel');cancel['hof_session_confirmation']='yes'
    assert read(path,owner,cancel)[0]==200

    id,path,panel=prepare('Cycle natif fictif — échéance',duration_seconds=15)
    assert read(path,owner,form(path,'open'))[0]==200
    ends=datetime.strptime(snapshot(id)['session']['ends_at'],'%Y-%m-%d %H:%M:%S.%f').replace(tzinfo=timezone.utc).timestamp()
    # The existing fixture can be ahead of wall time. Advance its shared primary clock explicitly.
    # No browser timestamp, direct SQL override, environment preparation or timeout is changed here.
    set_clock(format(ends,'.6f'))
    assert float((root/'corpus-sql-clock').read_text())>=ends,'Existing isolated clock did not reach the frozen deadline.'
    # The joined corpus fixture has its own clock; the barrier endpoint normally uses real primary time.
    # Reuse the existing private barrier-clock convention only for this new application case.
    primary_clock=root/'barrier-primary-clock';primary_clock.write_text(format(ends,'.6f'));primary_clock.chmod(0o600)
    try:
        complete=form(path,'complete');complete['hof_session_confirmation']='yes'
        response=read(path,owner,complete)
        if response[0]!=200:
            current=snapshot(id)['session']
            raise RuntimeError('Native completion diagnostics: '+json.dumps(dict(status=response[0],state=current['state'],closure_action=current['closure_action'],
                bindings=[{key:row.get(key) for key in ('operation','transport_phase','transport_state')} for row in bindings(id)])))
        finished=snapshot(id);proof=deliver('session',session=id)
        check('B6dl lifecycle native deadline completion reaches attested closed without declaring a winner',
              finished['session']['state']=='closed' and finished['session']['closure_action']=='closed'
              and proof.get('state')=='closed' and proof['items']==[])
    finally:primary_clock.unlink(missing_ok=True)
