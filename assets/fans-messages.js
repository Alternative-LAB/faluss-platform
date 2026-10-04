(() => {
  'use strict';
  const root = document.querySelector('.fu-messages'), layout = root?.querySelector('.fu-message-layout');
  if (!layout) return;
  const chat = root.querySelector('.fu-message-chat'), list = root.querySelector('.fu-message-list-scroll');
  const mobile = matchMedia('(max-width:700px)');
  let followingBottom = false;
  root.addEventListener('scroll', event => { const n = event.target; if (n.matches?.('.fu-message-scroll')) followingBottom = n.scrollHeight - n.scrollTop - n.clientHeight < 80; }, true);
  const resize = () => {
    const vv = visualViewport, rail = document.querySelector('.fu-rail');
    const bottom = rail && getComputedStyle(rail).position === 'fixed' ? rail.getBoundingClientRect().height : 0;
    if (mobile.matches) {
      root.style.setProperty('--fu-message-top', `${vv?.offsetTop || 0}px`);
      root.style.setProperty('--fu-message-height', `${Math.max(0, (vv?.height || innerHeight) - bottom)}px`);
      document.body.style.setProperty('--fu-keyboard-bottom', `${Math.max(0, innerHeight - (vv?.height || innerHeight) - (vv?.offsetTop || 0))}px`);
      layout.style.removeProperty('--fu-message-height');
    } else {
      root.style.removeProperty('--fu-message-height');
      layout.style.setProperty('--fu-message-height', `${Math.max(280, (vv?.height || innerHeight) - Math.max(0, layout.getBoundingClientRect().top - (vv?.offsetTop || 0)) - bottom - 12)}px`);
    }
    if (mobile.matches && followingBottom) { const scroll = root.querySelector('.fu-message-scroll'); if (scroll) scroll.scrollTop = scroll.scrollHeight; }
  };
  resize(); window.addEventListener('resize', resize); visualViewport?.addEventListener('resize', resize); visualViewport?.addEventListener('scroll', resize); document.fonts.ready.then(resize);
  root.addEventListener('error', event => { if (event.target.matches('.fu-message-avatar img')) event.target.hidden = true; }, true);
  root.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    const detail = event.target.closest('details[open]');
    if (detail) { detail.open = false; detail.querySelector('summary')?.focus(); event.preventDefault(); }
  });
  if (root.dataset.section !== 'inbox' || !root.dataset.refresh || !window.fetch || !crypto.randomUUID) return;
  const endpoint = new URL(root.dataset.refresh, location.href);
  if (endpoint.origin !== location.origin) return;
  const apiBase = new URL('./', endpoint), role = root.dataset.role;
  let thread = new URL(location.href).searchParams.get('thread') || '';
  let sequence = 0, historyRevision = null, epoch = 0, busy = false, stopped = false, timer, controller;
  let chatDue = 0, listDue = 0, failures = 0, cursor = '', seen = new Set(), listPosition = 0;
  let pendingForm = null, fresh = false, rebuilding = false, readAnchor = null;
  const status = document.createElement('p'); status.className = 'fu-message-sync-status'; status.setAttribute('role', 'status'); root.prepend(status);
  const readSequence = () => Math.max(0, ...Array.from(chat.querySelectorAll('[data-sequence]'), el => Number(el.dataset.sequence)));
  const init = () => { sequence = readSequence(); historyRevision = Number(chat.querySelector('[data-history-revision]')?.dataset.historyRevision ?? 0); };
  init();
  function notice(text) { status.textContent = text; resize(); }
  function fragment(html) { const t = document.createElement('template'); t.innerHTML = html; return t.content; }
  function endpointFor(params) { const u = new URL(endpoint); Object.entries({ role, ...params }).forEach(([k, v]) => u.searchParams.set(k, v)); return u; }
  function rememberPosition() { if (!list.getClientRects().length) return; history.replaceState({ ...history.state, fansListScroll: list.scrollTop }, '', location.href); listPosition = list.scrollTop; }
  function urlFor(id = '') { const u = new URL(location.href); u.search = ''; if (id) u.searchParams.set('thread', id); return u; }
  function publicExit() {
    stopped = true; clearTimeout(timer); controller?.abort(); root.replaceChildren();
    const p = document.createElement('p'); p.textContent = 'Session expirée ou accès refusé. Connectez-vous à nouveau pour continuer.';
    const a = document.createElement('a'); a.href = document.body.dataset.sessionPublic || '/app/fan/explorer'; a.textContent = 'Revenir à Fans'; root.append(p, a);
  }
  async function read(url, data) {
    controller = new AbortController(); const timeout = setTimeout(() => controller.abort(), 12000);
    try {
      const response = await fetch(url, { method: data ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: controller.signal,
        headers: { 'X-WP-Nonce': root.dataset.nonce, ...(data ? { 'Content-Type': 'application/json' } : {}) }, ...(data ? { body: JSON.stringify(data) } : {}) });
      if (response.status === 401 || response.status === 403 && !data) { publicExit(); throw new Error('access'); }
      const result = await response.json();
      if (!response.ok) { const error = new Error('request'); error.status = response.status; error.code = result.code; throw error; }
      return result;
    } finally { clearTimeout(timeout); controller = null; }
  }
  function anchor() {
    const scroll = chat.querySelector('.fu-message-scroll');
    if (!scroll) return { bottom: true, top: 0 };
    const first = [...scroll.querySelectorAll('[data-sequence]')].find(el => el.getBoundingClientRect().bottom > scroll.getBoundingClientRect().top);
    return { bottom: scroll.scrollHeight - scroll.scrollTop - scroll.clientHeight < 80, top: scroll.scrollTop,
      seq: first?.dataset.sequence, offset: first ? first.getBoundingClientRect().top - scroll.getBoundingClientRect().top : 0 };
  }
  function restore(a) {
    const scroll = chat.querySelector('.fu-message-scroll'); if (!scroll) return;
    const el = a.seq ? scroll.querySelector(`[data-sequence="${a.seq}"]`) : null;
    if (a.bottom) scroll.scrollTop = scroll.scrollHeight;
    else if (el) scroll.scrollTop += el.getBoundingClientRect().top - scroll.getBoundingClientRect().top - a.offset;
    else scroll.scrollTop = a.top;
  }
  function controls(doc) {
    // Update server decisions/nonces while retaining draft, idempotency key, focus and open menus.
    const oldHeader = chat.querySelector('.fu-message-heading'), newHeader = doc.querySelector('.fu-message-heading');
    if (oldHeader && newHeader) {
      const open = oldHeader.querySelector('details')?.open;
      const focused = oldHeader.contains(document.activeElement);
      // Only replace controls when their semantic state changes; routine polling does not steal focus.
      const signature = el => { const c = el.cloneNode(true); c.removeAttribute('data-revision'); c.removeAttribute('data-history-revision'); c.querySelectorAll('input[name=revision],input[name=fans_messages_nonce]').forEach(n => n.remove()); c.querySelectorAll('details').forEach(n => n.removeAttribute('open')); return c.innerHTML; };
      if (signature(oldHeader) !== signature(newHeader)) { oldHeader.replaceWith(newHeader); if (open) newHeader.querySelector('details').open = true; if (focused) newHeader.querySelector('summary')?.focus(); }
    }
    const oldActions = chat.querySelector('.fu-message-actions'), newActions = doc.querySelector('.fu-message-actions');
    if (!oldActions && newActions) chat.querySelector('.fu-message-heading')?.after(newActions);
    else if (oldActions && !newActions) oldActions.remove();
    const oldComposer = chat.querySelector('.fu-message-composer'), newComposer = doc.querySelector('.fu-message-composer');
    const oldReadonly = chat.querySelector('.fu-message-readonly'), newReadonly = doc.querySelector('.fu-message-readonly');
    if (newComposer) {
      oldReadonly?.remove();
      if (!oldComposer) chat.append(newComposer);
      else { oldComposer.hidden = false; oldComposer.querySelectorAll('textarea,button').forEach(n => n.disabled = false); }
    } else {
      // Keep the local draft in memory/DOM if the conversation becomes blocked, never submit it.
      if (oldComposer) { oldComposer.hidden = true; oldComposer.querySelectorAll('textarea,button').forEach(n => n.disabled = true); }
      if (newReadonly && oldReadonly?.textContent !== newReadonly.textContent) { oldReadonly?.remove(); chat.append(newReadonly); }
    }
    chat.querySelectorAll('input[name=revision]').forEach(input => input.value = String(doc.querySelector('[data-revision]')?.dataset.revision || newHeader?.dataset.revision || input.value));
  }
  async function refreshChat(myEpoch) {
    if (!thread) return;
    const result = await read(endpointFor({ part: 'chat', thread, after: sequence }));
    if (myEpoch !== epoch || stopped) return;
    const doc = fragment(result.html), a = anchor();
    if (!fresh && historyRevision !== null && historyRevision !== result.history_revision && !rebuilding) {
      // A decision/moderation revision is distinct from message appends. Reconcile retained bodies too.
      rebuilding = true; sequence = 0; readAnchor = a; chat.querySelector('.fu-message-log')?.replaceChildren();
      historyRevision = result.history_revision; chatDue = 0; return;
    }
    historyRevision = result.history_revision;
    if (fresh || !chat.querySelector('.fu-message-log')) {
      const back = chat.querySelector('.fu-message-return'); chat.replaceChildren(...(back ? [back] : []), doc); fresh = false;
    } else {
      controls(doc);
      const log = chat.querySelector('.fu-message-log');
      doc.querySelectorAll('[data-sequence]').forEach(node => {
        const current = log.querySelector(`[data-sequence="${node.dataset.sequence}"]`);
        if (!current) log.append(node);
      });
    }
    sequence = readSequence();
    // The live cursor replaces only the native next-page link. Native no-JS pagination remains intact.
    chat.querySelectorAll('.fu-message-scroll>a').forEach(a => a.remove());
    restore(rebuilding ? readAnchor : a);
    if (result.next_after !== null) chatDue = 0;
    else { rebuilding = false; readAnchor = null; chatDue = Date.now() + 6000; }
  }
  async function refreshList(myEpoch) {
    const result = await read(endpointFor({ part: 'list', cursor }));
    if (myEpoch !== epoch || stopped) return;
    const doc = fragment(result.html), position = list.scrollTop;
    doc.querySelectorAll('[data-thread]').forEach(node => {
      const id = node.dataset.thread; seen.add(id);
      if (id === thread) node.setAttribute('aria-current', 'true');
      const old = list.querySelector(`[data-thread="${id}"]`);
      if (!old) list.append(node);
      else if (old.innerHTML !== node.innerHTML) { const focus = old === document.activeElement; old.replaceWith(node); if (focus) node.focus({ preventScroll: true }); }
    });
    list.querySelectorAll(':scope>.fu-link,.fu-message-list-empty').forEach(n => n.remove());
    cursor = result.next_cursor || '';
    if (!cursor) {
      list.querySelectorAll('[data-thread]').forEach(n => { if (!seen.has(n.dataset.thread)) n.remove(); });
      if (!seen.size) list.append(fragment('<p class="fu-message-list-empty">Aucune conversation pour le moment.</p>'));
      seen = new Set(); listDue = Date.now() + 20000;
    } else listDue = Date.now() + 250;
    list.scrollTop = position;
  }
  async function submit(form) {
    const fields = new FormData(form), action = fields.get('message_action');
    let path, data;
    if (action === 'send') { path = `messages/${fields.get('thread_id')}/send`; data = { body: fields.get('body'), key: fields.get('key') }; }
    else if (action === 'request') { path = 'messages/requests'; data = { creator_id: fields.get('creator_id'), body: fields.get('body'), key: fields.get('key') }; }
    else { path = `messages/${fields.get('thread_id')}/decision`; data = { revision: Number(fields.get('revision')), action: fields.get('decision') }; }
    form.querySelectorAll('button').forEach(n => n.disabled = true);
    try {
      const result = await read(new URL(path, apiBase), data);
      if (stopped) return;
      if (action !== 'decision') {
        // Do not erase text typed while the request was in flight; a retry keeps its original key on failure.
        const field = form.querySelector('textarea'); if (field.value === data.body) field.value = '';
        form.querySelector('[name=key]').value = crypto.randomUUID();
      }
      notice('');
      if (action === 'request') openThread(result.thread_id, true);
      chatDue = 0; listDue = 0;
    } catch (error) {
      notice(error.status === 429 ? 'Limite d’envoi atteinte. Votre texte est conservé.' : error.status === 409 ? 'Cet échange a changé. Relisez son état avant de réessayer.' : 'Envoi non confirmé. Votre texte est conservé ; réessayez après reconnexion.');
      chatDue = 0;
    } finally { form.querySelectorAll('button').forEach(n => n.disabled = false); }
  }
  async function tick() {
    clearTimeout(timer);
    if (stopped || busy || document.hidden || !navigator.onLine) return;
    busy = true; const myEpoch = epoch;
    try {
      if (pendingForm) { const form = pendingForm; pendingForm = null; await submit(form); }
      else if (thread && Date.now() >= chatDue) await refreshChat(myEpoch);
      else if (Date.now() >= listDue) await refreshList(myEpoch);
      if (failures) notice(''); failures = 0;
    } catch (error) {
      if (myEpoch !== epoch || stopped) return;
      if ([404, 410].includes(error.status) && thread) { const back = chat.querySelector('.fu-message-return'); chat.replaceChildren(...(back ? [back] : []), fragment('<div class="fu-message-empty"><h2>Conversation indisponible</h2><p>Elle a expiré ou vous n’y avez plus accès.</p></div>')); thread = ''; }
      failures = Math.min(failures + 1, 5); notice('Actualisation interrompue. Nouvelle tentative automatique.');
    } finally {
      busy = false;
      if (!stopped && !document.hidden && navigator.onLine) timer = setTimeout(tick, failures ? Math.min(60000, 2000 * 2 ** failures) : pendingForm ? 0 : Math.max(250, Math.min(thread ? chatDue - Date.now() : Infinity, listDue - Date.now())));
    }
  }
  function openThread(id, replace = false, fromHistory = false) {
    if (!fromHistory) rememberPosition(); epoch++; controller?.abort(); thread = id; sequence = 0; historyRevision = null; fresh = !!id; rebuilding = false;
    layout.classList.toggle('has-selection', !!id);
    list.querySelectorAll('[data-thread]').forEach(n => { if (n.dataset.thread === id) n.setAttribute('aria-current', 'true'); else n.removeAttribute('aria-current'); });
    if (!fromHistory) history[replace ? 'replaceState' : 'pushState']({ fansListScroll: listPosition }, '', urlFor(id));
    if (id) {
      chat.replaceChildren(fragment(`<a class="fu-message-return" href="${location.pathname}">← Conversations</a><div class="fu-message-empty"><p>Chargement de l’échange…</p></div>`));
      chat.querySelector('.fu-message-return')?.focus({ preventScroll: true });
    } else { chat.replaceChildren(fragment('<div class="fu-message-empty"><span aria-hidden="true">✉</span><h2>Vos conversations, ici</h2><p>Sélectionnez un échange pour le lire.</p></div>')); list.scrollTop = history.state?.fansListScroll ?? listPosition; list.focus({ preventScroll: true }); }
    chatDue = 0; listDue = 0; tick(); resize();
  }
  root.addEventListener('click', event => {
    const link = event.target.closest('a'); if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button) return;
    if (link.matches('[data-thread]')) { event.preventDefault(); openThread(link.dataset.thread); }
    else if (link.matches('.fu-message-return')) { event.preventDefault(); openThread(''); }
  });
  root.addEventListener('submit', event => {
    const form = event.target, action = form.querySelector('[name=message_action]')?.value;
    if (!['send', 'request', 'decision'].includes(action)) return; // Report/appeal fallback forms remain native.
    if (!form.checkValidity() || !form.querySelector('[name=fans_messages_nonce]')) return;
    event.preventDefault();
    if (pendingForm || form.querySelector('button:disabled')) return;
    if (!navigator.onLine) { notice('Hors connexion. Votre texte est conservé.'); return; }
    pendingForm = form; tick();
  });
  window.addEventListener('popstate', () => openThread(new URL(location.href).searchParams.get('thread') || '', false, true));
  const resume = () => { clearTimeout(timer); if (document.hidden || !navigator.onLine) { controller?.abort(); return; } failures = 0; notice(''); chatDue = listDue = 0; tick(); };
  document.addEventListener('visibilitychange', resume); window.addEventListener('online', resume); window.addEventListener('offline', () => { resume(); notice('Hors connexion. Vos nouveaux textes restent ici jusqu’à l’envoi.'); });
  document.addEventListener('fans-session-ended', () => { stopped = true; pendingForm = null; clearTimeout(timer); controller?.abort(); seen.clear(); root.replaceChildren(); });
  window.addEventListener('pagehide', () => { stopped = true; clearTimeout(timer); controller?.abort(); });
  tick();
})();
