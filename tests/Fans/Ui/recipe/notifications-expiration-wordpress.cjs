const {chromium}=require('playwright'),assert=require('node:assert/strict'),fs=require('node:fs'),{execFileSync}=require('node:child_process');
(async()=>{
 const {BASE:base,ROOT:root,OUT:out}=process.env;assert.equal(new URL(base).hostname,'127.0.0.1');assert.match(root,/^\/var\/tmp\/fans-admission-wp-[a-z0-9_]+$/);
 const wsl=args=>execFileSync('wsl.exe',['-u','root','--',...args]),f=JSON.parse(wsl(['cat',root+'/session.json'])),b=await chromium.launch({channel:'chrome'});
 try{
  const c=await b.newContext();await c.route('**/*',r=>new URL(r.request().url()).origin===base?r.continue():r.abort());await c.addCookies(Object.entries(f.sessions.fan.cookies).map(([name,value])=>({name,value,url:base})));const p=await c.newPage();await p.goto(base+'/app/fan/notifications');assert.ok(await p.locator('.fu-notification-row').count()>0);
  const code="<?php if(DB_NAME!=='admission_recipe')throw new RuntimeException('fixture only');$f=json_decode(file_get_contents("+JSON.stringify(root+'/session.json')+"),true);$s=$f['sessions']['fan'];$m=WP_Session_Tokens::get_instance($s['id']);foreach($s['cookies'] as $name=>$cookie){$p=wp_parse_auth_cookie($cookie,$name===LOGGED_IN_COOKIE?'logged_in':'auth');$v=$m->get($p['token']);if($v){$v['expiration']=time()-1;$m->update($p['token'],$v);}}";
  execFileSync('wsl.exe',['-u','root','--','tee',root+'/expire-test.php'],{input:code,stdio:['pipe','ignore','pipe']});wsl(['php','/var/tmp/faluss-v3-wp/wp-cli.phar','--allow-root','--path='+root+'/wordpress','eval-file',root+'/expire-test.php','--use-include']);
  await p.waitForFunction(()=>!document.querySelector('.fu-notification-row'),null,{timeout:18000});assert.equal(await p.locator('.fu-notification-count').count(),0);assert.equal(await p.locator('[data-unread-count]').innerText(),'—');
  fs.writeFileSync(out+'/expiration.json',JSON.stringify({passed:2,checks:['Actual WordPress token expiration clears private rows on next poll','Expired session clears filter count without a page reload']},null,2));console.log('PASS 2 actual server-expiration checks');
 }finally{await b.close();}
})().catch(e=>{console.error(e.message);process.exitCode=1;});
