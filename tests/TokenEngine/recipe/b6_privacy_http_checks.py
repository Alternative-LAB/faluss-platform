"""Actual owner and moderator forms on the existing closed Hub/Fans pair. No mocked REST."""
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime
from html.parser import HTMLParser
from zoneinfo import ZoneInfo


class Forms(HTMLParser):
    def __init__(self,html):
        super().__init__();self.forms=[];self.current=None;self.feed(html)
    def handle_starttag(self,tag,attrs):
        data=dict(attrs)
        if tag=='form':self.current={}
        elif tag=='input' and self.current is not None and 'name' in data:
            if data.get('type')=='hidden' or data.get('type')=='checkbox' and 'checked' in data:
                self.current[data['name']]=data.get('value','')
    def handle_endtag(self,tag):
        if tag=='form' and self.current is not None:self.forms.append(self.current);self.current=None
    def action(self,key,value):
        for form in self.forms:
            if form.get(key)==value:return dict(form)
        raise RuntimeError('Expected protected preference form missing; private HTML omitted')


def run_checks(read,cookies,actors,month,deliver,check,origin_action):
    fan='/app/fan/espace?month='+month;creator='/app/creator/progression?month='+month
    ranking='/app/fan/classement-fans';creator_ranking='/app/fan/classements'
    panel='/app/admin?view=ranking&member='+str(actors['fan_user'])
    own=lambda family:deliver('preferences',actor=actors[family+'_user'])
    def form(action,family='fan'):
        status,html,_=read(fan if family=='fan' else creator,cookies[family])
        if status!=200:raise RuntimeError('Private preferences GET failed; no body logged')
        return Forms(html).action('hof_privacy_action',action)
    def post(action,changes=None,family='fan'):
        fields=form(action,family);fields.update(changes or {})
        return read(fan if family=='fan' else creator,cookies[family],fields)
    def review(action,changes=None):
        status,html,_=read(panel,cookies['admin'])
        if status!=200:raise RuntimeError('Private alias review GET failed; no body logged')
        fields=Forms(html).action('hof_review_action',action)
        fields.update(dict(reason='allowed_alias' if action=='approve' else 'needs_revision'))
        fields.update(changes or {})
        return read(panel,cookies['admin'],fields)
    def consent(enabled,family='fan',extra=None):
        fields=form('consent',family);fields.pop('ranking_public',None)
        if enabled:fields['ranking_public']='yes'
        fields.update(extra or {})
        return read(fan if family=='fan' else creator,cookies[family],fields)
    baseline=own('fan');other=own('creator')
    check('B6dl preference GET reads the existing approval without changing revision',
          'hof_privacy_nonce' in read(fan,cookies['fan'])[1] and own('fan')==baseline)
    check('B6dl another linked account has private Fan visibility and no synthetic alias by default',
          own('creator')['fan_public']=='0' and own('creator')['approved_alias']==''
          and Forms(read(fan,cookies['creator'])[1]).action('hof_privacy_action','alias-submit')['revision']==other['revision'])
    check('B6dl private alias moderation denies guest and linked nonadministrator',
          read(panel)[0]==403 and read(panel,cookies['fan'])[0]==403)
    invalid=form('alias-submit');invalid['hof_privacy_nonce']='invalid';invalid['public_alias']='Proposition de recette'
    check('B6dl invalid preference nonce writes neither alias nor consent',read(fan,cookies['fan'],invalid)[0]==403 and own('fan')==baseline)
    foreign=form('alias-submit');foreign['public_alias']='Pseudonyme refusé autre compte'
    check('B6dl a valid nonce from another linked session cannot change its owner',
          read(fan,cookies['creator'],foreign)[0]==403 and own('creator')==other and own('fan')==baseline)
    check('B6dl invalid alias fails without losing the last approved public name',
          post('alias-submit',dict(public_alias='<refused>'))[0]==400 and own('fan')==baseline)
    mixed=form('consent');mixed.update(admission_action='apply',category='arts')
    check('B6dl ambiguous privacy and admission POST is refused before either mutation',read(fan,cookies['fan'],mixed)[0]==400 and own('fan')==baseline)
    check('B6dl forged owner and family cannot target the other account',
          consent(False,extra=dict(member_id=actors['creator_user'],family='creator'))[0]==200
          and own('creator')==other and own('fan')['fan_public']=='0')
    check('B6dl native privacy POST is not dispatched as creator admission and preserves private history',
          'Fan de recette B6' not in read(ranking,cookies['fan'])[1]
          and deliver('statement',actor=actors['fan_user'],family='fan',month=month)['points']=='3')
    assert consent(True)[0]==200
    concurrent=form('consent');concurrent.pop('ranking_public',None);revision=int(own('fan')['revision'])
    with ThreadPoolExecutor(max_workers=2) as pool:
        responses=list(pool.map(lambda _:read(fan,cookies['fan'],concurrent)[0],range(2)))
    check('B6dl concurrent native preference submissions commit one revision and refuse stale replay',
          sorted(responses)==[200,409] and int(own('fan')['revision'])==revision+1)
    assert consent(True)[0]==200
    assert post('alias-submit',dict(public_alias='Pseudonyme proposé UI'))[0]==200
    check('B6dl native pending proposal never replaces the approved displayed pseudonym',
          'Fan de recette B6' in read(ranking,cookies['fan'])[1] and 'Pseudonyme proposé UI' not in read(ranking,cookies['fan'])[1])
    before_review=own('fan')
    check('B6dl invalid target-bound moderator nonce cannot approve the proposal',
          review('approve',dict(hof_review_nonce='invalid'))[0]==403 and own('fan')==before_review)
    assert review('reject')[0]==200
    check('B6dl rejecting a proposal preserves only the previously approved public name',
          own('fan')['alias_state']=='rejected' and 'Fan de recette B6' in read(ranking,cookies['fan'])[1])
    assert post('alias-submit',dict(public_alias='Pseudonyme proposé UI'))[0]==200
    review_form=Forms(read(panel,cookies['admin'])[1]).action('hof_review_action','approve')
    review_form['reason']='allowed_alias';revision=int(own('fan')['revision'])
    with ThreadPoolExecutor(max_workers=2) as pool:
        responses=list(pool.map(lambda _:read(panel,cookies['admin'],review_form)[0],range(2)))
    check('B6dl concurrent moderator forms approve once with the submitted revision',
          sorted(responses)==[200,409] and int(own('fan')['revision'])==revision+1)
    check('B6dl approved native decision updates the actual B6 delivered name without a second consumption',
          'Pseudonyme proposé UI' in read(ranking,cookies['fan'])[1] and own('fan')['approved_alias']=='Pseudonyme proposé UI')
    check('B6dl native withdrawal requires explicit confirmation',post('alias-withdraw')[0]==400 and own('fan')['approved_alias']!='')
    assert review('revoke',dict(confirm_revoke='yes'))[0]==200
    check('B6dl native moderator revocation closes public display and preserves the private corrected statement',
          own('fan')['approved_alias']=='' and own('fan')['fan_public']=='0'
          and 'Pseudonyme proposé UI' not in read(ranking,cookies['fan'])[1]
          and deliver('statement',actor=actors['fan_user'],family='fan',month=month)['points']=='3')
    assert post('alias-submit',dict(public_alias='Fan de recette B6'))[0]==200
    assert review('approve')[0]==200
    check('B6dl moderator approval never silently renews a revoked public consent',
          own('fan')['fan_public']=='0' and 'Fan de recette B6' not in read(ranking,cookies['fan'])[1])
    assert consent(True)[0]==200
    assert post('alias-withdraw',dict(confirm_withdraw='yes'))[0]==200
    check('B6dl confirmed owner withdrawal clears the alias and consent without erasing private points',
          own('fan')['alias_state']=='withdrawn' and own('fan')['approved_alias']=='' and own('fan')['fan_public']=='0'
          and deliver('statement',actor=actors['fan_user'],family='fan',month=month)['points']=='3')
    assert post('alias-submit',dict(public_alias='Fan de recette B6'))[0]==200
    assert review('approve')[0]==200
    assert consent(True)[0]==200
    check('B6dl creator native consent changes no Fan preferences or economic history',
          consent(False,'creator')[0]==200 and 'Atelier de recette B6' not in read(creator_ranking,cookies['fan'])[1]
          and own('fan')['fan_public']=='1' and deliver('statement',actor=actors['creator_user'],family='creator',month=month)['points']=='3')
    assert consent(True,'creator')[0]==200
    if origin_action:
        deliver('prepare-read',action_id=origin_action)
        check('B6dl public consent withdrawal remains available during uncertain Hub freshness',
              consent(False)[0]==200 and own('fan')['fan_public']=='0' and 'Relevé indisponible' in read(fan,cookies['fan'])[1])
        assert consent(True)[0]==200
        assert deliver('advance-read',action_id=origin_action)['phase']=='current'
    recorded=own('fan');zone=deliver('presentation-timezone',actor=actors['admin_user'],zone='Europe/Paris')
    try:
        date=datetime.strptime(recorded['updated_at'],'%Y-%m-%d %H:%M:%S.%f').replace(tzinfo=ZoneInfo('UTC')).astimezone(ZoneInfo('Europe/Paris'))
        expected=date.strftime('%d/%m/%Y à %H:%M:%S')
        html=read(panel,cookies['admin'])[1]
        check('B6dl alias moderation uses WordPress timezone explicitly without rewriting the UTC record',
              'Europe/Paris' in html and expected in html and own('fan')==recorded)
    finally:deliver('presentation-timezone',actor=actors['admin_user'],zone=zone['previous'])
