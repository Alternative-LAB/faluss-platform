"""Ordinary WordPress cookies/REST/cron, synthetic Identity links, no target sites or PF source."""
import concurrent.futures, json, pathlib, uuid, urllib.request, urllib.error


def run_checks(api, evaluate, check, flags, session, root, base):
    flags(FALUSS_PLATFORM_FANS_FOLLOWERS=True)
    check('automatic explicit follower schema in disposable WordPress',evaluate('echo \\Faluss\\Platform\\Fans\\Followers\\FollowersSchema::installOrVerify()?"yes":"no";')=='yes')
    creator=session['profiles']['other']
    seed=r'''
    if(DB_NAME!=='admission_recipe'){throw new RuntimeException('Disposable fixture only');}
    global $wpdb;$result=[];
    foreach(['auto-a','auto-b','auto-c'] as $label){
        $id=wp_insert_user(['user_login'=>'recipe_'.$label.'_'.wp_generate_password(8,false,false),'user_pass'=>wp_generate_password(40),'user_email'=>$label.'@example.invalid','role'=>'subscriber']);
        if(is_wp_error($id)){throw new RuntimeException('Synthetic member unavailable');}
        if($wpdb->insert(\Faluss\Platform\Fans\Sso\FansSsoSchema::tables()['links'],['wp_user_id'=>$id,'faluss_id'=>wp_generate_uuid4(),'created_at'=>gmdate('Y-m-d H:i:s'),'last_proved_at'=>gmdate('Y-m-d H:i:s')])!==1){throw new RuntimeException('Synthetic binding unavailable');}
        wp_set_current_user($id);
        $cookies=[LOGGED_IN_COOKIE=>wp_generate_auth_cookie($id,time()+3600,'logged_in'),AUTH_COOKIE=>wp_generate_auth_cookie($id,time()+3600,'auth')];
        $_COOKIE[LOGGED_IN_COOKIE]=$cookies[LOGGED_IN_COOKIE];
        $result[$label]=['id'=>$id,'cookies'=>$cookies,'nonce'=>wp_create_nonce('wp_rest')];
    }
    echo wp_json_encode($result);
    '''
    added=json.loads(evaluate(seed));session['sessions'].update(added)
    private=root/'automatic-sessions.json'
    private.write_text(json.dumps({'base':base,'creator':creator,'sessions':session['sessions']}));private.chmod(0o600)
    config=api('messages/automatic','other')[1]
    check('automatic disabled by default and no attachments',not config['welcome']['enabled'] and not config['thanks']['enabled'] and not config['welcome_attachment_available'] and not config['thanks_attachment_available'])
    for who in [None,'admin','unlinked','auto-a']:
        check('automatic configuration owner-only '+str(who),api('messages/automatic',who)[0] in (401,403))
    payload={'body':'Bienvenue A, texte synthétique de recette.','enabled':True,'revision':0}
    check('automatic configuration requires actual nonce',api('messages/automatic/welcome','other',payload,nonce=False)[0] in (401,403))
    check('welcome actual configuration saved',api('messages/automatic/welcome','other',payload)[0]==200)
    check('automatic stale edit conflicts',api('messages/automatic/welcome','other',payload)[0]==409)
    check('server preview never saves text',api('messages/automatic/welcome/preview','other',{'body':'Aperçu uniquement.'})[0]==200 and api('messages/automatic','other')[1]['welcome']['revision']==1)
    for kind in ['welcome','thanks']:
        check('automatic image field refused '+kind,api('messages/automatic/'+kind,'other',{**payload,'revision':1 if kind=='welcome' else 0,'image_id':str(uuid.uuid4())})[0]==400)
    follow='creators/'+creator+'/follow'
    for who in [None,'unlinked','admin']:
        check('private follow state requires linked member '+str(who),api(follow,who)[0] in (401,403))
    check('follow state cannot select recipient',api(follow+'?member=other','auto-a')[0]==400)
    headers={'Cookie':'; '.join(k+'='+v for k,v in session['sessions']['auto-a']['cookies'].items()),
             'X-WP-Nonce':session['sessions']['auto-a']['nonce'],'Content-Type':'application/json'}
    request=urllib.request.Request(base+'/wp-json/faluss-fans/v1/'+follow,method='GET',data=b'{}',headers=headers)
    try: response=urllib.request.urlopen(request,timeout=20)
    except urllib.error.HTTPError as error: response=error
    check('private follow GET rejects even an empty JSON body',response.status==400);response.close()
    check('economic source remains closed server-side',api('messages/automatic/thanks','other',{'body':'Merci fictif.','enabled':True,'revision':0})[0]==503)
    check('initial follow state private no-store',api(follow,'auto-a')[1]['following'] is False and 'no-store' in api(follow,'auto-a')[2].get('Cache-Control',''))
    check('real follow needs nonce',api(follow,'auto-a',{},nonce=False)[0] in (401,403))
    check('real follow queues, not synchronously sends',api(follow,'auto-a',{})[0]==200 and api('messages','auto-a')[1]['items']==[])
    check('repeat follow stays idempotent',api(follow,'auto-a',{})[0]==200)
    check('future welcome text updated independently',api('messages/automatic/welcome','other',{**payload,'body':'Bienvenue B, version future.','revision':1})[0]==200 and api('messages/automatic','other')[1]['thanks']['revision']==0)
    dispatch=lambda:evaluate(r'do_action(\Faluss\Platform\Fans\Messaging\MessageAutomaticScheduler::HOOK);$r=json_decode(get_option(\Faluss\Platform\Fans\Messaging\MessageAutomaticScheduler::STATUS,""),true);if(!is_array($r)||$r["error"]!==""){throw new RuntimeException("Automatic hook unavailable");}echo wp_json_encode($r);')
    check('actual asynchronous WordPress worker sends one',json.loads(dispatch())['processed']==1)
    check('job recovery has no second message',json.loads(dispatch())['processed']==0)
    thread=api('messages','auto-a')[1]['items'][0]['thread_id'];route='messages/'+thread
    received=api(route,'auto-a')[1]
    check('frozen text in ordinary inbox',received['state']=='automatic' and received['messages'][0]['body']==payload['body'] and not received['can_send'] and received['can_start'])
    check('automatic message not general creator contact',api(route+'/send','other',{'body':'Interdit avant une demande.','key':str(uuid.uuid4())})[0]==403)
    check('automatic message not general fan contact',api(route+'/send','auto-a',{'body':'Passage interdit.','key':str(uuid.uuid4())})[0]==403)
    check('fan can explicitly start real request on same conversation',api('messages/requests','auto-a',{'creator_id':creator,'body':'Demande synthétique après bienvenue.','key':str(uuid.uuid4())})[1]['state']=='pending')
    pending=api(route,'other')[1]
    check('creator can explicitly accept after automatic message',api(route+'/decision','other',{'revision':pending['revision'],'action':'accept'})[0]==200)
    check('normal bilateral send after actual acceptance',api(route+'/send','auto-a',{'body':'Échange synthétique accepté.','key':str(uuid.uuid4())})[0]==200)
    with concurrent.futures.ThreadPoolExecutor(2) as pool:
        results=list(pool.map(lambda _:api(follow,'auto-b',{}),range(2)))
    check('two concurrent follows one durable welcome',all(r[0]==200 for r in results) and json.loads(dispatch())['processed']==1 and len(api('messages','auto-b')[1]['items'])==1)
    check('third event waits for disable decision',api(follow,'auto-c',{})[0]==200)
    check('creator disables welcome and clears waiting work',api('messages/automatic/welcome','other',{**payload,'enabled':False,'revision':2})[0]==200 and json.loads(dispatch())['processed']==0 and api('messages','auto-c')[1]['items']==[])
    check('reenable never resurrects old event',api('messages/automatic/welcome','other',{**payload,'revision':3})[0]==200 and json.loads(dispatch())['processed']==0)
    capture={'base':base,'creator':creator,'sessions':session['sessions'],'automatic_thread':thread,
             'automatic_only_thread':api('messages','auto-b')[1]['items'][0]['thread_id']}
    (root/'automatic-capture.json').write_text(json.dumps(capture));(root/'automatic-capture.json').chmod(0o600)
    # These are disposable preferences only; leave no active sending preference after the recipe.
    check('welcome test preference closed explicitly',api('messages/automatic/welcome','other',{**payload,'enabled':False,'revision':4})[0]==200)
