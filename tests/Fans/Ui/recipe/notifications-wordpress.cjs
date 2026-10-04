// Disposable WordPress/MariaDB only. Real cookie sessions and domain writes; never log private payloads.
const {chromium,request}=require('playwright');
const assert=require('node:assert/strict'),fs=require('node:fs'),{execFileSync}=require('node:child_process');
(async()=>{
 const {BASE:base,ROOT:root,OUT:out}=process.env;
 assert.equal(new URL(base).hostname,'127.0.0.1');assert.match(root,/^\/var\/tmp\/fans-admission-wp-[a-z0-9_]+$/);
 const wsl=args=>execFileSync('wsl.exe',['-u','root','--',...args]);
 const fixture=JSON.parse(wsl(['cat',root+'/session.json']));
 const cli=code=>{execFileSync('wsl.exe',['-u','root','--','tee',root+'/notification-check.php'],{input:'<?php\n'+code,stdio:['pipe','ignore','pipe']});return wsl(['php','/var/tmp/faluss-v3-wp/wp-cli.phar','--allow-root','--path='+root+'/wordpress','eval-file',root+'/notification-check.php','--use-include']).toString().trim();};
 cli("if(DB_NAME!=='admission_recipe')throw new RuntimeException('fixture only');global $wpdb;foreach(['messages','threads','blocks','reports','report_events','legal_holds'] as $t){$wpdb->query('DELETE FROM wp_faluss_fans_dm_'.$t);}$wpdb->query('DELETE FROM wp_faluss_fans_notifications');");
 // Redirect fixture home URLs to its loopback origin; outbound access is blocked in every browser context.
 execFileSync('wsl.exe',['-u','root','--','tee',root+'/wordpress/wp-content/mu-plugins/local-redirect.php'],{input:"<?php add_filter('wp_redirect',static fn($url)=>str_replace('https://fans.example.test',"+JSON.stringify(base)+",$url));",stdio:['pipe','ignore','pipe']});
 const browser=await chromium.launch({channel:'chrome',headless:true}),clients={},contexts={},pages={},checks=[],errors=[];
 fs.mkdirSync(out,{recursive:true});
 const check=(name,value)=>{assert.ok(value,name);checks.push(name);};
 const api=async(who,path,data,expected=200)=>{const res=await clients[who].fetch('/wp-json/faluss-fans/v1/'+path,{method:data?'POST':'GET',...(data?{data}:{})});assert.equal(res.status(),expected,path);return res.json();};
 const role=who=>who==='other'?'creator':'fan';
 const center=who=>'/app/'+role(who)+'/notifications';
 const go=async(who,query='')=>{const p=pages[who],read=p.waitForResponse(r=>r.url().includes('/notification-view'));assert.equal((await p.goto(base+center(who)+query)).status(),200);await read;await p.evaluate(()=>document.fonts.ready);return p;};
 const local=async p=>p.locator('a[href],form[action]').evaluateAll((els,base)=>els.forEach(el=>{const attr=el.tagName==='FORM'?'action':'href',u=new URL(el.getAttribute(attr),location.href);if(u.origin==='https://fans.example.test')el.setAttribute(attr,base+u.pathname+u.search+u.hash);}),base);
 const rows=who=>JSON.parse(cli('wp_set_current_user('+fixture.sessions[who].id+');echo wp_json_encode(\\Faluss\\Platform\\Fans\\Notifications\\NotificationService::listing());'));
 const mark=async(who,id,unread)=>{const res=await clients[who].get(center(who));const nonce=(await res.text()).match(/name="fans_notifications_nonce" value="([^"]+)"/)[1];return clients[who].post(center(who),{form:{fans_notifications_nonce:nonce,notification:String(id),unread:String(unread)},maxRedirects:0});};
 const count=who=>rows(who).unread;
 try{
  for(const who of ['fan','other','member','admin','unlinked']){
   const s=fixture.sessions[who];clients[who]=await request.newContext({baseURL:base,extraHTTPHeaders:{Cookie:Object.entries(s.cookies).map(([k,v])=>`${k}=${v}`).join('; '),'X-WP-Nonce':s.nonce}});
   contexts[who]=await browser.newContext({viewport:{width:1440,height:1000},hasTouch:true});await contexts[who].route('**/*',r=>new URL(r.request().url()).origin===base?r.continue():r.abort());await contexts[who].addCookies(Object.entries(s.cookies).map(([name,value])=>({name,value,url:base})));pages[who]=await contexts[who].newPage();pages[who].on('pageerror',e=>errors.push(e.message));
  }
  const fresh=await request.newContext({baseURL:base});check('guest read denied',(await fresh.get('/wp-json/faluss-fans/v1/notification-view?role=fan&part=count')).status()===403);await fresh.dispose();
  for(const who of ['admin','unlinked'])await api(who,'notification-view?role=fan&part=count',null,403);
  await api('fan','notification-view?role=fan&part=count&recipient=1',null,400);
  await api('fan','notification-view?role=fan&part=center&cursor=-1',null,400);
  await api('fan','notification-view?role=creator&part=count',null,404);
  check('scoped reads reject recipient malformed cursor and creator impersonation',true);
  let res=await clients.fan.get('/wp-json/faluss-fans/v1/notification-view?role=fan&part=center');check('REST private no-store',/private/.test(res.headers()['cache-control'])&&/no-store/.test(res.headers()['cache-control'])&&res.headers()['cdn-cache-control']==='no-store');
  const p=await go('other'),q=await go('fan');check('empty center remains usable',await p.locator('.fu-notification-empty').count()===1);
  let navigations=0;p.on('framenavigated',f=>{if(f===p.mainFrame())navigations++;});
  const thread=await api('fan','messages/requests',{creator_id:fixture.profiles.other,body:'Demande synthétique de recette.',key:crypto.randomUUID()});
  await p.locator('.fu-notification').first().waitFor({timeout:18000});check('other SSO session request appears without reload',navigations===0&&Number(await p.locator('[data-unread-count]').innerText())===1);
  const notification=rows('other').items[0].id;
  await api('other','messages/'+thread.thread_id+'/decision',{revision:1,action:'accept'});
  await q.locator('.fu-notification').first().waitFor({timeout:18000});check('acceptance updates Fan filter count and list',Number(await q.locator('[data-unread-count]').innerText())===1);
  // Generate sufficient real domain events for pagination in both roles.
  for(let i=0;i<22;i++){
   await api('other','messages/'+thread.thread_id+'/send',{body:'Texte synthétique de recette '+i,key:crypto.randomUUID()});
   await api('fan','messages/'+thread.thread_id+'/send',{body:'Réponse synthétique de recette '+i,key:crypto.randomUUID()});
  }
  for(const who of ['fan','other']){const data=rows(who);for(const item of data.items.filter((_,i)=>i%3===0))assert.equal((await mark(who,item.id,0)).status(),200);}
  for(const who of ['fan','other']){
   const page=await go(who);const before=count(who);await page.reload();check(who+' render and scrolling never mark read',count(who)===before);
   for(const width of [1440,834,390,320,430]){
    await page.setViewportSize({width,height:width>700?1000:844});await page.evaluate(()=>document.fonts.ready);await page.evaluate(()=>scrollTo(0,0));
    check(who+' '+width+' no overflow',await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
    const metrics=await page.locator('.fu-notification-row').first().evaluate(el=>({height:el.getBoundingClientRect().height,border:getComputedStyle(el.closest('.fu-notification')).borderLeftWidth}));
    check(who+' '+width+' compact tactile row',metrics.height>=48&&metrics.height<=60&&metrics.border==='0px');
    check(who+' '+width+' read unread states',await page.locator('[data-unread=true]').count()>0&&await page.locator('[data-unread=false]').count()>0);
    check(who+' '+width+' one interactive target per row',await page.locator('.fu-notification').evaluateAll(els=>els.every(el=>el.querySelectorAll('a,button,input:not([type=hidden])').length===1)));
    check(who+' '+width+' no top bar, visual header or footer',await page.locator('.fu-notifications-bar,.fu-heading,.fu-footer').count()===0);
    check(who+' '+width+' accessible title without layout space',await page.getByRole('heading',{name:'Notifications',exact:true}).evaluate(el=>el.getBoundingClientRect().height===1&&getComputedStyle(el).clipPath==='inset(50%)'));
    check(who+' '+width+' filters start at top and list fills width',await page.evaluate(()=>{const main=document.querySelector('.fu-main').getBoundingClientRect(),filters=document.querySelector('nav[aria-label="Filtrer les notifications"]').getBoundingClientRect(),list=document.querySelector('.fu-notification-list').getBoundingClientRect();return filters.top<=16&&filters.left-main.left<=16&&main.right-list.right<=16;}));
    check(who+' '+width+' no lower restart control or nested scrolling',await page.getByText('Revenir au début',{exact:true}).count()===0&&await page.locator('.fu-notification-list').evaluate(el=>getComputedStyle(el).overflowY==='visible'));
    if([1440,834,390].includes(width))await page.screenshot({path:out+'/'+role(who)+'-loaded-'+width+'.png'});
   }
  }
  await p.setViewportSize({width:1440,height:1000});await go('other');
  const id=await p.locator('.fu-notification[data-unread=true]').first().getAttribute('data-id'),target=p.locator('[data-id="'+id+'"]');
  await target.locator('button').focus();await p.evaluate(()=>scrollTo(0,430));const oldTop=await target.evaluate(el=>el.getBoundingClientRect().top);
  await mark('other',id,0);await p.waitForFunction(id=>document.querySelector('[data-id="'+id+'"]').dataset.unread==='false',id,{timeout:18000});
  check('read change preserves focused row',await target.locator('button').evaluate(el=>el===document.activeElement));check('read change preserves viewport',Math.abs((await target.evaluate(el=>el.getBoundingClientRect().top))-oldTop)<3);
  await mark('other',id,1);await p.waitForFunction(id=>document.querySelector('[data-id="'+id+'"]').dataset.unread==='true',id,{timeout:18000});check('unread update reflected from second request session',true);
  const next=rows('other').next_cursor;await go('other','?cursor='+next);const oldIds=await p.locator('.fu-notification').evaluateAll(els=>els.map(el=>el.dataset.id));
  await api('fan','messages/'+thread.thread_id+'/send',{body:'Arrivée pendant la page suivante.',key:crypto.randomUUID()});await p.waitForTimeout(13000);
  check('pagination stays on older page with no duplicate or jump',new URL(p.url()).searchParams.get('cursor')===next&&JSON.stringify(oldIds)===JSON.stringify(await p.locator('.fu-notification').evaluateAll(els=>els.map(el=>el.dataset.id))));
  await local(p);await Promise.all([p.waitForURL(base+center('other')),p.getByRole('link',{name:'Toutes',exact:true}).click()]);check('Toutes returns from older page to first page',!new URL(p.url()).searchParams.has('cursor'));
  await go('other','?filter=unread');check('unread filter only unread rows',await p.locator('[data-unread=false]').count()===0);
  const first=p.locator('.fu-notification').first(),firstId=await first.getAttribute('data-id');await mark('other',firstId,0);await p.locator('[data-id="'+firstId+'"]').waitFor({state:'detached',timeout:18000});check('filter preserved while externally read row leaves',new URL(p.url()).searchParams.get('filter')==='unread');
  let polls=0;p.on('request',r=>{if(r.url().includes('/notification-view'))polls++;});await p.evaluate(()=>{Object.defineProperty(document,'hidden',{configurable:true,get:()=>true});document.dispatchEvent(new Event('visibilitychange'));});let n=polls;await p.waitForTimeout(14000);check('hidden suspends notification reads',polls===n);
  await p.evaluate(()=>{Object.defineProperty(document,'hidden',{configurable:true,get:()=>false});document.dispatchEvent(new Event('visibilitychange'));});await p.waitForResponse(r=>r.url().includes('/notification-view'));check('visible triggers immediate read',polls>n);
  await contexts.other.setOffline(true);n=polls;await p.waitForTimeout(14000);check('offline suspends reads',polls===n);await contexts.other.setOffline(false);await p.waitForResponse(r=>r.url().includes('/notification-view'));check('online triggers immediate read',polls>n);
  // Protected native opening: no mutation via GET, forged POST, foreign recipient or inaccessible destination.
  await go('other');const row=p.locator('.fu-notification[data-unread=true]').first(),openId=await row.getAttribute('data-id'),nonce=await row.locator('[name=fans_notifications_nonce]').inputValue();
  let before=count('other');await clients.other.get(center('other')+'?action=open&notification='+openId);check('GET open does not mutate',count('other')===before);
  res=await clients.other.post(center('other'),{form:{action:'open',notification:openId,fans_notifications_nonce:'wrong'},maxRedirects:0});check('forged open denied without marking',res.status()===403&&count('other')===before);
  res=await clients.other.post(center('other'),{form:{action:'open',notification:openId,fans_notifications_nonce:nonce,destination:'https://invalid.test'},maxRedirects:0});check('client supplied redirect refused',res.status()===400&&count('other')===before);
  const foreignNonce=(await (await clients.fan.get(center('fan'))).text()).match(/name="fans_notifications_nonce" value="([^"]+)"/)[1];res=await clients.fan.post(center('fan'),{form:{action:'open',notification:openId,fans_notifications_nonce:foreignNonce},maxRedirects:0});check('foreign notification cannot open',res.status()===404);
  await local(p);await row.locator('button').focus();await Promise.all([p.waitForURL('**/messages?thread=*'),row.locator('button').press('Enter')]);check('keyboard POST marks read and redirects authorized target',count('other')===before-1);check('other screen retains its bell and logout',await p.locator('.fu-notifications-bar .fu-bell').count()===1&&await p.locator('[data-fans-logout]').count()===1);
  // No-JavaScript native fallback.
  const nc=await browser.newContext({javaScriptEnabled:false});await nc.route('**/*',r=>new URL(r.request().url()).origin===base?r.continue():r.abort());await nc.addCookies(Object.entries(fixture.sessions.fan.cookies).map(([name,value])=>({name,value,url:base})));const np=await nc.newPage();await np.goto(base+center('fan'));await local(np);await Promise.all([np.waitForURL('**/notifications?cursor=*'),np.getByRole('link',{name:'Page suivante →'}).click()]);check('without JavaScript next-page server cursor works',new URL(np.url()).searchParams.has('cursor'));await local(np);await Promise.all([np.waitForURL(base+center('fan')),np.getByRole('link',{name:'Toutes',exact:true}).click()]);await local(np);before=count('fan');await Promise.all([np.waitForURL('**/messages?thread=*'),np.locator('.fu-notification[data-unread=true] button').first().click()]);check('without JavaScript native POST and redirect work',count('fan')===before-1);await nc.close();
  await go('other');before=count('other');const stale=p.locator('.fu-notification[data-unread=true]').first(),staleId=await stale.getAttribute('data-id'),staleNonce=await stale.locator('[name=fans_notifications_nonce]').inputValue();
  // Actual retention makes the object unavailable; keep notification metadata solely to exercise a stale row.
  cli("global $wpdb;$wpdb->query(\"UPDATE wp_faluss_fans_dm_threads SET last_sent_at='2020-01-01 00:00:00'\");");
  res=await clients.other.post(center('other'),{form:{action:'open',notification:staleId,fans_notifications_nonce:staleNonce},maxRedirects:0});check('stale inaccessible POST returns conflict without marking',res.status()===409&&count('other')===before);
  await p.waitForFunction(()=>document.querySelectorAll('.fu-notification button').length===0,null,{timeout:18000});check('revoked targets lose interactivity without reload',await p.locator('.fu-notification-unavailable').count()>0);
  const privateView=await api('other','notification-view?role=creator&part=center');check('inaccessible projection contains no object UUID or broken target',!privateView.html.includes(thread.thread_id)&&!privateView.html.includes('action="'));
  await p.screenshot({path:out+'/creator-inaccessible-1440.png'});
  cli('WP_Session_Tokens::get_instance('+fixture.sessions.other.id+')->destroy_all();');await p.waitForFunction(()=>!document.querySelector('.fu-notification-row'),null,{timeout:18000});check('expired session clears private list',await p.locator('.fu-notification-row').count()===0);
  check('no browser runtime errors',errors.length===0);fs.writeFileSync(out+'/browser.json',JSON.stringify({browser:browser.version(),passed:checks.length,checks},null,2));console.log(JSON.stringify({passed:checks.length,browser:browser.version()}));
 }finally{for(const c of Object.values(clients))await c.dispose();await browser.close();}
})().catch(e=>{console.error(e.message);process.exitCode=1;});
