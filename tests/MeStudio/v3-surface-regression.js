// Canonical PHP output, illustrative local media, no WordPress or physical device.
'use strict';
const fs=require('fs'),path=require('path'),cp=require('child_process'),assert=require('assert/strict');
const {chromium,webkit}=require('playwright');
const root=path.resolve(__dirname,'../..'),out=path.join(root,'docs/evidence/me-v3-062');
const cards=JSON.parse(cp.execFileSync(process.env.FALUSS_PHP,[path.join(__dirname,'v3-surface-regression.php')],{encoding:'utf8',env:{...process.env,FALUSS_V3_SURFACES:'1'}}));
const css=['assets/me-studio/css/card-v2.css','assets/link/css/faluss-link.css','assets/link/css/faluss-link-immersive.css'].map(f=>fs.readFileSync(path.join(root,f),'utf8')).join('\n');
const motion=fs.readFileSync(path.join(root,'assets/link/js/faluss-link-immersive.js'),'utf8');
const media=color=>'data:image/svg+xml,'+encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" width="600" height="600"><rect width="600" height="600" fill="${color}"/><path d="M0 450L180 120 360 400 500 90 600 450 600 600 0 600" fill="#d6c5a6"/></svg>`);
const html=(key,script=false)=>`<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0}${css}</style>${cards[key].replace(/\?ver=[^" ]*/g,'').replaceAll('https://faluss.test/media/77.jpg',media('#526479')).replaceAll('https://faluss.test/media/78.jpg',media('#ac7262'))}${script?`<script>${motion}</script>`:''}`;
(async()=>{
 fs.mkdirSync(out,{recursive:true});const results=[];
 for(const [engine,type,opts] of [['chromium',chromium,{executablePath:process.env.FALUSS_CHROME}],['webkit',webkit,{}]]){
  const browser=await type.launch({headless:true,...opts});
  try{
   for(const width of [320,390,768]){
    const page=await browser.newPage({viewport:{width,height:844}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
    for(const shape of ['round','rounded','square'])for(const effect of ['none','border','shadow','both']){
     await page.setContent(html(`avatar-${shape}-${effect}`));
     const style=await page.locator('.faluss-link-card__avatar').evaluate(el=>({radius:getComputedStyle(el).borderRadius,imageRadius:getComputedStyle(el.querySelector('img')).borderRadius,shadow:getComputedStyle(el).boxShadow,filter:getComputedStyle(el).filter}));
     assert.equal(style.radius,style.imageRadius,'Image respects its selected shape in real cascade');
     assert.equal(style.radius,shape==='round'?'999px':shape==='rounded'?'14px':'0px');
     if(effect==='border'||effect==='both')assert.notEqual(style.shadow,'none');
     if(effect==='shadow'||effect==='both')assert.notEqual(style.filter,'none');
    }
    await page.setContent(html('avatar-hidden'));assert.equal(await page.locator('.faluss-link-card__avatar').isVisible(),false);
    for(const size of ['compact','cover'])for(const visible of [0,1]){
     await page.setContent(html(size+'-'+visible,true));await page.evaluate(()=>new Promise(requestAnimationFrame));
     const pageStyle=await page.locator('article').evaluate(el=>({background:getComputedStyle(el).backgroundColor,fade:getComputedStyle(el).getPropertyValue('--fl-hero-transition-color')}));
     assert.equal(pageStyle.background,'rgb(0, 0, 0)');assert.equal(pageStyle.fade,'#000000');
     const tiles=await page.locator('.faluss-link-card__link').evaluateAll(nodes=>nodes.map(n=>{const r=n.getBoundingClientRect();return {x:r.x,y:r.y,w:r.width,h:r.height,label:n.textContent.trim(),color:getComputedStyle(n).color,radius:getComputedStyle(n).borderRadius};}));
     assert.equal(tiles.length,6);assert.equal(tiles[0].radius,'18px','Image tiles keep their shape independently of list button style');assert(tiles[0].w>tiles[1].w*1.9);assert.equal(tiles[1].y,tiles[2].y);assert(tiles[2].x>tiles[1].x);
     assert.deepEqual(tiles.map(t=>t.label),['Lien 1','Lien 2','Lien 3','Lien 4','Lien 5','Lien 7']);assert.equal(tiles[0].color,'rgb(255, 255, 255)');
     assert(await page.locator('.faluss-link-card__link-image').first().isVisible());
     assert(await page.locator('.faluss-link-card__link').first().locator('.faluss-link-card__link-icon').isVisible(),'Configured catalogue icon can coexist with a tile image');
     assert(await page.locator('[data-faluss-network="instagram"]').isVisible(),'Only configured catalogue media renders');
     assert.equal(await page.locator('.faluss-link-card__avatar').isVisible(),!!visible);
     const rest=await page.locator('.faluss-link-card__cover img').evaluate(el=>getComputedStyle(el).transform);assert.equal(rest,'matrix(1, 0, 0, 1, 0, 0)');
     assert(tiles[0].y<650,'First link remains accessible below identity');
     if(width===390&&visible)await page.screenshot({path:path.join(out,engine+'-'+size+'-images-390.png'),fullPage:true});
     await page.evaluate(()=>window.scrollTo(0,240));await page.waitForFunction(()=>getComputedStyle(document.querySelector('.faluss-link-card__cover img')).transform!=='matrix(1, 0, 0, 1, 0, 0)');
     const scrolled=await page.locator('.faluss-link-card__cover img').evaluate(el=>getComputedStyle(el).transform);assert.notEqual(scrolled,rest,'Canonical wallpaper moves and zooms out');
     await page.evaluate(()=>window.scrollTo(0,0));
    }
    await page.setContent(html('explicit-fade'));assert.equal(await page.locator('article').evaluate(el=>getComputedStyle(el).getPropertyValue('--fl-hero-transition-color')),'#82206B');
    await page.setContent(html('list'));assert.equal(await page.locator('.faluss-link-card__link-image').count(),0);assert.equal(await page.locator('.faluss-link-card__links').evaluate(el=>getComputedStyle(el).display),'grid');
    await page.emulateMedia({reducedMotion:'reduce'});await page.setContent(html('cover-1',true));await page.evaluate(()=>window.scrollTo(0,240));assert.equal(await page.locator('.faluss-link-card__cover img').evaluate(el=>getComputedStyle(el).transform),'none');
    assert.deepEqual(errors,[]);results.push(`${engine} ${width}: avatar 3 shapes x 4 effects, hide/restore, exact black/explicit fade, Compact/Cover with/without photo, image grid/fallback/order/visibility, resting crop, public motion/reduced motion OK`);await page.close();
   }
  }finally{await browser.close();}
 }
 fs.writeFileSync(path.join(out,'surface-results.txt'),results.join('\n')+'\n');console.log(results.join('\n'));
})().catch(e=>{console.error(e);process.exit(1);});
