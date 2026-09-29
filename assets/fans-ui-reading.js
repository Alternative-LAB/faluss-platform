(() => {
  'use strict';
  const privateView = document.querySelector('[data-fans-private-reading]');
  if (privateView) {
    window.addEventListener('pagehide', () => privateView.replaceChildren());
    window.addEventListener('pageshow', (event) => { if (event.persisted) window.location.reload(); });
  }
  const root = document.querySelector('[data-fans-publications]');
  if (!root) return;
  const results = root.querySelector('[data-text-results]');
  const status = root.querySelector('[data-text-status]');
  const next = root.querySelector('[data-text-next]');
  const refresh = root.querySelector('[data-text-refresh]');
  const id = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
  let cursor = null;
  let controller = null;
  let generation = 0;
  const node = (tag, text, className = '') => {
    const item = document.createElement(tag);
    item.textContent = text;
    item.className = className;
    return item;
  };
  const valid = (item) => item && id.test(item.publication_id) && id.test(item.creator_id)
    && Number.isSafeInteger(item.revision) && item.revision > 0
    && typeof item.body === 'string' && item.body.length > 0 && item.body.length <= 16000
    && typeof item.updated_at === 'string';
  function reset() {
    controller?.abort();
    generation += 1;
    results.replaceChildren();
    cursor = null;
    next.hidden = true;
  }
  async function load(after = null) {
    reset();
    const current = generation;
    controller = new AbortController();
    const { signal } = controller;
    status.textContent = 'Chargement des publications…';
    next.disabled = true;
    const url = new URL(root.dataset.api, location.href);
    url.searchParams.set('per_page', '6');
    if (after !== null) url.searchParams.set('cursor', after);
    try {
      const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', signal });
      if (!response.ok) throw new Error('unavailable');
      const page = await response.json();
      if (signal.aborted || current !== generation) return;
      if (!page || !Array.isArray(page.items) || page.items.length > 6 || !page.items.every(valid)
        || !(page.next_cursor === null || (typeof page.next_cursor === 'string' && page.next_cursor.length > 0 && page.next_cursor.length <= 512))
        || (page.next_cursor !== null && (page.items.length === 0 || page.next_cursor === after))) throw new Error('invalid');
      const fragment = document.createDocumentFragment();
      for (const item of page.items) {
        const card = node('article', '', 'fu-panel fu-text-card');
        card.append(node('p', 'Texte approuvé', 'fu-panel__kicker'));
        card.append(node('p', item.body, 'fu-text-body'));
        const link = node('a', 'Voir la fiche de l’auteur ↗', 'fu-link');
        link.href = root.dataset.publicBase + encodeURIComponent(item.creator_id);
        card.append(link);
        fragment.append(card);
      }
      results.replaceChildren(fragment);
      cursor = page.next_cursor;
      next.hidden = cursor === null;
      next.disabled = false;
      status.textContent = page.items.length === 0 ? 'Aucune publication publique disponible.' : 'Publications chargées. Les textes restent soumis à la modération.';
    } catch (error) {
      if (signal.aborted || current !== generation) return;
      results.replaceChildren();
      status.textContent = 'Publications indisponibles. Le service est fermé ou ne répond pas. Vous pouvez réessayer.';
    }
  }
  next.addEventListener('click', () => { if (cursor !== null) load(cursor); });
  refresh.addEventListener('click', () => load());
  window.addEventListener('pagehide', reset);
  window.addEventListener('pageshow', (event) => { if (event.persisted) load(); });
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) { reset(); status.textContent = 'Lecture suspendue.'; } else load();
  });
  load();
})();
