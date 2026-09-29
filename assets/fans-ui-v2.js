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
        output.removeAttribute('aria-hidden'); output.textContent = 'Portrait indisponible';
      }
    } finally {
      if (objectUrl) { URL.revokeObjectURL(objectUrl); portraits.delete(objectUrl); }
    }
  }

  function creatorCard(item, base) {
    const card = element('article', 'fu-card');
    const art = element('div', 'fu-card__art'); card.append(art);
    const data = editorial(item);
    const body = element('div', 'fu-card__body');
    body.append(element('p', 'fu-panel__kicker', categories[item.category]));
    body.append(element('h3', '', data?.public_name || 'Profil sans nom public'));
    body.append(element('p', 'fu-card__bio', data ? (data.bio || 'Aucune bio renseignée.') : 'Présentation approuvée indisponible.'));
    const link = element('a', 'fu-card__link', 'Consulter la fiche ↗');
    link.href = base + encodeURIComponent(item.creator_id);
    link.setAttribute('aria-label', `Consulter la fiche publique, catégorie ${categories[item.category]}`);
    body.append(link);
    card.append(body);
    return { card, art };
  }

  async function loadExplorer() {
    const { signal, revision: current } = begin();
    const target = results(explorer);
    target.replaceChildren();
    status(explorer, 'Chargement des profils…');
    const active = explorer.querySelector('[data-category][aria-pressed="true"]');
    const category = active?.dataset.category || '';
    const url = new URL(explorer.dataset.api, window.location.href);
    if (category) url.searchParams.set('category', category);
    try {
      const response = await get(url, signal);
      if (signal.aborted || current !== revision) return;
      if (!Array.isArray(response.data) || response.data.length > 20 || !response.data.every(validProfile)) {
        throw new Error('invalid_response');
      }
      if (response.data.length === 0) {
        status(explorer, 'Aucun profil créateur publié dans cette catégorie.');
        showState(explorer, 'Aucun profil publié', 'Vous pouvez choisir une autre catégorie ou revenir plus tard.');
        return;
      }
      const base = explorer.dataset.publicBase;
      const fragment = document.createDocumentFragment();
      const pendingPortraits = response.data.map((item) => {
        const { card, art } = creatorCard(item, base); fragment.append(card); return { item, art };
      });
      target.append(fragment);
      pendingPortraits.forEach(({ item, art }) => portrait(item, art, signal, current));
      const complete = response.data.filter(item => editorial(item)).length;
      const plural = response.data.length > 1 ? 's' : '';
      status(explorer, complete === 0
        ? `${response.data.length} fiche${plural} publique${plural} structurée${plural}. Découverte en préparation.`
        : `${response.data.length} fiche${plural} publique${plural}, dont ${complete} présentation${complete > 1 ? 's' : ''} approuvée${complete > 1 ? 's' : ''}.`);
    } catch (error) {
      if (signal.aborted || current !== revision) return;
      target.replaceChildren();
      status(explorer, 'Les profils sont indisponibles pour le moment. Réessayez plus tard.');
      showState(explorer, 'Découverte indisponible', 'Les profils ne peuvent pas être chargés actuellement.');
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
      const card = element('article', 'fu-panel fu-profile');
      const art = element('div', 'fu-profile__glyph');
      art.setAttribute('aria-hidden', 'true');
      card.append(art);
      const content = element('div');
      content.append(element('p', 'fu-panel__kicker', categories[response.data.category]));
      const data = editorial(response.data);
      content.append(element('h3', '', data?.public_name || 'Profil sans nom public'));
      content.append(element('p', 'fu-profile__bio', data ? (data.bio || 'Aucune bio renseignée.') : 'Présentation approuvée indisponible.'));
      const follows = element('p', 'fu-live', 'Chargement du nombre de suivis…');
      follows.dataset.fansFollowCount = '';
      follows.setAttribute('role', 'status');
      content.append(follows);
      card.append(content);
      target.append(card);
      portrait(response.data, art, signal, current);
      status(profile, 'Profil public chargé.');
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
      target.textContent = `Suivis enregistrés : ${data.count.toLocaleString('fr-FR')}`;
    } catch (error) {
      if (signal.aborted || current !== revision || !target.isConnected) return;
      target.textContent = 'Nombre de suivis indisponible.';
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
    status(root, 'Lecture interrompue. Actualisation au retour sur la page.');
  };
  window.addEventListener('pagehide', clear);
  window.addEventListener('pageshow', (event) => { if (event.persisted) refresh(); });
  document.addEventListener('visibilitychange', () => { if (document.hidden) clear(); else refresh(); });
  refresh();
})();
