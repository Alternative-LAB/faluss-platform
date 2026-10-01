(() => {
  'use strict';
  const dialog=document.querySelector('#fu-create');if(!dialog)return;
  // Old bookmarks addressed the gallery inside the former combined page.
  if(location.pathname.replace(/\/$/,'').endsWith('/creator/creer') && location.hash==='#fu-images'){
    location.replace(location.pathname.replace(/creer\/?$/,'images')+location.search+'#fu-images');return;
  }
  const main=document.querySelector('.fu-main'),rail=document.querySelector('.fu-rail'),root=document.documentElement;
  const panels=[...dialog.querySelectorAll('[data-create-panel]')],choices=[...dialog.querySelectorAll('[data-create-type]')];
  const nav=rail.querySelector('[data-create-open]');
  let opened=false,previous=null,previousActive=null,oldOverflow='',position=0,closing=false,queued=null;
  const media=new window.FansCreateMedia(dialog,()=>show('publication',true));
  function show(type,returning=false){
    panels.forEach(panel=>panel.hidden=panel.dataset.createPanel!==type);
    choices.forEach(choice=>choice.setAttribute('aria-pressed',String(choice.dataset.createType===(type==='images'?'publication':type))));
    media.clear();dialog.querySelector('.fu-create__panels').scrollTop=0;
    if(type==='images')media.load();
    if(returning)dialog.querySelector('[data-create-images]')?.focus({preventScroll:true});
    else (panels.find(panel=>!panel.hidden)?.querySelector('h2')||choices[0]).focus({preventScroll:true});
  }
  function open(trigger,push=true){
    if(closing){queued=trigger;return;}if(opened){close();return;}
    previous=trigger;opened=true;position=scrollY;oldOverflow=root.style.overflow;
    previousActive=rail.querySelector('.is-active');previousActive?.classList.remove('is-active');nav?.classList.add('is-active');nav?.setAttribute('aria-expanded','true');
    dialog.hidden=false;main.inert=true;
    // Lock the viewport, not body: body overflow would become the sticky rail's scroll container.
    root.style.overflow='hidden';
    if(push)history.pushState({...history.state,fansCreate:true},'',location.href);
    show('selector');
  }
  function close(fromHistory=false,restoreFocus=true){
    if(!opened)return;opened=false;media.clear();dialog.hidden=true;main.inert=false;root.style.overflow=oldOverflow;
    nav?.classList.remove('is-active');nav?.setAttribute('aria-expanded','false');previousActive?.classList.add('is-active');
    window.scrollTo({top:position,behavior:'instant'});
    if(restoreFocus&&previous?.isConnected)previous.focus({preventScroll:true});
    if(!fromHistory&&history.state?.fansCreate===true){closing=true;history.back();}
  }
  document.addEventListener('click',event=>{
    const trigger=event.target.closest('[data-create-open]');
    if(trigger&&event.button===0&&!event.ctrlKey&&!event.metaKey&&!event.shiftKey&&!event.altKey){event.preventDefault();open(trigger);}
    else if(opened&&event.target.closest('.fu-rail a'))close(true,false);
  });
  dialog.addEventListener('click',event=>{
    if(event.target.closest('[data-create-close]')){close();return;}
    if(event.target.closest('[data-create-images]')){show('images');return;}
    if(event.target.closest('[data-create-image-return]')){show('publication',true);return;}
    const choice=event.target.closest('[data-create-type]');if(choice)show(choice.dataset.createType);
    const toggle=event.target.closest('[data-create-emoji]');
    if(toggle){const list=dialog.querySelector('#fu-compose-emojis');list.hidden=!list.hidden;toggle.setAttribute('aria-expanded',String(!list.hidden));if(!list.hidden)list.querySelector('button').focus({preventScroll:true});}
    const emoji=event.target.closest('[data-emoji]');
    if(emoji){const input=dialog.querySelector('#fu-compose-text'),start=input.selectionStart,end=input.selectionEnd;if(!input.readOnly&&input.value.length-(end-start)+emoji.dataset.emoji.length<=input.maxLength){input.setRangeText(emoji.dataset.emoji,start,end,'end');input.dispatchEvent(new Event('input',{bubbles:true}));}input.focus({preventScroll:true});}
  });
  document.addEventListener('keydown',event=>{
    if(!opened)return;
    if(event.key==='Escape'){event.preventDefault();if(!dialog.querySelector('[data-create-panel=images]').hidden)show('publication',true);else close();return;}
    if(event.key!=='Tab')return;
    const focusable=[...dialog.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),textarea:not([disabled]),select:not([disabled])'),...rail.querySelectorAll('a[href]')].filter(item=>item.getClientRects().length&&!item.closest('[hidden]'));
    const index=focusable.indexOf(document.activeElement),next=index<0?(event.shiftKey?focusable.length-1:0):(index+(event.shiftKey?-1:1)+focusable.length)%focusable.length;
    event.preventDefault();focusable[next]?.focus({preventScroll:true});
  });
  window.addEventListener('popstate',()=>{closing=false;if(history.state?.fansCreate===true){if(!opened)open(nav,false);}else close(true);if(queued){const trigger=queued;queued=null;open(trigger);}});
  window.addEventListener('pagehide',()=>close(true,false));
})();
