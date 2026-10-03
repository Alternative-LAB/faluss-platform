(() => {
  'use strict';

  const categories = Object.freeze({
    arts: 'Arts', music: 'Musique', games: 'Jeux',
    learning: 'Savoirs', lifestyle: 'Art de vivre'
  });
  const idPattern = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
  const explorer = document.querySelector('[data-fans-explorer]');
  const profile = document.querySelector('[data-fans-profile]');
  if (!explorer && !profile) return;

  let controller = null;
  let revision = 0;
  const portraits = new Set();
  const observers = new Set();
  const releasePortraits = () => { portraits.forEach(url => URL.revokeObjectURL(url)); portraits.clear(); };
  const editorial = (item) => {
    const value = item.editorial;
    return value && typeof value.public_name === 'string' && value.public_name.trim().length > 0
      && [...value.public_name].length <= 80 && typeof value.bio === 'string' && [...value.bio].length <= 1000
      && Number.isSafeInteger(value.revision) && value.revision > 0 && typeof value.portrait === 'boolean' ? value : null;
  };
  const element = (tag, className, value) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (value !== undefined) node.textContent = value;
    return node;
  };
  const validProfile = (item) => item && typeof item === 'object' && !Array.isArray(item)
    && typeof item.creator_id === 'string' && idPattern.test(item.creator_id)
    && Object.hasOwn(categories, item.category) && item.status === 'active'
    && item.identity_verified === false;
  const status = (root, message) => { root.querySelector('[data-fans-status]').textContent = message; };
  const results = (root) => root.querySelector('[data-fans-results]');
  const showState = (root, title, detail) => {
    const box = element('div', 'fu-panel fu-empty');
    box.append(element('span', 'fu-empty__mark', '◌'));
    const copy = element('div');
    copy.append(element('h3', '', title));
    copy.append(element('p', '', detail));
    box.append(copy);
    results(root).replaceChildren(box);
  };
  const begin = () => {
    if (controller) controller.abort();
    releasePortraits();
    observers.forEach(observer => observer.disconnect()); observers.clear();
    if (explorer) explorer.querySelector('[data-fans-hero]').replaceChildren();
    controller = new AbortController();
    revision += 1;
    return { signal: controller.signal, revision };
  };
  const get = async (url, signal) => {
    const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', signal });
    if (response.status === 404) return { missing: true };
    if (!response.ok) throw new Error('unavailable');
    return { data: await response.json() };
  };

  async function portrait(item, output, signal, current) {
    const data = editorial(item);
    if (!data?.portrait) return;
    let objectUrl = null;
    try {
      const root = explorer || profile;
      const url = new URL(`${root.dataset.api.replace(/\/$/, '')}/${item.creator_id}/portrait/${data.revision}`, location.href);
      if (url.origin !== location.origin || url.search || url.hash) throw new Error('invalid');
      const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal });
      if (!response.ok || response.headers.get('Content-Type')?.split(';')[0].trim() !== 'image/jpeg'
          || !response.body || Number(response.headers.get('Content-Length')) > 2097152) throw new Error('unavailable');
      const reader = response.body.getReader(); const chunks = []; let size = 0;
      try {
        while (true) {
          const part = await reader.read(); if (part.done) break;
          size += part.value.byteLength;
          if (size > 2097152 || signal.aborted || current !== revision) throw new Error('invalid');
          chunks.push(part.value);
        }
      } finally { await reader.cancel(); }
      if (signal.aborted || current !== revision || !output.isConnected) return;
      objectUrl = URL.createObjectURL(new Blob(chunks, { type: 'image/jpeg' })); portraits.add(objectUrl);
      const img = element('img'); img.alt = `Portrait public de ${data.public_name}`; img.src = objectUrl;
      await img.decode();
      if (signal.aborted || current !== revision || !output.isConnected) return;
      if (img.naturalWidth > 1280 || img.naturalHeight > 1280) throw new Error('invalid');
      output.removeAttribute('aria-hidden'); output.classList.add('has-portrait'); output.replaceChildren(img);
      objectUrl = null;
    } catch (error) {
      if (!signal.aborted && current === revision && output.isConnected) {
        output.setAttribute('aria-hidden', 'true');
      }
    } finally {
      if (objectUrl) { URL.revokeObjectURL(objectUrl); portraits.delete(objectUrl); }
    }
  }

  function creatorCard(item, base) {
    const data = editorial(item);
    const card = element('article', 'fu-discovery-card');
    const art = element('div', 'fu-discovery-card__art'); art.setAttribute('aria-hidden', 'true');
    const body = element('div', 'fu-discovery-card__body');
    body.append(element('p', 'fu-panel__kicker', categories[item.category]));
    body.append(element('h3', '', data.public_name));
    if (data.bio) body.append(element('p', 'fu-discovery-card__bio', data.bio));
    const link = element('a', 'fu-link', 'Voir le profil ↗');
    link.href = base + encodeURIComponent(item.creator_id);
    link.setAttribute('aria-label', `Voir le profil de ${data.public_name}`);
    body.append(link); card.append(art, body);
    return { card, art };
  }

  function hero(items, signal, current) {
    const selected = items.slice(0, 3);
    // Provisional recipe rule, replaceable independently of cards/API: arrival order; repeat the sole creator.
    // See docs/evidence/fans-discovery/README.md; this is not a lasting editorial or commercial policy.
    if (selected.length === 1) selected.push(selected[0], selected[0]);
    const root = explorer.querySelector('[data-fans-hero]');
    const region = element('section', 'fu-discovery-hero');
    region.setAttribute('aria-label', 'Créateurs à découvrir');
    region.setAttribute('aria-roledescription', 'carrousel');
    const slides = element('div', 'fu-discovery-hero__slides');
    const controls = element('div', 'fu-discovery-hero__controls');
    const previous = element('button', '', '←'); previous.type = 'button'; previous.setAttribute('aria-label', 'Créateur précédent');
    const next = element('button', '', '→'); next.type = 'button'; next.setAttribute('aria-label', 'Créateur suivant');
    const announce = element('span', 'fu-discovery__sr'); announce.setAttribute('aria-live', 'polite');
    const dots = element('div', 'fu-discovery-hero__dots');
    let active = 0;
    const entries = selected.map((item, index) => {
      const data = editorial(item);
      const slide = element('article', 'fu-discovery-hero__slide');
      slide.setAttribute('aria-label', `${index + 1} sur ${selected.length}`);
      slide.setAttribute('aria-roledescription', 'diapositive');
      slide.id = `fu-discovery-slide-${index}`;
      const art = element('div', 'fu-discovery-hero__art'); art.setAttribute('aria-hidden', 'true');
      const copy = element('div', 'fu-discovery-hero__copy');
      copy.append(element('p', 'fu-panel__kicker', categories[item.category]), element('h2', '', data.public_name));
      if (data.bio) copy.append(element('p', 'fu-discovery-hero__bio', data.bio));
      const link = element('a', 'fu-discovery-hero__link', 'Découvrir son univers ↗');
      link.href = explorer.dataset.publicBase + encodeURIComponent(item.creator_id);
      link.setAttribute('aria-label', `Découvrir le profil de ${data.public_name}`);
      copy.append(link); slide.append(art, copy); slides.append(slide);
      const dot = element('button', ''); dot.type = 'button';
      dot.setAttribute('aria-label', `Diapositive ${index + 1} : ${data.public_name}`);
      dot.setAttribute('aria-controls', slide.id); dot.addEventListener('click', () => show(index)); dots.append(dot);
      return { slide, art, dot, item, loaded: false };
    });
    function show(index, notify = true) {
      active = (index + entries.length) % entries.length;
      entries.forEach((entry, position) => { entry.slide.hidden = position !== active; entry.dot.setAttribute('aria-pressed', String(position === active)); });
      const entry = entries[active];
      if (!entry.loaded) { entry.loaded = true; portrait(entry.item, entry.art, signal, current); }
      if (notify) announce.textContent = `Diapositive ${active + 1} sur ${entries.length}, ${editorial(entry.item).public_name}`;
    }
    previous.addEventListener('click', () => show(active - 1)); next.addEventListener('click', () => show(active + 1));
    controls.append(previous, dots, next, announce); region.append(slides, controls); root.append(region); show(0, false);
  }

  function categoryRow(category, items, filtered, signal, current) {
    const section = element('section', 'fu-discovery-row');
    const heading = element('div', 'fu-discovery-row__heading');
    const title = element('h2', '', categories[category]); title.id = `fu-discovery-title-${category}`;
    const track = element('div', 'fu-discovery-row__track' + (filtered ? ' fu-discovery-row__track--all' : '')); track.id = `fu-discovery-row-${category}`;
    track.setAttribute('role', 'list'); track.setAttribute('aria-labelledby', title.id); track.tabIndex = 0;
    const actions = element('div', 'fu-discovery-row__actions');
    if (!filtered && items.length > 10) {
      const all = element('button', 'fu-link', 'Voir tous'); all.type = 'button';
      all.setAttribute('aria-label', `Voir tous les créateurs : ${categories[category]}`);
      all.addEventListener('click', () => { const filter = explorer.querySelector(`[data-category="${category}"]`); filter.focus(); filter.click(); });
      actions.append(all);
    }
    const previous = element('button', '', '←'); previous.type = 'button'; previous.setAttribute('aria-label', `Précédents : ${categories[category]}`);
    const next = element('button', '', '→'); next.type = 'button'; next.setAttribute('aria-label', `Suivants : ${categories[category]}`);
    for (const button of [previous, next]) button.setAttribute('aria-controls', track.id);
    const update = () => {
      previous.setAttribute('aria-disabled', String(track.scrollLeft < 2));
      next.setAttribute('aria-disabled', String(track.scrollLeft + track.clientWidth >= track.scrollWidth - 2));
    };
    const scroll = direction => track.scrollBy({ left: direction * track.clientWidth * .8, behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth' });
    previous.addEventListener('click', () => { if (previous.getAttribute('aria-disabled') !== 'true') scroll(-1); });
    next.addEventListener('click', () => { if (next.getAttribute('aria-disabled') !== 'true') scroll(1); });
    track.addEventListener('keydown', event => {
      if (event.target !== track || !['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
      event.preventDefault(); scroll(event.key === 'ArrowLeft' ? -1 : 1);
    });
    track.addEventListener('scroll', update, { passive: true });
    const pending = [];
    for (const item of items.slice(0, filtered ? 20 : 10)) {
      const { card, art } = creatorCard(item, explorer.dataset.publicBase); card.setAttribute('role', 'listitem');
      track.append(card); pending.push({ item, art });
    }
    actions.append(previous, next); heading.append(title, actions); section.append(heading, track); results(explorer).append(section);
    const resize = new ResizeObserver(update); resize.observe(track); observers.add(resize); update();
    // Off-screen portraits are not requested until their cards enter the viewport.
    const lazy = new IntersectionObserver(entries => {
      for (const entry of entries) if (entry.isIntersecting) {
        lazy.unobserve(entry.target); const job = pending.find(job => job.art === entry.target);
        if (job) portrait(job.item, job.art, signal, current);
      }
    });
    pending.forEach(job => lazy.observe(job.art)); observers.add(lazy);
  }

  async function loadExplorer() {
    const { signal, revision: current } = begin();
    const target = results(explorer); target.replaceChildren();
    status(explorer, 'Chargement des créateurs…');
    const category = explorer.querySelector('[data-category][aria-pressed="true"]')?.dataset.category || '';
    try {
      // The unchanged API has a per-category limit; querying each category avoids hiding a populated universe.
      const groups = await Promise.all((category ? [category] : Object.keys(categories)).map(async key => {
        const url = new URL(explorer.dataset.api, location.href); url.searchParams.set('category', key);
        const response = await get(url, signal);
        if (!Array.isArray(response.data) || response.data.length > 20 || !response.data.every(item => validProfile(item)
          && item.category === key && typeof item.created_at === 'string' && /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(item.created_at))) throw new Error('invalid_response');
        return [key, response.data.filter(item => editorial(item))];
      }));
      if (signal.aborted || current !== revision) return;
      const byArrival = (a, b) => a.created_at.localeCompare(b.created_at) || a.creator_id.localeCompare(b.creator_id);
      const all = groups.flatMap(([, items]) => items).sort(byArrival);
      if (!all.length) {
        status(explorer, ''); showState(explorer, 'De nouveaux univers se préparent', 'Explorez une autre catégorie ou retrouvez-nous bientôt.'); return;
      }
      hero(all, signal, current);
      for (const [key, items] of groups) if (items.length) categoryRow(key, items.sort(byArrival), Boolean(category), signal, current);
      status(explorer, '');
    } catch (error) {
      if (signal.aborted || current !== revision) return;
      target.replaceChildren(); status(explorer, '');
      showState(explorer, 'La découverte fait une pause', 'Les créateurs seront bientôt de retour. Réessayez dans un instant.');
    }
  }

  async function loadProfile() {
    const { signal, revision: current } = begin();
    const target = results(profile);
    target.replaceChildren();
    status(profile, 'Chargement du profil…');
    const id = profile.dataset.creatorId;
    if (!idPattern.test(id)) {
      status(profile, 'Profil introuvable.');
      showState(profile, 'Profil introuvable', 'Ce lien ne correspond à aucun profil public.');
      return;
    }
    try {
      const response = await get(`${profile.dataset.api.replace(/\/$/, '')}/${encodeURIComponent(id)}`, signal);
      if (signal.aborted || current !== revision) return;
      if (response.missing) {
        status(profile, 'Ce profil n’est plus public ou n’existe pas.');
        showState(profile, 'Profil non disponible', 'Il a pu être retiré ou suspendu.');
        return;
      }
      if (!validProfile(response.data) || response.data.creator_id !== id) throw new Error('invalid_response');
      const card = element('article', 'fu-public-creator__identity');
      const cover = element('div', 'fu-public-creator__cover'); cover.setAttribute('aria-hidden', 'true'); target.append(cover);
      const art = element('div', 'fu-public-creator__portrait');
      art.setAttribute('aria-hidden', 'true');
      card.append(art);
      const content = element('div', 'fu-public-creator__copy');
      content.append(element('p', 'fu-panel__kicker', categories[response.data.category]));
      const data = editorial(response.data);
      content.append(element('h2', '', data?.public_name || 'Créateur'));
      if (data?.bio) content.append(element('p', 'fu-profile__bio', data.bio));
      const follows = element('p', 'fu-public-creator__followers', '');
      follows.dataset.fansFollowCount = '';
      follows.setAttribute('role', 'status');
      content.append(follows);
      card.append(content);
      target.append(card);
      portrait(response.data, art, signal, current);
      status(profile, '');
      loadFollowCount(id, follows, signal, current);
    } catch (error) {
      if (signal.aborted || current !== revision) return;
      target.replaceChildren();
      status(profile, 'Ce profil est indisponible pour le moment.');
      showState(profile, 'Profil indisponible', 'Réessayez plus tard.');
    }
  }

  async function loadFollowCount(id, target, signal, current) {
    try {
      const response = await get(`${profile.dataset.api.replace(/\/$/, '')}/${encodeURIComponent(id)}/followers/count`, signal);
      if (signal.aborted || current !== revision || !target.isConnected) return;
      const data = response.data;
      if (!data || data.creator_id !== id || !Number.isSafeInteger(data.count) || data.count < 0) {
        throw new Error('invalid_count');
      }
      target.textContent = `${data.count.toLocaleString('fr-FR')} ${data.count === 1 ? 'suivi' : 'suivis'}`;
    } catch (error) {
      if (signal.aborted || current !== revision || !target.isConnected) return;
      target.textContent = '';
    }
  }

  if (explorer) {
    explorer.querySelectorAll('[data-category]').forEach((button) => {
      button.addEventListener('click', () => {
        explorer.querySelectorAll('[data-category]').forEach((item) => {
          item.setAttribute('aria-pressed', item === button ? 'true' : 'false');
        });
        loadExplorer();
      });
    });
  }
  const refresh = () => { if (explorer) loadExplorer(); else loadProfile(); };
  const clear = () => {
    if (controller) controller.abort();
    releasePortraits();
    revision += 1;
    const root = explorer || profile;
    results(root).replaceChildren();
    observers.forEach(observer => observer.disconnect()); observers.clear();
    if (explorer) explorer.querySelector('[data-fans-hero]').replaceChildren();
    status(root, '');
  };
  window.addEventListener('pagehide', clear);
  window.addEventListener('pageshow', (event) => { if (event.persisted) refresh(); });
  document.addEventListener('visibilitychange', () => { if (document.hidden) clear(); else refresh(); });
  refresh();
})();
