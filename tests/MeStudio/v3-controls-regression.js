// Real renderer/styles, synthetic media and host wrappers; not Safari on a physical phone.
'use strict';
const fs=require('fs'),path=require('path'),cp=require('child_process'),assert=require('assert/strict');
const {chromium,webkit}=require('playwright');
const root=path.resolve(__dirname,'../..'),out=path.join(root,'docs/evidence/me-v3-063');
const cards=JSON.parse(cp.execFileSync(process.env.FALUSS_PHP,[path.join(__dirname,'v3-controls-regression.php')],{encoding:'utf8',env:{...process.env,FALUSS_V3_CONTROLS:'1'}}));
const css=['assets/me-studio/css/card-v2.css','assets/link/css/faluss-link.css','assets/link/css/faluss-link-immersive.css'].map(f=>fs.readFileSync(path.join(root,f),'utf8')).join('\n');
const js=fs.readFileSync(path.join(root,'assets/link/js/faluss-link-card.js'),'utf8');
const media='data:image/svg+xml,'+encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="600" height="900"><rect width="600" height="900" fill="#304f40"/><path d="M0 300L220 90 600 300 600 900 0 900" fill="#538c63"/></svg>');
const html=(key,publicPage=false)=>`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#fffdf5"><style>body{margin:0}.elementor,.e-con,.elementor-widget,.elementor-widget-container{padding:25px;margin:20px;background:#fff;max-width:800px}${css}</style>${publicPage?cards.head:""}</head><body class="${publicPage?'faluss-identity-public-route':''}"><main class="faluss-identity-profile-page"><div class="elementor"><div class="e-con"><div class="elementor-widget"><div class="elementor-widget-container">${cards[key].replace(/https:\/\/faluss.test\/media\/\d+\.jpg(?:\?[^" ]*)?/g,media)}</div></div></div></div></main><script>${js}</script></body></html>`;
(async()=>{
 fs.mkdirSync(out,{recursive:true});const results=[];
 for(const [engine,type,opts] of [['chromium',chromium,{executablePath:process.env.FALUSS_CHROME}],['webkit',webkit,{}]]){
  const browser=await type.launch({headless:true,...opts});
  try{
   for(const width of [320,390,768]){
    const page=await browser.newPage({viewport:{width,height:844}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
    const colors=()=>page.locator('article').evaluate(e=>Object.fromEntries(['name','handle','bio','link'].map(part=>[part,getComputedStyle(e.querySelector('.faluss-link-card__'+part)).color])));
    await page.setContent(html('color-legacy'));const legacy=await colors();
    for(const [key,color] of [['#FFFFFF','rgb(255, 255, 255)'],['#000000','rgb(0, 0, 0)'],['#C94B73','rgb(201, 75, 115)']]){
     await page.setContent(html('color-'+key));const current=await colors();
     assert.equal(current.handle,color);assert.equal(current.bio,color);assert.equal(current.name,legacy.name);assert.equal(current.link,legacy.link);
    }
    await page.setContent(html('color-legacy'));assert.deepEqual(await colors(),legacy,'Unset preference preserves former text styling');
    let listBorder;
    for(const border of ['none','solid']){
     await page.setContent(html('list-'+border));const value=await page.locator('.faluss-link-card__link').first().evaluate(e=>getComputedStyle(e).border);
     if(listBorder)assert.equal(value,listBorder,'Illustrated border never changes list mode');listBorder=value;
     for(const size of ['compact','cover']){
      await page.setContent(html(size+'-'+border,true));
      const values=await page.locator('.faluss-link-card__link').evaluateAll(nodes=>nodes.map(e=>({width:getComputedStyle(e).borderTopWidth,color:getComputedStyle(e).borderTopColor})));
      assert(values.length>2);for(const v of values){assert.equal(v.width,border==='none'?'0px':'1px');if(border==='solid')assert.equal(v.color,'rgb(201, 75, 115)');}
     }
    }
    for(const key of ['compact-short-none','cover-short-none','compact-none','cover-none']){
     await page.setContent(html(key,true));
     for(const height of [690,844,932,690]){
      await page.setViewportSize({width,height});
      for(const scroll of [0,10000]){
       await page.evaluate(y=>window.scrollTo(0,y),scroll);
       const geometry=await page.locator('article').evaluate(e=>{const r=e.getBoundingClientRect();return {top:r.top+scrollY,bottom:r.bottom+scrollY,w:r.width,x:r.x,document:document.documentElement.scrollHeight,background:getComputedStyle(document.documentElement).backgroundColor,body:getComputedStyle(document.body).backgroundColor,canvas:getComputedStyle(e).backgroundColor,theme:document.querySelector('meta[name=theme-color]').content};});
       assert.equal(geometry.top,0,'No upper host gap');assert.equal(geometry.x,0);assert.equal(geometry.w,width);
       assert(geometry.bottom>=height-1 && geometry.bottom>=geometry.document-1,'Canvas covers the entire drawable document '+JSON.stringify({engine,width,height,key,geometry}));
       assert.equal(geometry.background,geometry.canvas);assert.equal(geometry.body,geometry.canvas);assert.equal(geometry.theme,'#000000');
      }
     }
     await page.setViewportSize({width,height:844});await page.evaluate(()=>window.scrollTo(0,0));
     // Inject representative installed-mode insets: browser emulation does not supply an iOS notch.
     await page.addStyleTag({content:css.replaceAll('env(safe-area-inset-top,0px)','44px').replaceAll('env(safe-area-inset-bottom,0px)','34px')});
     const inset=await page.locator('.faluss-link-card__body').evaluate(e=>({top:parseFloat(getComputedStyle(e).paddingTop),bottom:parseFloat(getComputedStyle(e).paddingBottom)}));assert(inset.top>=44&&inset.bottom>=66,JSON.stringify({engine,width,key,inset}));
     if(width===390&&key==='cover-none')await page.screenshot({path:path.join(out,engine+'-public-cover-390.png'),fullPage:true});
    }
    await page.setContent(html('cover-none',false));assert.equal(await page.evaluate(()=>document.documentElement.style.getPropertyValue('--fl-public-canvas')),'','Embedded preview must not recolour its document');
    assert.deepEqual(errors,[]);results.push(`${engine} ${width}: common handle/bio colour + unchanged name/links/legacy, illustrated border none/colour + unchanged list; full public document, host wrappers, short/long Compact/Cover, viewport 690/844/932, scroll endpoints and simulated installed safe areas OK`);await page.close();
   }
   const noScript=await browser.newPage({javaScriptEnabled:false,viewport:{width:390,height:844}});
   await noScript.setContent(html('cover-short-none',true));assert.equal(await noScript.locator('html').evaluate(e=>getComputedStyle(e).backgroundColor),'rgb(0, 0, 0)','Server canvas works without JavaScript');await noScript.close();
  }finally{await browser.close();}
 }
 fs.writeFileSync(path.join(out,'controls-results.txt'),results.join('\n')+'\n');console.log(results.join('\n'));
})().catch(e=>{console.error(e);process.exit(1);});
