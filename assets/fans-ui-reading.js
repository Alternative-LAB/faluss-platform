(() => {
  'use strict';
  const privateViews = [...document.querySelectorAll('[data-fans-private-reading]')];
  if (privateViews.length) {
    window.addEventListener('pagehide', () => privateViews.forEach(view => view.replaceChildren()));
    window.addEventListener('pageshow', (event) => { if (event.persisted) window.location.reload(); });
  }
  const root = document.querySelector('[data-fans-publications]');
  if (!root) return;
  const results = root.querySelector('[data-text-results]');
  const status = root.querySelector('[data-text-status]');
  const next = root.querySelector('[data-text-next]');
  const refresh = root.querySelector('[data-text-refresh]');
  status.tabIndex = -1;
  const id = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
  const creator = root.dataset.creatorId ?? null;
  const profilePresentation = root.dataset.presentation === 'profile';
  const imageLabel = profilePresentation ? 'Voir l’image' : 'Vérifier l’image associée';
  let cursor = null;
  let controller = null;
  let generation = 0;
  let imageBusy = false;
  const objectUrls = new Set();
  const node = (tag, text, className = '') => {
    const item = document.createElement(tag);
    item.textContent = text;
    item.className = className;
    return item;
  };
  // wpdb may return integer columns as decimal strings in the existing REST projection.
  const validRevision = (value) => (typeof value === 'number' || (typeof value === 'string' && /^[1-9][0-9]{0,9}$/.test(value)))
    && Number.isSafeInteger(Number(value)) && Number(value) > 0 && Number(value) < 2147483647;
  const valid = (item) => item && id.test(item.publication_id) && id.test(item.creator_id)
    && (creator === null || item.creator_id === creator)
    && validRevision(item.revision)
    && typeof item.body === 'string' && item.body.length > 0 && item.body.length <= 16000
    && typeof item.updated_at === 'string'
    && (!profilePresentation || typeof item.has_public_image === 'boolean');
  function reset(keepNext = false) {
    controller?.abort();
    generation += 1;
    imageBusy = false;
    for (const url of objectUrls) URL.revokeObjectURL(url);
    objectUrls.clear();
    results.replaceChildren();
    cursor = null;
    if (!keepNext) next.hidden = true;
  }
  // One derivative at a time. Only the public profile automatically loads explicitly eligible images; no retries.
  function imageControl(card, item, current, signal) {
    const figure = node('figure', '', 'fu-publication-image');
    const button = node('button', imageLabel);
    button.type = 'button';
    button.dataset.imageLoad = '';
    const message = node('p', '', 'fu-footnote');
    message.setAttribute('role', 'status');
    const output = node('div', '', 'fu-publication-image__output');
    let shownUrl = null;
    figure.append(button, message, output);
    card.append(figure);
    const activate = async () => {
      if (imageBusy || signal.aborted || current !== generation) return;
      if (shownUrl !== null) {
        output.replaceChildren();
        URL.revokeObjectURL(shownUrl); objectUrls.delete(shownUrl); shownUrl = null;
        button.textContent = imageLabel;
        message.textContent = 'Image masquée.';
        return;
      }
      imageBusy = true;
      root.querySelectorAll('[data-image-load]').forEach(control => { control.setAttribute('aria-disabled', 'true'); });
      message.textContent = 'Vérification de l’image…';
      let objectUrl = null;
      try {
        const url = new URL(root.dataset.api.replace(/\/$/, '') + '/' + item.publication_id + '/image/' + Number(item.revision), location.href);
        if (url.origin !== location.origin || url.search || url.hash) throw new Error('invalid');
        const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal });
        if (response.status === 404) {
          message.textContent = profilePresentation ? '' : 'Aucune image publique disponible pour cette publication.';
          if (profilePresentation) figure.hidden = true;
          return;
        }
        if (!response.ok || response.headers.get('Content-Type')?.split(';')[0].trim().toLowerCase() !== 'image/jpeg'
          || !response.body || Number(response.headers.get('Content-Length')) > 2097152) throw new Error('unavailable');
        const reader = response.body.getReader();
        const chunks = [];
        let size = 0;
        try {
          while (true) {
            const part = await reader.read();
            if (part.done) break;
            size += part.value.byteLength;
            if (size > 2097152 || signal.aborted || current !== generation) throw new Error('invalid');
            chunks.push(part.value);
          }
        } finally { await reader.cancel(); }
        if (signal.aborted || current !== generation) return;
        objectUrl = URL.createObjectURL(new Blob(chunks, { type: 'image/jpeg' }));
        objectUrls.add(objectUrl);
        const picture = document.createElement('img');
        picture.alt = 'Image associée à cette publication ; description détaillée indisponible.';
        picture.src = objectUrl;
        await picture.decode();
        if (signal.aborted || current !== generation) return;
        if (picture.naturalWidth > 1280 || picture.naturalHeight > 1280) throw new Error('invalid');
        output.replaceChildren(picture);
        message.textContent = profilePresentation ? '' : 'Image de la publication. La description détaillée n’est pas encore fournie.';
        shownUrl = objectUrl;
        button.textContent = 'Masquer l’image';
      } catch (error) {
        if (!signal.aborted && current === generation) message.textContent = 'Image indisponible. Vous pouvez réessayer ou continuer la lecture du texte.';
      } finally {
        if (objectUrl !== null && !output.firstChild) { URL.revokeObjectURL(objectUrl); objectUrls.delete(objectUrl); }
        if (current === generation) {
          imageBusy = false;
          root.querySelectorAll('[data-image-load]').forEach(control => { control.removeAttribute('aria-disabled'); });
        }
      }
    };
    button.addEventListener('click', activate);
    return activate;
  }
  async function load(after = null, control = null) {
    reset(control === next);
    const current = generation;
    controller = new AbortController();
    const { signal } = controller;
    status.textContent = 'Chargement des publications…';
    next.setAttribute('aria-disabled', 'true');
    const focusReading = () => {
      // Only follow an explicit action if the reader has not moved elsewhere.
      if (control !== null && document.activeElement === control) status.focus();
    };
    const url = new URL(root.dataset.api, location.href);
    url.searchParams.set('per_page', '6');
    if (after !== null) url.searchParams.set('cursor', after);
    try {
      if (creator !== null) {
        if (!id.test(creator)) throw new Error('invalid');
        url.searchParams.set('creator_id', creator);
        if (profilePresentation) url.searchParams.set('public_image', '1');
      }
      const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', signal });
      if (!response.ok) throw new Error('unavailable');
      const page = await response.json();
      if (signal.aborted || current !== generation) return;
      if (!page || !Array.isArray(page.items) || page.items.length > 6 || !page.items.every(valid)
        || !(page.next_cursor === null || (typeof page.next_cursor === 'string' && page.next_cursor.length > 0 && page.next_cursor.length <= 512))
        || (page.next_cursor !== null && (page.items.length === 0 || page.next_cursor === after))) throw new Error('invalid');
      const fragment = document.createDocumentFragment();
      const images = [];
      for (const item of page.items) {
        const card = node('article', '', 'fu-panel fu-text-card');
        card.append(node('p', 'Publication', 'fu-panel__kicker'));
        const body = node('p', item.body.length > 420 ? item.body.slice(0, 420) + '…' : item.body, 'fu-text-body');
        card.append(body);
        if (item.body.length > 420) {
          const expand = node('button', 'Lire la suite', 'fu-link');
          expand.type = 'button';
          expand.setAttribute('aria-expanded', 'false');
          expand.addEventListener('click', () => {
            const open = expand.getAttribute('aria-expanded') !== 'true';
            body.textContent = open ? item.body : item.body.slice(0, 420) + '…';
            expand.setAttribute('aria-expanded', String(open));
            expand.textContent = open ? 'Réduire la publication' : 'Lire la suite';
          });
          card.append(expand);
        }
        if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(item.updated_at)) {
          const time = node('time', 'Mise à jour le ' + item.updated_at.slice(0, 10).split('-').reverse().join('/'), 'fu-footnote');
          time.dateTime = item.updated_at.replace(' ', 'T') + 'Z';
          card.append(time);
        }
        if (root.dataset.imageDelivery === 'true' && (!profilePresentation || item.has_public_image)) {
          const activate = imageControl(card, item, current, signal);
          if (profilePresentation) images.push(activate);
        }
        if (creator === null) {
          const link = node('a', 'Voir la fiche de l’auteur ↗', 'fu-link');
          link.href = root.dataset.publicBase + encodeURIComponent(item.creator_id);
          card.append(link);
        }
        fragment.append(card);
      }
      results.replaceChildren(fragment);
      cursor = page.next_cursor;
      status.textContent = profilePresentation
        ? (page.items.length === 0 ? 'Les premières publications arrivent bientôt.' : (control ? 'Publications affichées.' : ''))
        : (page.items.length === 0 ? 'Aucune publication publique disponible.' : 'Publications chargées. Les textes restent soumis à la modération.');
      focusReading();
      next.hidden = cursor === null;
      next.removeAttribute('aria-disabled');
      for (const activate of images) {
        if (signal.aborted || current !== generation) break;
        await activate();
      }
    } catch (error) {
      if (signal.aborted || current !== generation) return;
      results.replaceChildren();
      status.textContent = profilePresentation ? 'Les publications ne sont pas disponibles pour le moment. Réessayez dans un instant.' : 'Publications indisponibles. Le service est fermé ou ne répond pas. Vous pouvez réessayer.';
      focusReading();
      next.hidden = true;
    }
  }
  next.addEventListener('click', () => { if (cursor !== null) load(cursor, next); });
  refresh.addEventListener('click', () => load(null, refresh));
  window.addEventListener('pagehide', () => reset());
  window.addEventListener('pageshow', (event) => { if (event.persisted) load(); });
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) { reset(); status.textContent = 'Lecture suspendue.'; } else load();
  });
  load();
})();
