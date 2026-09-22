(function () {
  'use strict';

  var initialized = new WeakSet();
  var scrollLockCount = 0;
  var scrollLockSnapshot = null;

  function motionIsReduced() {
    return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }

  function lockScroll() {
    if (0 === scrollLockCount) {
      scrollLockSnapshot = {
        body: document.body.style.overflow,
        html: document.documentElement.style.overflow,
        overscroll: document.documentElement.style.overscrollBehavior,
        x: window.scrollX || window.pageXOffset || 0,
        y: window.scrollY || window.pageYOffset || 0
      };
      document.body.style.overflow = 'hidden';
      document.documentElement.style.overflow = 'hidden';
      document.documentElement.style.overscrollBehavior = 'none';
    }
    scrollLockCount += 1;
  }

  function unlockScroll() {
    if (0 === scrollLockCount) {
      return;
    }
    scrollLockCount -= 1;
    if (0 === scrollLockCount && scrollLockSnapshot) {
      var snapshot = scrollLockSnapshot;
      document.body.style.overflow = scrollLockSnapshot.body;
      document.documentElement.style.overflow = scrollLockSnapshot.html;
      document.documentElement.style.overscrollBehavior = scrollLockSnapshot.overscroll;
      scrollLockSnapshot = null;
      window.requestAnimationFrame(function () {
        window.scrollTo(snapshot.x, snapshot.y);
      });
    }
  }

  function copyCustomProperties(source, target) {
    var styles = window.getComputedStyle(source);
    for (var index = 0; index < styles.length; index += 1) {
      var property = styles[index];
      if (0 === property.indexOf('--faluss-navigation-')) {
        target.style.setProperty(property, styles.getPropertyValue(property));
      }
    }
  }

  function copyStyles(source, target) {
    if (!source || !target) {
      return;
    }
    var styles = window.getComputedStyle(source);
    [
      'background', 'background-color', 'background-image', 'background-position', 'background-repeat', 'background-size',
      'border', 'border-radius', 'box-shadow', 'color', 'font-family', 'font-size', 'font-style', 'font-weight',
      'letter-spacing', 'line-height', 'margin', 'padding', 'text-align', 'text-decoration', 'text-transform'
    ].forEach(function (property) {
      target.style.setProperty(property, styles.getPropertyValue(property));
    });
  }

  function copyStateStyles(source, target, prefix) {
    if (!source || !target) {
      return;
    }
    var styles = window.getComputedStyle(source);
    [ 'background', 'border', 'border-radius', 'box-shadow', 'color', 'font-family', 'font-size', 'font-style', 'font-weight', 'letter-spacing', 'line-height', 'margin', 'padding', 'text-align', 'text-decoration', 'text-transform' ].forEach(function (property) {
      target.style.setProperty(prefix + '-' + property, styles.getPropertyValue(property));
    });
  }

  function applyPortalStyles(root, portal) {
    copyCustomProperties(root, portal);
    var sidebar = portal.querySelector('[data-faluss-navigation-sidebar]');
    copyStyles(root.querySelector('.faluss-identity-navigation__style-source--sidebar'), sidebar);
    var sidebarSource = root.querySelector('.faluss-identity-navigation__style-source--sidebar');
    if (sidebarSource && sidebar) {
      sidebar.style.setProperty('--faluss-navigation-sidebar-surface', window.getComputedStyle(sidebarSource).getPropertyValue('background'));
      sidebar.style.setProperty('background', 'transparent');
    }
    [ 'nav', 'smart' ].forEach(function (kind) {
      var links = portal.querySelectorAll(kind === 'smart' ? '.faluss-identity-navigation-portal__link--smart' : '.faluss-identity-navigation-portal__link:not(.faluss-identity-navigation-portal__link--smart)');
      [ 'normal', 'hover', 'active' ].forEach(function (state) {
        var source = root.querySelector('.faluss-identity-navigation__style-source--' + kind + '--' + state);
        links.forEach(function (link) {
          if (state === 'normal') {
            copyStyles(source, link);
          } else {
            copyStateStyles(source, link, '--faluss-navigation-' + kind + '-' + state);
          }
        });
      });
    });
  }

  function installStateStyleRules(portal) {
    var style = document.createElement('style');
    style.textContent =
      '.faluss-identity-navigation-portal__link:not(.faluss-identity-navigation-portal__link--smart):hover,.faluss-identity-navigation-portal__link:not(.faluss-identity-navigation-portal__link--smart):focus-visible{' +
      'background:var(--faluss-navigation-nav-hover-background);border:var(--faluss-navigation-nav-hover-border);border-radius:var(--faluss-navigation-nav-hover-border-radius);box-shadow:var(--faluss-navigation-nav-hover-box-shadow);color:var(--faluss-navigation-nav-hover-color);font-family:var(--faluss-navigation-nav-hover-font-family);font-size:var(--faluss-navigation-nav-hover-font-size);font-style:var(--faluss-navigation-nav-hover-font-style);font-weight:var(--faluss-navigation-nav-hover-font-weight);letter-spacing:var(--faluss-navigation-nav-hover-letter-spacing);line-height:var(--faluss-navigation-nav-hover-line-height);margin:var(--faluss-navigation-nav-hover-margin);padding:var(--faluss-navigation-nav-hover-padding);text-align:var(--faluss-navigation-nav-hover-text-align);text-decoration:var(--faluss-navigation-nav-hover-text-decoration);text-transform:var(--faluss-navigation-nav-hover-text-transform)}' +
      '.faluss-identity-navigation-portal__link:not(.faluss-identity-navigation-portal__link--smart):active{background:var(--faluss-navigation-nav-active-background);border:var(--faluss-navigation-nav-active-border);border-radius:var(--faluss-navigation-nav-active-border-radius);box-shadow:var(--faluss-navigation-nav-active-box-shadow);color:var(--faluss-navigation-nav-active-color);font-family:var(--faluss-navigation-nav-active-font-family);font-size:var(--faluss-navigation-nav-active-font-size);font-style:var(--faluss-navigation-nav-active-font-style);font-weight:var(--faluss-navigation-nav-active-font-weight);letter-spacing:var(--faluss-navigation-nav-active-letter-spacing);line-height:var(--faluss-navigation-nav-active-line-height);margin:var(--faluss-navigation-nav-active-margin);padding:var(--faluss-navigation-nav-active-padding);text-align:var(--faluss-navigation-nav-active-text-align);text-decoration:var(--faluss-navigation-nav-active-text-decoration);text-transform:var(--faluss-navigation-nav-active-text-transform)}' +
      '.faluss-identity-navigation-portal__link--smart:hover,.faluss-identity-navigation-portal__link--smart:focus-visible{background:var(--faluss-navigation-smart-hover-background);border:var(--faluss-navigation-smart-hover-border);border-radius:var(--faluss-navigation-smart-hover-border-radius);box-shadow:var(--faluss-navigation-smart-hover-box-shadow);color:var(--faluss-navigation-smart-hover-color);font-family:var(--faluss-navigation-smart-hover-font-family);font-size:var(--faluss-navigation-smart-hover-font-size);font-style:var(--faluss-navigation-smart-hover-font-style);font-weight:var(--faluss-navigation-smart-hover-font-weight);letter-spacing:var(--faluss-navigation-smart-hover-letter-spacing);line-height:var(--faluss-navigation-smart-hover-line-height);margin:var(--faluss-navigation-smart-hover-margin);padding:var(--faluss-navigation-smart-hover-padding);text-align:var(--faluss-navigation-smart-hover-text-align);text-decoration:var(--faluss-navigation-smart-hover-text-decoration);text-transform:var(--faluss-navigation-smart-hover-text-transform)}' +
      '.faluss-identity-navigation-portal__link--smart:active{background:var(--faluss-navigation-smart-active-background);border:var(--faluss-navigation-smart-active-border);border-radius:var(--faluss-navigation-smart-active-border-radius);box-shadow:var(--faluss-navigation-smart-active-box-shadow);color:var(--faluss-navigation-smart-active-color);font-family:var(--faluss-navigation-smart-active-font-family);font-size:var(--faluss-navigation-smart-active-font-size);font-style:var(--faluss-navigation-smart-active-font-style);font-weight:var(--faluss-navigation-smart-active-font-weight);letter-spacing:var(--faluss-navigation-smart-active-letter-spacing);line-height:var(--faluss-navigation-smart-active-line-height);margin:var(--faluss-navigation-smart-active-margin);padding:var(--faluss-navigation-smart-active-padding);text-align:var(--faluss-navigation-smart-active-text-align);text-decoration:var(--faluss-navigation-smart-active-text-decoration);text-transform:var(--faluss-navigation-smart-active-text-transform)}';
    portal.appendChild(style);
  }

  function initialize(root) {
    if (initialized.has(root)) {
      return;
    }
    initialized.add(root);

    var trigger = root.querySelector('[data-faluss-navigation-trigger]');
    var template = root.querySelector('[data-faluss-navigation-template]');
    if (!trigger || !template || !('content' in template)) {
      return;
    }

    var portal = null;
    var closeTimer = null;
    var resizeHandler = null;
    var returnFocusMode = 'pointer';

    function teardown() {
      if (!portal) {
        return;
      }
      if (resizeHandler) {
        window.removeEventListener('resize', resizeHandler);
      }
      resizeHandler = null;
      portal.remove();
      portal = null;
      trigger.setAttribute('aria-expanded', 'false');
      root.dataset.falussNavigationReturnMode = returnFocusMode;
      unlockScroll();
      if (document.contains(trigger)) {
        trigger.focus({ preventScroll: true });
      }
    }

    function finishClose() {
      if (portal && portal.open) {
        portal.close();
      }
    }

    function close() {
      if (!portal || !portal.open || closeTimer) {
        return;
      }
      portal.dataset.falussNavigationState = 'closing';
      if (motionIsReduced() || portal.dataset.falussNavigationAnimation === 'none') {
        finishClose();
        return;
      }
      var duration = parseFloat(window.getComputedStyle(portal).getPropertyValue('--faluss-navigation-duration'));
      if (isNaN(duration)) {
        duration = 320;
      }
      closeTimer = window.setTimeout(finishClose, duration + 40);
    }

    function open() {
      if (portal && portal.open) {
        return;
      }
      var fragment = template.content.cloneNode(true);
      portal = fragment.querySelector('[data-faluss-navigation-portal]');
      if (!portal || typeof portal.showModal !== 'function') {
        portal = null;
        return;
      }
      portal.dataset.falussNavigationAnimation = root.dataset.falussNavigationAnimation || 'slide';
      document.body.appendChild(portal);
      installStateStyleRules(portal);
      applyPortalStyles(root, portal);
      resizeHandler = function () { applyPortalStyles(root, portal); };
      window.addEventListener('resize', resizeHandler, { passive: true });
      portal.addEventListener('close', function () {
        if (closeTimer) {
          window.clearTimeout(closeTimer);
          closeTimer = null;
        }
        teardown();
      });
      portal.addEventListener('cancel', function (event) {
        event.preventDefault();
        returnFocusMode = 'keyboard';
        close();
      });
      var closeTarget = portal.querySelector('[data-faluss-navigation-close]');
      closeTarget.addEventListener('click', function () {
        returnFocusMode = 'pointer';
        close();
      });
      closeTarget.addEventListener('keydown', function (event) {
        if ('Enter' === event.key || ' ' === event.key) {
          event.preventDefault();
          returnFocusMode = 'keyboard';
          close();
        }
      });
      portal.showModal();
      portal.dataset.falussNavigationState = 'opening';
      trigger.setAttribute('aria-expanded', 'true');
      lockScroll();
      window.requestAnimationFrame(function () {
        if (!portal) {
          return;
        }
        portal.dataset.falussNavigationState = 'open';
        var sidebar = portal.querySelector('[data-faluss-navigation-sidebar]');
        var firstAction = portal.querySelector('[data-faluss-navigation-link]');
        if (sidebar) {
          sidebar.focus({ preventScroll: true });
        } else if (firstAction) {
          firstAction.focus({ preventScroll: true });
        }
      });
    }

    trigger.addEventListener('pointerdown', function () {
      returnFocusMode = 'pointer';
      delete root.dataset.falussNavigationReturnMode;
    });
    trigger.addEventListener('keydown', function (event) {
      if ('Enter' === event.key || ' ' === event.key) {
        returnFocusMode = 'keyboard';
        delete root.dataset.falussNavigationReturnMode;
      }
    });
    trigger.addEventListener('click', open);
  }

  function initializeWithin(context) {
    var roots = [];
    if (context && context.matches && context.matches('[data-faluss-identity-navigation]')) {
      roots.push(context);
    }
    if (context && context.querySelectorAll) {
      roots = roots.concat(Array.prototype.slice.call(context.querySelectorAll('[data-faluss-identity-navigation]')));
    }
    roots.forEach(initialize);
  }

  function boot() {
    initializeWithin(document);
    if (window.elementorFrontend && window.elementorFrontend.hooks) {
      window.elementorFrontend.hooks.addAction('frontend/element_ready/faluss_identity_navigation.default', function ($scope) {
        initializeWithin($scope && $scope[0] ? $scope[0] : document);
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
  window.addEventListener('elementor/frontend/init', boot);
}());
