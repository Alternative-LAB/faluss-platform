(function () {
    'use strict';

    var titleThreshold = 4.5;
    var editorialThreshold = 3;

    function rgb(value) {
        var hex = String(value || '').trim();
        var match = hex.match(/^#([0-9a-f]{3}|[0-9a-f]{6})$/i);
        if (match) {
            var source = match[1].length === 3 ? match[1].replace(/(.)/g, '$1$1') : match[1];
            return [parseInt(source.slice(0, 2), 16), parseInt(source.slice(2, 4), 16), parseInt(source.slice(4, 6), 16)];
        }
        match = hex.match(/^rgba?\(\s*([\d.]+)[,\s]+\s*([\d.]+)[,\s]+\s*([\d.]+)/i);
        return match ? [parseFloat(match[1]), parseFloat(match[2]), parseFloat(match[3])] : null;
    }
    function relativeLuminance(color) {
        var channels = rgb(color);
        if (!channels) { return null; }
        return channels.map(function (channel) { channel /= 255; return channel <= 0.03928 ? channel / 12.92 : Math.pow((channel + 0.055) / 1.055, 2.4); }).reduce(function (sum, value, index) { return sum + value * [0.2126, 0.7152, 0.0722][index]; }, 0);
    }
    function contrastRatio(a, b) { var first = relativeLuminance(a), second = relativeLuminance(b); return first === null || second === null ? 0 : (Math.max(first, second) + 0.05) / (Math.min(first, second) + 0.05); }
    function computedVariable(element, name, fallback) { var value = window.getComputedStyle(element).getPropertyValue(name).trim(); return value || fallback; }
    function effectiveSurface(card) { return computedVariable(card, '--fl-page-background', computedVariable(card, '--fl-canvas', '#FFFDF5')); }
    function opaqueCssColor(value) { var alpha = String(value || '').match(/^rgba\([^,]+,[^,]+,[^,]+,\s*([\d.]+)\)$/i); return rgb(value) && (!alpha || parseFloat(alpha[1]) >= 0.99) ? value : ''; }
    function surfaceFor(node, card) {
        var cursor = node;
        while (cursor && cursor !== card.parentElement) {
            var surface = opaqueCssColor(window.getComputedStyle(cursor).backgroundColor);
            if (surface) { return surface; }
            cursor = cursor.parentElement;
        }
        return effectiveSurface(card);
    }
    function bestContrastColor(surface) { return contrastRatio('#000000', surface) >= contrastRatio('#FFFFFF', surface) ? '#000000' : '#FFFFFF'; }
    function readable(node, property, surface, threshold) {
        node.style.removeProperty(property + '-resolved');
        var preferred = window.getComputedStyle(node).color;
        var resolved = contrastRatio(preferred, surface) >= threshold ? preferred : bestContrastColor(surface);
        node.style.setProperty(property + '-resolved', resolved);
    }
    function refresh(card) {
        if (!card || !card.classList || !card.classList.contains('faluss-link-card')) { return; }
        card.querySelectorAll('.faluss-link-card__name,.faluss-link-card__section-title').forEach(function (node) { readable(node, '--fl-title-color', surfaceFor(node, card), titleThreshold); });
        card.querySelectorAll('.faluss-link-card__handle,.faluss-link-card__bio,.faluss-link-card__content-text').forEach(function (node) { readable(node, '--fl-secondary-color', surfaceFor(node, card), editorialThreshold); });
        card.querySelectorAll('.faluss-link-card__link').forEach(function (node) { readable(node, '--fl-link-text', surfaceFor(node, card), titleThreshold); });
    }
    function initialize(root) { var scope = root && root.jquery ? root[0] : (root || document); if (!scope.querySelectorAll) { return; } scope.querySelectorAll('.faluss-link-card').forEach(refresh); }

    window.FalussLinkCard = { refresh: refresh, initialize: initialize, contrastRatio: contrastRatio, bestContrastColor: bestContrastColor, surfaceFor: surfaceFor };
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', function () { initialize(document); }); } else { initialize(document); }
    window.addEventListener('elementor/frontend/init', function () { if (window.elementorFrontend && window.elementorFrontend.hooks) { window.elementorFrontend.hooks.addAction('frontend/element_ready/faluss_link_card.default', initialize); window.elementorFrontend.hooks.addAction('frontend/element_ready/faluss_link_studio.default', initialize); window.elementorFrontend.hooks.addAction('frontend/element_ready/faluss_link_appearance.default', initialize); } });
}());
