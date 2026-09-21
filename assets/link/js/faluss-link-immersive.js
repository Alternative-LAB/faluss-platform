(function () {
    'use strict';

    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }

    var cards = [];
    var frame = 0;
    var listening = false;

    function isPublicProfileRoute() {
        return !!(document.body && document.body.classList.contains('faluss-identity-public-route'));
    }

    function update() {
        frame = 0;
        if (!isPublicProfileRoute()) {
            return;
        }
        cards.forEach(function (card) {
            var cover = card.querySelector('.faluss-link-card__cover');
            var rect = card.getBoundingClientRect();
            if (!cover || rect.bottom < 0 || rect.top > window.innerHeight) {
                return;
            }
            var depth = Math.max(-24, Math.min(24, -rect.top * 0.12));
            var panelDepth = Math.max(-10, Math.min(0, rect.top * 0.035));
            cover.style.setProperty('--fl-immersive-depth', depth.toFixed(2) + 'px');
            card.style.setProperty('--fl-immersive-panel-depth', panelDepth.toFixed(2) + 'px');
        });
    }

    function requestUpdate() {
        if (!frame) {
            frame = window.requestAnimationFrame(update);
        }
    }

    function bindListeners() {
        if (listening) {
            return;
        }
        listening = true;
        window.addEventListener('scroll', requestUpdate, { passive: true });
    }

    function initialize(root) {
        if (!isPublicProfileRoute()) {
            return;
        }
        var scope = root && root.jquery ? root[0] : (root || document);
        var candidates = scope.querySelectorAll ? scope.querySelectorAll('.faluss-link-card--presentation-immersive') : [];
        Array.prototype.forEach.call(candidates, function (card) {
            if (card.dataset.falussLinkImmersiveReady) {
                return;
            }
            card.dataset.falussLinkImmersiveReady = '1';
            cards.push(card);
        });
        if (cards.length) {
            bindListeners();
            requestUpdate();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { initialize(document); });
    } else {
        initialize(document);
    }

    window.addEventListener('elementor/frontend/init', function () {
        if (window.elementorFrontend && window.elementorFrontend.hooks) {
            window.elementorFrontend.hooks.addAction('frontend/element_ready/faluss_link_card.default', initialize);
        }
    });
}());
