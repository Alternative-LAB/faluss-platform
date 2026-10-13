"""Actual barrier HTTP and complete cache on the same private Fans primary; no simulated ACK."""
import copy
import json
import uuid


def run_checks(root,source,cli_path,check,command,fixture,hub_sql):
    origin=fixture['origin'];sql=fixture['fan_sql']
    endpoint=(root/'corpus-endpoint').read_text().replace('/corpus','/barrier')
    def invoke(role,action,**extra):
        path=root/(uuid.uuid4().hex+'.json')
        path.write_text(json.dumps(dict(action=action,origin=origin,endpoint=endpoint,**extra)));path.chmod(0o600)
        node=root/'wordpress' if role=='hub' else fixture['fans']
        return json.loads(command(['php',cli_path,'--allow-root','--path='+str(node),'eval-file',
            str(source/'tests/TokenEngine/recipe/b6-joined-worker.php'),str(path),'--use-include']))
    ledger=hub_sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id')
    historical=hub_sql('SELECT * FROM wp_token_engine_ledger ORDER BY id')
    policies={}
    for role in ('hub','fans'):
        policies[role]=copy.deepcopy(fixture['policies'][role])
        policies[role]['permissions']+=['pf.ranking.context.register','pf.ranking.context.close','pf.lookup']
        fixture['policy'](role,policies[role])
    check('B6join explicit same-node fixture installs separate barrier metadata',
        invoke('hub','install')==dict(ready=True) and invoke('fans','install')==dict(ready=True))
    marker=root/'b6-joined-enabled';marker.write_text('fixture-only');marker.chmod(0o600)
    # Read the owner fixture descriptor; the genuine gateway then attests it independently.
    encoded=hub_sql("SELECT HEX(descriptor_json) FROM wp_token_engine_pf_b3b_barriers WHERE "
        "JSON_UNQUOTE(JSON_EXTRACT(descriptor_json,'$.content.kind'))='origin' AND "
        "JSON_UNQUOTE(JSON_EXTRACT(descriptor_json,'$.content.origin_id'))='"+origin+"' AND version=1")
    descriptor=json.loads(bytes.fromhex(encoded).decode())
    fields=dict(operation='register',action_id=str(uuid.uuid4()),origin_id=origin,policy_version='1.0.0',object=descriptor)
    prepared=invoke('fans','prepare',fields=fields)
    check('B6join an unacknowledged origin cannot compose a complete cache',
        'error' in invoke('fans','compose',action_id=prepared['action_id']))
    reply=invoke('fans','advance',action_id=prepared['action_id'])
    check('B6join genuine signed HTTP attests the same authoritative origin',reply['state']=='acknowledged')
    ack=invoke('fans','ack',action_id=prepared['action_id'])
    check('B6join attested descriptor and corpus origin are identical',
        ack['fields']['object']==descriptor and ack['result']['state']=='active')
    sql('CREATE TABLE wp_b6_joined_probe (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB')
    before=fixture['inbox']('cache-read')
    rejected=invoke('fans','compose',action_id=prepared['action_id'],reject=True)
    check('B6join rejected application rolls back the real shared transaction',
        rejected==dict(error='pf_fixture_apply_refused') and sql('SELECT COUNT(*) FROM wp_b6_joined_probe')=='0'
        and fixture['inbox']('cache-read')==before)
    accepted=invoke('fans','compose',action_id=prepared['action_id'])
    check('B6join authenticated barrier and exact cache commit in one owner transaction',
        accepted==dict(state='reconciled',generation=before['generation'],transaction='1')
        and sql('SELECT COUNT(*) FROM wp_b6_joined_probe')=='1')
    sql('DELETE FROM wp_b6_joined_probe')
    revoked=copy.deepcopy(policies['fans']);revoked['keys']['recipe-hub-k1']['state']='revoked';fixture['policy']('fans',revoked)
    check('B6join revoked current Hub trust suppresses the entire composed delivery',
        'error' in invoke('fans','compose',action_id=prepared['action_id']) and sql('SELECT COUNT(*) FROM wp_b6_joined_probe')=='0')
    fixture['policy']('fans',policies['fans'])
    close=dict(fields,operation='close',action_id=str(uuid.uuid4()),object={
        name:ack['result'][name] for name in ('barrier_key','version','content_sha256')}|dict(reason='origin_closed'))
    closing=invoke('fans','prepare',fields=close)
    check('B6join local closing suppresses composed delivery before network acknowledgement',
        'error' in invoke('fans','compose',action_id=prepared['action_id']) and sql('SELECT COUNT(*) FROM wp_b6_joined_probe')=='0')
    check('B6join the same signed close reaches its primary attested state',
        invoke('fans','advance',action_id=closing['action_id'])['state']=='acknowledged')
    check('B6join closed origin cannot deliver an old cache or old register ACK',
        'error' in invoke('fans','compose',action_id=prepared['action_id']) and sql('SELECT COUNT(*) FROM wp_b6_joined_probe')=='0')
    check('B6join actual composition preserves both ledgers without a Fans ledger',
        hub_sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id')==ledger
        and hub_sql('SELECT * FROM wp_token_engine_ledger ORDER BY id')==historical
        and sql("SHOW TABLES LIKE 'wp_fans%ledger%'")=='')
