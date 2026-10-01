(() => {
  'use strict';
  const body=document.body,endpoint=body.dataset.fansSession;if(!endpoint)return;
  const publicUrl=new URL(body.dataset.sessionPublic,location.origin);if(publicUrl.origin!==location.origin)return;
  const channel='faluss-fans-session-closed',broadcast=typeof BroadcastChannel==='function'?new BroadcastChannel(channel):null;
  let leaving=false,checking=false;
  function leave(closed=false){
    if(leaving)return;leaving=true;document.dispatchEvent(new Event('fans-session-ended'));body.replaceChildren();body.hidden=true;
    if(closed)publicUrl.searchParams.set('faluss_fans_session','closed');location.replace(publicUrl.href);
  }
  function notify(){broadcast?.postMessage('closed');try{localStorage.setItem(channel,String(Date.now())+Math.random());}catch(error){/* Channel and server validation still apply. */}}
  broadcast?.addEventListener('message',event=>{if(event.data==='closed')leave(true);});
  window.addEventListener('storage',event=>{if(event.key===channel&&event.newValue)leave(true);});
  async function check(){
    if(leaving||checking)return;checking=true;
    try{const response=await fetch(endpoint,{credentials:'same-origin',cache:'no-store',redirect:'error',headers:{'X-WP-Nonce':body.dataset.sessionNonce}});const data=await response.json();if(!response.ok||data.active!==true){leave();return;}body.hidden=false;}
    catch(error){leave();}finally{checking=false;}
  }
  // The deadline comes from the verified server token, not a visit-based renewal or the client's wall clock.
  const remaining=Math.max(0,(Number(body.dataset.sessionExpires)-Number(body.dataset.sessionNow))*1000);
  setTimeout(()=>leave(),remaining);
  document.addEventListener('visibilitychange',()=>{if(document.hidden)body.hidden=true;else check();});
  window.addEventListener('focus',check);
  window.addEventListener('pagehide',()=>{document.dispatchEvent(new Event('fans-session-ended'));body.replaceChildren();body.hidden=true;});
  window.addEventListener('pageshow',event=>{if(event.persisted){body.hidden=true;location.reload();}});
  body.querySelector('[data-fans-logout]')?.addEventListener('submit',async event=>{
    event.preventDefault();const form=event.target,button=form.querySelector('button');if(button.disabled)return;button.disabled=true;
    const data=new FormData(form);data.set('fans_logout_json','1');
    try{const response=await fetch(form.getAttribute('action'),{method:'POST',credentials:'same-origin',cache:'no-store',redirect:'error',body:data});const result=await response.json();if(!response.ok||result.success!==true)throw new Error('logout');notify();leave(true);}
    catch(error){button.disabled=false;let status=form.querySelector('[role=alert]');if(!status){status=document.createElement('p');status.setAttribute('role','alert');form.append(status);}status.textContent='Déconnexion non confirmée. Rechargez votre espace et réessayez.';}
  });
})();
