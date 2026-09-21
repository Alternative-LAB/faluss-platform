(function () {
  'use strict';

  function configuration(component) {
    var localized = window.falussLinkReward || {};
    return {
      url: component.dataset.falussRewardAjaxUrl || localized.url || '',
      nonce: component.dataset.falussRewardNonce || localized.nonce || ''
    };
  }

  function action(component) { return component.querySelector('.faluss-link-reward__action'); }
  function feedback(component) { return component.querySelector('.faluss-link-reward__feedback'); }

  function setFeedback(component, message, kind) {
    var node = feedback(component);
    if (!node) return;
    node.hidden = false;
    node.className = 'faluss-link-reward__feedback faluss-link-reward__feedback--' + kind;
    node.textContent = message;
  }

  function setState(component, state) {
    ['available', 'granted', 'already_claimed', 'rule_unavailable', 'permission_denied', 'subject_unavailable', 'configuration_invalid', 'transient_error', 'error', 'loading'].forEach(function (name) {
      component.classList.remove('faluss-link-reward--' + name);
    });
    component.classList.add('faluss-link-reward--' + state);
    component.dataset.falussRewardState = state;
  }

  function formatAmount(value) { return typeof value === 'number' ? value.toLocaleString() : String(value || ''); }

  function nextAvailability(value) {
    if (!value) return '';
    var date = new Date(value);
    return ' Disponible à nouveau le ' + (isNaN(date.getTime()) ? value : date.toLocaleString()) + '.';
  }

  function updateBalance(component, data) {
    var node = component.querySelector('.faluss-link-reward__balance');
    if (!node || typeof data.balance !== 'number' || !data.unit) return;
    node.textContent = 'Solde : ' + formatAmount(data.balance) + ' ' + data.unit;
  }

  function renderTerminalState(component, data) {
    var state = data && data.state ? data.state : 'transient_error';
    setState(component, state);
    var actionNode = action(component);
    if (actionNode) actionNode.replaceChildren();

    if (state === 'granted' || state === 'already_claimed') {
      var message = state === 'granted'
        ? 'Gain attribué : ' + formatAmount(data.amount) + ' ' + data.unit + '. Solde actualisé : ' + formatAmount(data.balance) + ' ' + data.unit + '.'
        : (component.dataset.falussRewardClaimedLabel || 'Récompense quotidienne déjà réclamée.') + (typeof data.balance === 'number' && data.unit ? ' Solde actuel : ' + formatAmount(data.balance) + ' ' + data.unit + '.' : '');
      setFeedback(component, message + nextAvailability(data.next_available_at), 'success');
      updateBalance(component, data);
      return;
    }
    var messages = {
      rule_unavailable: 'La récompense quotidienne n’est pas disponible actuellement.',
      permission_denied: 'La réclamation n’est pas disponible sur cette carte.',
      subject_unavailable: 'Votre identité Faluss active est nécessaire pour réclamer cette récompense.',
      configuration_invalid: 'La récompense quotidienne n’est pas encore configurée.',
      transient_error: component.dataset.falussRewardErrorLabel || 'La récompense est temporairement indisponible. Réessayez plus tard.'
    };
    setFeedback(component, data.message || messages[state] || component.dataset.falussRewardUnavailableLabel || 'Récompense quotidienne indisponible.', 'unavailable');
  }

  function requestFailure(component, button, message) {
    setState(component, 'error');
    button.disabled = false;
    button.removeAttribute('aria-busy');
    setFeedback(component, message || component.dataset.falussRewardErrorLabel || 'La récompense est temporairement indisponible. Réessayez plus tard.', 'error');
  }

  function requestClaim(component, button) {
    if (button.disabled) return;
    var config = configuration(component);
    if (!window.fetch || !config.url || !config.nonce) {
      requestFailure(component, button, component.dataset.falussRewardErrorLabel);
      return;
    }
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    setState(component, 'loading');
    setFeedback(component, component.dataset.falussRewardLoadingLabel || 'Réclamation en cours…', 'loading');

    var body = 'action=' + encodeURIComponent('faluss_link_daily_reward_claim') + '&nonce=' + encodeURIComponent(config.nonce);
    window.fetch(config.url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'Accept': 'application/json' },
      body: body
    }).then(function (response) {
      return response.text().then(function (text) {
        var payload = null;
        try { payload = JSON.parse(text); } catch (error) { payload = null; }
        return { ok: response.ok, payload: payload };
      });
    }).then(function (result) {
      var response = result.payload;
      var data = response && response.data ? response.data : {};
      var states = ['granted', 'already_claimed', 'rule_unavailable', 'permission_denied', 'subject_unavailable', 'configuration_invalid', 'transient_error'];
      if (!response || !data || states.indexOf(data.state) === -1) {
        requestFailure(component, button, data.message || component.dataset.falussRewardErrorLabel);
        return;
      }
      renderTerminalState(component, data);
    }).catch(function () {
      requestFailure(component, button, component.dataset.falussRewardErrorLabel);
    });
  }

  function initialize(component) {
    if (!component || component.dataset.falussRewardReady === '1') return;
    component.dataset.falussRewardReady = '1';
    component.addEventListener('click', function (event) {
      var button = event.target.closest('[data-faluss-reward-claim]');
      if (!button || !component.contains(button)) return;
      event.preventDefault();
      requestClaim(component, button);
    });
  }

  function boot(root) {
    var scope = root && root.querySelectorAll ? root : document;
    if (scope.matches && scope.matches('[data-faluss-link-reward]')) initialize(scope);
    scope.querySelectorAll('[data-faluss-link-reward]').forEach(initialize);
  }

  function elementorScope(scope) {
    if (scope && scope[0] && scope[0].querySelectorAll) return scope[0];
    return scope && scope.querySelectorAll ? scope : document;
  }

  function bindElementor() {
    if (window.falussLinkRewardElementorBound) {
      boot(document);
      return;
    }
    if (!window.elementorFrontend || !window.elementorFrontend.hooks) return;
    window.falussLinkRewardElementorBound = true;
    window.elementorFrontend.hooks.addAction('frontend/element_ready/faluss_link_daily_reward.default', function (scope) {
      boot(elementorScope(scope));
    });
    boot(document);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { boot(document); bindElementor(); });
  } else {
    boot(document);
    bindElementor();
  }
  if (window.jQuery) window.jQuery(window).on('elementor/frontend/init', bindElementor);
  window.addEventListener('elementor/frontend/init', bindElementor);
}());
