const {chromium}=require('playwright'),fs=require('fs'),assert=require('node:assert/strict'),{execFileSync}=require('node:child_process');
(async()=>{
 const {BASE:base,ROOT:root,OUT:out}=process.env;assert.equal(new URL(base).hostname,'127.0.0.1');assert.match(root,/^\/var\/tmp\/fans-admission-wp-[a-z0-9_]+$/);
 const fixture=JSON.parse(execFileSync('wsl.exe',['-u','root','--','cat',root+'/session.json']));
 const browser=await chromium.launch({channel:'chrome',headless:true}),ctx=await browser.newContext({viewport:{width:320,height:700},hasTouch:true,isMobile:true});
 await ctx.addCookies(Object.entries(fixture.sessions.other.cookies).map(([name,value])=>({name,value,url:base})));
 await ctx.route('**/*',r=>new URL(r.request().url()).origin===base?r.continue():r.abort());const p=await ctx.newPage(),checks=[];
 try{
 await p.goto(base+'/app/creator/messages?thread=00000000-0000-4000-8000-000000000099');
 await p.getByRole('heading',{name:'Conversation indisponible',exact:true}).waitFor();await p.getByRole('link',{name:'← Conversations',exact:true}).waitFor();
 await p.getByRole('link',{name:'← Conversations',exact:true}).tap();await p.locator('.fu-message-list').waitFor();checks.push('unavailable conversation keeps a visible working return');
 assert.equal(await p.locator('.fu-nav__item').count(),8);
 await p.locator('.fu-nav').evaluate(n=>n.scrollLeft=0);
 const rect=await p.locator('.fu-nav').boundingBox(),cdp=await ctx.newCDPSession(p),y=rect.y+30;
 await cdp.send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[{x:280,y}]});
 for(const x of [240,200,160,120,80,40])await cdp.send('Input.dispatchTouchEvent',{type:'touchMove',touchPoints:[{x,y}]});
 await cdp.send('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});
 await p.waitForFunction(()=>document.querySelector('.fu-nav').scrollLeft>50);checks.push('native horizontal touch swipe reveals overflow tabs');
 assert.ok(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
 await p.screenshot({path:out+'/creator-navigation-touch-320.png'});
 await p.locator('.fu-nav__item').last().focus();assert.ok(await p.locator('.fu-nav__item').last().evaluate(n=>n.getBoundingClientRect().right<=innerWidth));checks.push('all eight destinations retained and keyboard focus revealed');
 for(const width of [700,701]){await p.setViewportSize({width,height:844});await p.waitForFunction(width=>Math.abs(innerWidth-width)<2,width);const rows=await p.locator('.fu-nav').evaluate(n=>getComputedStyle(n).flexDirection);assert.equal(rows,width===700?'row':'column');}checks.push('mobile breakpoint switches cleanly to unchanged desktop sidebar');
 fs.writeFileSync(out+'/navigation.json',JSON.stringify({checks},null,2));console.log('PASS '+checks.length+' navigation checks');
 }finally{await browser.close();}
})().catch(e=>{console.error(e.stack);process.exit(1);});
