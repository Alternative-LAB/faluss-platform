(() => {
  'use strict';
  const bar=document.querySelector('[data-notifications-endpoint]');if(!bar)return;
  const endpoint=new URL(bar.dataset.notificationsEndpoint,location.href);if(endpoint.origin!==location.origin)return;
  const center=document.querySelector('.fu-notifications'),list=center?.querySelector('.fu-notification-list');
  let timer=null,controller=null,stopped=false,failures=0,again=false;
  const interval=center?12000:20000;
  const status=text=>{const el=center?.querySelector('.fu-notification-status');if(el)el.textContent=text;};
  function pause(){clearTimeout(timer);controller?.abort();}
  function stop(){stopped=true;pause();}
  function reconcile(html){
    const template=document.createElement('template');template.innerHTML=html;
    const focused=document.activeElement,focusId=focused?.closest('.fu-notification')?.dataset.id;
    const oldRows=[...list.querySelectorAll('.fu-notification')];
    const anchor=oldRows.find(el=>el.getBoundingClientRect().bottom>0),top=anchor?.getBoundingClientRect().top;
    const scroll=scrollY,oldFocusIndex=oldRows.findIndex(el=>el.dataset.id===focusId);
    const existing=new Map(oldRows.map(el=>[el.dataset.id,el]));
    // Keep unchanged nodes (including their focus and native forms). Update changed rows from the server projection.
    const nodes=[...template.content.children].map(node=>{
      const old=existing.get(node.dataset.id);return old&&old.outerHTML===node.outerHTML?old:node;
    });
    list.replaceChildren(...nodes);
    if(focusId){
      const same=nodes.find(el=>el.dataset.id===focusId)?.querySelector('button');
      const fallback=nodes[Math.min(Math.max(0,oldFocusIndex),nodes.length-1)]?.querySelector('button')||center.querySelector('nav a');
      (same||fallback)?.focus({preventScroll:true});
    }
    const kept=anchor&&nodes.find(el=>el.dataset.id===anchor.dataset.id);
    // At the top show arrivals immediately; further down preserve the visible notification's position.
    if(scroll>0&&kept)scrollTo(0,scroll+kept.getBoundingClientRect().top-top);
  }
  async function refresh(){
    clearTimeout(timer);if(stopped||document.hidden||!navigator.onLine)return;
    if(controller){again=true;return;}again=false;controller=new AbortController();
    const timeout=setTimeout(()=>controller?.abort(),10000);
    try{
      const url=new URL(endpoint);url.searchParams.set('role',bar.dataset.role);url.searchParams.set('part',center?'center':'count');
      if(center){url.searchParams.set('filter',center.dataset.filter);url.searchParams.set('cursor',center.dataset.cursor);}
      const response=await fetch(url,{credentials:'same-origin',cache:'no-store',redirect:'error',signal:controller.signal,headers:{'X-WP-Nonce':bar.dataset.notificationsNonce}});
      if(response.status===401||response.status===403){
        stop();list?.replaceChildren();bar.querySelector('.fu-notification-count')?.remove();
        center?.querySelector('.fu-notification-pagination')?.replaceChildren();status('Session expirée ou accès indisponible. Reconnectez-vous.');return;
      }
      if(!response.ok)throw new Error('notifications');
      const data=await response.json();if(stopped||document.hidden||!navigator.onLine)return;
      if(!Number.isInteger(data.unread)||data.unread<0)throw new Error('notifications');
      let count=bar.querySelector('.fu-notification-count');
      if(!count){bar.querySelector('.fu-footnote')?.remove();count=document.createElement('span');count.className='fu-notification-count';bar.querySelector('.fu-bell').append(count);}
      count.textContent=String(data.unread);count.setAttribute('aria-label',`${data.unread} non lues`);
      center?.querySelector('[data-unread-count]')?.replaceChildren(String(data.unread));
      if(list){
        if(typeof data.html!=='string'||typeof data.pagination!=='string')throw new Error('notifications');
        reconcile(data.html);
        const pagination=center.querySelector('.fu-notification-pagination');
        // Never replace the pagination link while it is focused; update on the next poll.
        if(!pagination.contains(document.activeElement)&&pagination.innerHTML!==data.pagination)pagination.innerHTML=data.pagination;
      }
      status('');failures=0;
    }catch(error){if(!stopped&&!document.hidden&&navigator.onLine){failures=Math.min(failures+1,4);status('Actualisation interrompue. Nouvelle tentative automatique.');}}
    finally{
      clearTimeout(timeout);controller=null;
      if(!stopped&&!document.hidden&&navigator.onLine)timer=setTimeout(refresh,again?0:Math.min(120000,interval*2**failures));
    }
  }
  document.addEventListener('visibilitychange',()=>document.hidden?pause():refresh());
  window.addEventListener('offline',pause);window.addEventListener('online',refresh);
  window.addEventListener('focus',refresh);
  document.addEventListener('fans-session-ended',stop);window.addEventListener('pagehide',stop);
  // Native POST submission is the only UI mutation; it survives disabled JavaScript and checks rights again.
  center?.addEventListener('submit',pause);
  refresh();
})();
