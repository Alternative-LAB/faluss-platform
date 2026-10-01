// Only session-wordpress.py: Chrome resolves this reserved test hostname to loopback.
const {chromium}=require('playwright'),fs=require('node:fs'),assert=require('node:assert/strict'),os=require('node:os'),path=require('node:path');
(async()=>{
 const seed=JSON.parse(fs.readFileSync(process.env.SESSION)),out=process.env.OUT,base='https://fans.local.test';
 const profile=fs.mkdtempSync(path.join(os.tmpdir(),'fans-session-chrome-')),checks=[];
 const options={channel:'chrome',headless:true,ignoreHTTPSErrors:true,viewport:{width:1440,height:900},args:['--no-proxy-server','--host-resolver-rules=MAP fans.local.test 127.0.0.1, MAP * ~NOTFOUND']};
 let c;
 async function launch(){c=await chromium.launchPersistentContext(profile,options);await c.route('**/*',r=>new URL(r.request().url()).origin===base?r.continue():r.abort());}
 try{
  await launch();await c.addCookies(seed.cookies);let p=await c.newPage();await p.goto(base+'/app/fan/accueil');await p.locator('[data-fans-logout]').waitFor();
  const expires=Number(await p.locator('body').getAttribute('data-session-expires'));const before=await c.cookies(base);await p.screenshot({animations:'disabled',path:out+'/session-fan-1440.png'});
  await c.close();await launch();p=await c.newPage();await p.goto(base+'/app/fan/accueil');await p.locator('[data-fans-logout]').waitFor();assert.equal(Number(await p.locator('body').getAttribute('data-session-expires')),expires);
  const after=await c.cookies(base);assert.equal(after.find(x=>x.name.startsWith('wordpress_logged_in')).expires,before.find(x=>x.name.startsWith('wordpress_logged_in')).expires);checks.push('Chrome closed/reopened: persistent real SSO cookie works before unchanged eight-hour deadline');
  const other=await c.newPage();await other.goto(base+'/app/fan/espace');await other.locator('[data-fans-logout]').waitFor();
  await p.bringToFront();await p.setViewportSize({width:390,height:844});await p.screenshot({animations:'disabled',path:out+'/session-fan-390.png'});
  const replies=[];p.on('response',r=>{if(new URL(r.url()).pathname.includes('admin-post'))replies.push(r.status());});
  await p.getByRole('button',{name:'Déconnexion de Fans'}).click();
  try{await p.waitForURL('**/explorer?faluss_fans_session=closed');await other.waitForURL('**/explorer?faluss_fans_session=closed');}catch(e){console.log({path:new URL(p.url()).pathname,otherPath:new URL(other.url()).pathname,replies,alert:await p.locator('[data-fans-logout] [role=alert]').allTextContents()});throw e;}
  assert.equal(await p.locator('[data-fans-logout]').count(),0);assert.equal(await other.locator('[data-fans-logout]').count(),0);await p.locator('summary').filter({hasText:'Utiliser un autre compte'}).click();await p.screenshot({animations:'disabled',path:out+'/logout-390.png'});
  await p.goBack();assert.equal(await p.locator('[data-fans-logout]').count(),0);await other.reload();assert.equal(await other.locator('[data-fans-logout]').count(),0);checks.push('Nonce logout clears current/other tab; back/reload never restore authenticated UI');
  await c.close();
  const b=await chromium.launch({channel:'chrome',headless:true,args:options.args});
  try{const n=await b.newContext({ignoreHTTPSErrors:true,javaScriptEnabled:false});await n.route('**/*',r=>new URL(r.request().url()).origin===base?r.continue():r.abort());await n.addCookies(seed.native);const np=await n.newPage();await np.goto(base+'/app/fan/accueil');await np.getByRole('button',{name:'Déconnexion de Fans'}).click();await np.waitForURL('**/explorer?faluss_fans_session=closed');assert.equal(await np.locator('[data-fans-logout]').count(),0);checks.push('No-JavaScript native POST logout works');}finally{await b.close();}
  fs.writeFileSync(out+'/session-browser.json',JSON.stringify({checks,limits:['Isolated local HTTPS WordPress only','No control over screenshots previously taken or a malicious client cache']},null,2));console.log('PASS '+checks.length+' real session browser scenarios');
 }finally{try{await c?.close();}catch(e){}fs.rmSync(profile,{recursive:true,force:true});}
})().catch(e=>{console.error(e.stack);process.exitCode=1;});
