(() => {
  'use strict';
  const dialog = document.querySelector('#fu-create');
  if (!dialog) return;
  const main = document.querySelector('.fu-main'), rail = document.querySelector('.fu-rail');
  const panels = [...dialog.querySelectorAll('[data-create-panel]')];
  const choices = [...dialog.querySelectorAll('[data-create-type]')];
  let previous = null, oldOverflow = '', opened = false;
  let previousActive = null;
  function close(fromHistory = false) {
    if (!opened) return;
    opened = false; dialog.hidden = true; main.inert = false; rail.inert = false;
    document.body.style.overflow = oldOverflow;
    previous?.classList.remove('is-active');previous?.setAttribute('aria-expanded','false');
    previousActive?.classList.add('is-active');
    if (previous?.isConnected) previous.focus();
    if (!fromHistory && history.state?.fansCreate === true) history.back();
  }
  function open(trigger) {
    if (opened) return;
    previous = trigger; opened = true; oldOverflow = document.body.style.overflow;
    previousActive=rail.querySelector('.is-active');previousActive?.classList.remove('is-active');
    trigger.classList.add('is-active');trigger.setAttribute('aria-expanded','true');
    panels.forEach(panel => { panel.hidden = true; });
    choices.forEach(choice => choice.setAttribute('aria-pressed','false'));
    dialog.hidden = false; main.inert = true; rail.inert = true;
    document.body.style.overflow = 'hidden';
    history.pushState({...history.state,fansCreate:true},'',location.href);
    choices[0].focus();
  }
  document.addEventListener('click', event => {
    const trigger = event.target.closest('[data-create-open]');
    if (!trigger || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    event.preventDefault();open(trigger);
  });
  dialog.addEventListener('click', event => {
    if (event.target.closest('[data-create-close]')) { close();return; }
    const choice = event.target.closest('[data-create-type]');
    if (choice) {
      choices.forEach(item => item.setAttribute('aria-pressed',String(item === choice)));
      panels.forEach(panel => { panel.hidden = panel.dataset.createPanel !== choice.dataset.createType; });
      panels.find(panel => !panel.hidden)?.querySelector('h2').focus();
    }
    const toggle = event.target.closest('[data-create-emoji]');
    if (toggle) {
      const list = dialog.querySelector('#fu-compose-emojis');list.hidden = !list.hidden;
      toggle.setAttribute('aria-expanded',String(!list.hidden));
      if (!list.hidden) list.querySelector('button').focus();
    }
    const emoji = event.target.closest('[data-emoji]');
    if (emoji) {
      const input = dialog.querySelector('#fu-compose-text');
      const start=input.selectionStart, end=input.selectionEnd;
      if (input.value.length - (end-start) + emoji.dataset.emoji.length <= input.maxLength) {
        input.setRangeText(emoji.dataset.emoji,start,end,'end');input.dispatchEvent(new Event('input',{bubbles:true}));
      }
      input.focus();
    }
  });
  dialog.addEventListener('keydown', event => {
    if (event.key === 'Escape') { event.preventDefault();close();return; }
    if (event.key !== 'Tab') return;
    const focusable = [...dialog.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),textarea:not([disabled]),select:not([disabled])')]
      .filter(item=>item.getClientRects().length && !item.closest('[hidden]'));
    const first=focusable[0],last=focusable.at(-1);
    if (event.shiftKey && (document.activeElement === first || !focusable.includes(document.activeElement))) { event.preventDefault();last.focus(); }
    else if (!event.shiftKey && (document.activeElement === last || !focusable.includes(document.activeElement))) { event.preventDefault();first.focus(); }
  });
  window.addEventListener('popstate',()=>close(true));
  window.addEventListener('pagehide',()=>close(true));
})();
