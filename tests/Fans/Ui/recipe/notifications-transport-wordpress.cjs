// Transport failures are injected only in the local browser; successful reads use real WordPress.
const {chromium}=require('playwright'),assert=require('node:assert/strict'),fs=require('node:fs'),{execFileSync}=require('node:child_process');
(async()=>{
 const {BASE:base,ROOT:root,OUT:out}=process.env;assert.equal(new URL(base).hostname,'127.0.0.1');assert.match(root,/^\/var\/tmp\/fans-admission-wp-[a-z0-9_]+$/);
 const f=JSON.parse(execFileSync('wsl.exe',['-u','root','--','cat',root+'/session.json'])),b=await chromium.launch({channel:'chrome'}),checks=[];
 try{
  const c=await b.newContext();await c.route('**/*',r=>new URL(r.request().url()).origin===base?r.continue():r.abort());await c.addCookies(Object.entries(f.sessions.fan.cookies).map(([name,value])=>({name,value,url:base})));const p=await c.newPage();
  let calls=[],active=0,max=0,mode='fail';
  await p.route('**/notification-view?*',async r=>{calls.push(Date.now());active++;max=Math.max(max,active);try{if(mode==='fail')await r.abort('failed');else {await new Promise(resolve=>setTimeout(resolve,3000));await r.continue();}}finally{active--;}});
  await p.goto(base+'/app/fan/notifications');
  while(calls.length<3)await p.waitForTimeout(1000);
  assert.ok(calls[1]-calls[0]>=23000&&calls[2]-calls[1]>=47000);checks.push('Transport errors back off from 24 to 48 seconds');
  mode='delay';const done=p.waitForResponse(r=>r.url().includes('/notification-view'));await p.evaluate(()=>{for(let i=0;i<8;i++)window.dispatchEvent(new Event('online'));});await done;assert.equal(max,1);checks.push('Repeated online events never overlap notification requests');
  await p.waitForTimeout(7000);assert.equal(await p.locator('.fu-notification-status').innerText(),'');checks.push('Successful real read clears transport error');
  await p.unroute('**/notification-view?*');await p.goto(base+'/app/fan/explorer');const read=await p.waitForResponse(r=>r.url().includes('/notification-view'));assert.equal(new URL(read.url()).searchParams.get('part'),'count');checks.push('Bell outside center uses count-only private read');
  const n=Number(await p.locator('.fu-notification-count').innerText());assert.ok(Number.isInteger(n));checks.push('Bell count remains server-provided');
  fs.writeFileSync(out+'/transport.json',JSON.stringify({passed:checks.length,checks},null,2));console.log(JSON.stringify({passed:checks.length}));
 }finally{await b.close();}
})().catch(e=>{console.error(e.message);process.exitCode=1;});
