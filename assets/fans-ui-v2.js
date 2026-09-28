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

  function creatorCard(item, base) {
    const card = element('article', 'fu-card');
    card.append(element('div', 'fu-card__art'));
    const body = element('div', 'fu-card__body');
    body.append(element('p', 'fu-panel__kicker', categories[item.category]));
    body.append(element('h3', '', 'Profil créateur'));
    body.append(element('p', 'fu-card__id', item.creator_id));
    const link = element('a', 'fu-card__link', 'Voir le profil ↗');
    link.href = base + encodeURIComponent(item.creator_id);
    link.setAttribute('aria-label', `Voir le profil créateur ${item.creator_id}`);
    body.append(link);
    card.append(body);
    return card;
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
      response.data.forEach((item) => fragment.append(creatorCard(item, base)));
      target.append(fragment);
      status(explorer, `${response.data.length} profil${response.data.length > 1 ? 's' : ''} publié${response.data.length > 1 ? 's' : ''}.`);
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
      card.append(element('div', 'fu-profile__glyph', 'F'));
      const content = element('div');
      content.append(element('p', 'fu-panel__kicker', categories[response.data.category]));
      content.append(element('h3', '', 'Profil créateur'));
      content.append(element('p', '', 'Ce créateur dispose d’un profil public sur Fans.'));
      content.append(element('p', 'fu-profile__id', response.data.creator_id));
      card.append(content);
      target.append(card);
      status(profile, 'Profil public chargé.');
    } catch (error) {
      if (signal.aborted || current !== revision) return;
      target.replaceChildren();
      status(profile, 'Ce profil est indisponible pour le moment.');
      showState(profile, 'Profil indisponible', 'Réessayez plus tard.');
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
  window.addEventListener('pageshow', (event) => { if (event.persisted) refresh(); });
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
  refresh();
})();
