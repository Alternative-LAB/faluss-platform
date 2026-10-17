"""Actual WordPress/private REST admission transitions; synthetic linked fixture members."""
import concurrent.futures, uuid


def run_checks(api, evaluate, check, creator):
    for who in [None, 'unlinked', 'admin', 'member']:
        # The member's own profile is still pending in this fixture.
        check('contact requires an active linked creator '+str(who), api('messages/contact',who)[0] in (401,403))
    check('contact nonce required', api('messages/contact','other',{'mode':'open','revision':0},nonce=False)[0] in (401,403))
    check('contact cannot select another creator', api('messages/contact','other',{'mode':'open','revision':0,'creator_id':creator})[0]==400)
    check('contact GET rejects override', api('messages/contact?mode=request','other')[0]==400)
    check('contact defaults open on prepared schema',api('messages/contact','other')[1]=={'mode':'open','revision':0})
    check('contact reads do not insert preferences',evaluate('global $wpdb;echo $wpdb->get_var("SELECT COUNT(*) FROM wp_faluss_fans_dm_contact");')=='0')
    payload={'creator_id':creator,'body':'Premier message de recette en mode ouvert.','key':str(uuid.uuid4())}
    code,opened,_=api('messages/requests','member',payload)
    check('WordPress default admission really opens bilateral conversation',code==200 and opened['state']=='open' and opened['direct_opening_available'])
    route='messages/'+opened['thread_id']
    check('direct conversation reply uses existing service',api(route+'/send','other',{'body':'Réponse de recette.','key':str(uuid.uuid4())})[0]==200)
    with concurrent.futures.ThreadPoolExecutor(2) as pool:
        results=list(pool.map(lambda _:api('messages/contact','other',{'mode':'request','revision':0}),range(2)))
    check('WordPress concurrent contact edit single winner',sorted(r[0] for r in results)==[200,409])
    check('restriction preserves accepted conversation',api(route+'/send','member',{'body':'Conversation conservée.','key':str(uuid.uuid4())})[0]==200)
    code,pending,_=api('messages/requests','waiting',{**payload,'key':str(uuid.uuid4())})
    check('restricted new conversation stays pending',code==200 and pending['state']=='pending')
    check('creator can restore open mode',api('messages/contact','other',{'mode':'open','revision':1})[0]==200)
    pending_route='messages/'+pending['thread_id']
    check('opening mode never auto accepts existing pending',api(pending_route+'/send','other',{'body':'Interdit avant acceptation.','key':str(uuid.uuid4())})[0]==403)
    check('old pending remains rejectable',api(pending_route+'/decision','other',{'revision':1,'action':'refuse'})[0]==200)
    check('open mode does not override refusal',api('messages/requests','waiting',{**payload,'key':str(uuid.uuid4())})[0]==409)
    check('creator block remains available',api(pending_route+'/decision','other',{'revision':2,'action':'block'})[0]==200)
    check('open mode does not override block',api('messages/requests','waiting',{**payload,'key':str(uuid.uuid4())})[0]==403)
    block=api('messages/blocks','other')[1]['items'][0]['block_id']
    check('fixture explicitly unblocks without accepting',api('messages/blocks/'+block+'/unblock','other',{'confirm':True})[0]==200)
    check('unblock does not reopen refusal',api(pending_route+'/send','waiting',{'body':'Toujours refusé.','key':str(uuid.uuid4())})[0]==403)
    check('contact reads private no-store','no-store' in api('messages/contact','other')[2].get('Cache-Control',''))
    # Expire only the two actual fixture conversations; ordinary purge owns erasure.
    evaluate("global $wpdb;$wpdb->query(\"UPDATE wp_faluss_fans_dm_threads SET last_sent_at=UTC_TIMESTAMP()-INTERVAL 13 MONTH WHERE thread_id IN ('"+opened['thread_id']+"','"+pending['thread_id']+"')\");\\Faluss\\Platform\\Fans\\Messaging\\MessageStore::purge();")
    check('ordinary expiry owns direct and pending erasure',api(route,'member')[0]==404 and api(pending_route,'waiting')[0]==404)
    check('pending baseline explicitly restored',api('messages/contact','other',{'mode':'request','revision':2})[0]==200)
