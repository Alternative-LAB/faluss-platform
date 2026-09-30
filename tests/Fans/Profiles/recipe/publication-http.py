"""Owner image association via native forms, existing real REST/services and SQL."""
import runpy, pathlib, urllib.request, urllib.error, urllib.parse, json, uuid
fixture = runpy.run_path(str(pathlib.Path(__file__).with_name('images-http.py')))
call, check, sql, png, BASE = [fixture[key] for key in ['call','check','sql','png','BASE']]

def native(user, fields, publication):
    request=urllib.request.Request(BASE+'/faluss-fans/creator/creer?publication='+publication,
        urllib.parse.urlencode(fields).encode(), {'Cookie':'fixture_user='+str(user)})
    try: response=urllib.request.urlopen(request,timeout=20)
    except urllib.error.HTTPError as error: response=error
    return response.status, response.read()

request=urllib.request.Request(BASE+'/wp-json/faluss-fans/v1/text-publications',
    json.dumps({'text':'Texte synthétique pour association réelle.','category':'hosted_allowed_content'}).encode(),
    {'Cookie':'fixture_user=17','X-WP-Nonce':'fixture-wp_rest','Idempotency-Key':str(uuid.uuid4()),'Content-Type':'application/json'})
response=urllib.request.urlopen(request); publication=json.loads(response.read())['publication_id']
image=call(17,'images',upload=png(90))[1]
form={'author_action':'image','fans_author_nonce':'fixture-fans_author','publication_id':publication,'revision':'1',
      'image_choice':image['image_id']+':1','confirm_image_review':'yes'}
for user in [0,18,19,1,20]: check('native association permission '+str(user),native(user,form,publication)[0] in [403,404])
check('association nonce required',native(17,dict(form,fans_author_nonce='wrong'),publication)[0]==403)
check('association explicit confirmation required',native(17,dict(form,confirm_image_review=''),publication)[0]==400)
check('missing choice never means detach',native(17,dict(form,image_choice=''),publication)[0]==400)
check('pending image denied by server',native(17,form,publication)[0]==409)
call(1,'images/'+image['image_id']+'/moderate',{'revision':1,'decision':'approve','reason':'allowed_image'})
check('stale image revision denied',native(17,form,publication)[0]==409)
form['image_choice']=image['image_id']+':2'
check('approved owner association native',native(17,form,publication)[0]==200)
private=call(17,'text-publications/'+publication+'/private')[1]
check('real association increments text revision and stays pending',int(private['revision'])==2 and private['state']=='pending' and private['image_id']==image['image_id'])
check('pending text not public',call(0,'text-publications/'+publication)[0]==404)
check('pending image derivative not public',call(0,'text-publications/'+publication+'/image/2')[0]==404)
check('replay conflicts instead of second mutation',native(17,form,publication)[0]==409)
call(1,'text-publications/'+publication+'/moderate',{'revision':2,'decision':'approve','reason':'allowed_text'})
check('approved derivative actual JPEG',call(0,'text-publications/'+publication+'/image/3')[1].startswith(b'\xff\xd8'))
form.update(revision='3',image_choice='none')
check('explicit detach native',native(17,form,publication)[0]==200)
private=call(17,'text-publications/'+publication+'/private')[1]
check('detach masks approved text and removes reference',private['state']=='pending' and private['image_id'] is None and int(private['revision'])==4)
check('old public derivative revoked',call(0,'text-publications/'+publication+'/image/3')[0]==404)
form.update(revision='4',image_choice=image['image_id']+':2')
sql("CREATE TRIGGER fail_text_image BEFORE INSERT ON test_faluss_fans_text_images FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='isolated image reference failure'")
try: check('SQL reference failure leaves text revision unchanged',native(17,form,publication)[0]==503 and int(call(17,'text-publications/'+publication+'/private')[1]['revision'])==4)
finally: sql('DROP TRIGGER fail_text_image')
print('PASS native image association, permissions, revision, moderation and rollback',flush=True)
