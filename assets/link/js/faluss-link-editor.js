(function ($) {
    'use strict';

    var teaserFormats = { landscape: 'Paysage', portrait: 'Portrait', square: 'Carré' };
    var teaserAccessModes = { public: 'Public', member: 'Membre Faluss', entitlement: 'Droit requis' };
    var styleFields = /^(page_background|hero_transition_color|name_color|alignment|social_variant|link_style)$/;
    var statusLifetime = 1800;

    function card(studio) { return studio.find('.faluss-link-studio__preview .faluss-link-card'); }
    function reducedMotion() { return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches; }
    function safeURL(value) {
        try {
            var url = new URL($.trim(value || ''));
            return url.protocol === 'https:' && !!url.hostname && !url.username && !url.password;
        } catch (error) { return false; }
    }

    function catalog() {
        var source = (window.falussLinkCover && falussLinkCover.networks) || {};
        return Object.keys(source).filter(function (key) {
            return source[key] && source[key].active;
        }).map(function (key) {
            return { key: key, label: source[key].label || key, outline: source[key].outline || null, full: source[key].full || null };
        });
    }
    function network(key) { return catalog().filter(function (item) { return item.key === key; })[0] || null; }
    function validAsset(asset) { return asset && typeof asset.src === 'string' && asset.src !== ''; }
    function networkAsset(item, variant) {
        if (!item) { return null; }
        var primary = variant === 'full' ? item.full : item.outline;
        var alternate = variant === 'full' ? item.outline : item.full;
        return validAsset(primary) ? primary : (validAsset(alternate) ? alternate : null);
    }
    function networkIcon(key, variant) {
        var item = network(key), asset = networkAsset(item, variant), image;
        if (!item || !asset) { return null; }
        image = $('<img>', { 'class': 'faluss-link-card__network-asset', src: asset.src, alt: item.label, loading: 'lazy' });
        if (asset.srcset) { image.attr('srcset', asset.srcset); }
        if (asset.sizes) { image.attr('sizes', asset.sizes); }
        return image;
    }

    function themes() { return (window.falussLinkCover && Array.isArray(falussLinkCover.themes)) ? falussLinkCover.themes : []; }
    function theme(slug) { return themes().filter(function (item) { return item && item.slug === slug; })[0] || null; }
    function themeLinkStyle(value) { return value === 'dark' ? 'solid' : (/^(solid|light|outline)$/.test(value || '') ? value : 'solid'); }
    function themeOverrides(studio) {
        var raw = studio.find('[name="theme_overrides"]').val() || '[]';
        try { raw = JSON.parse(raw); } catch (error) { raw = []; }
        return Array.isArray(raw) ? raw.filter(function (key, index, values) {
            return styleFields.test(key || '') && values.indexOf(key) === index;
        }) : [];
    }
    function setThemeOverrides(studio, overrides) { studio.find('[name="theme_overrides"]').val(JSON.stringify(overrides)); }
    function markThemeOverride(studio, name) {
        if (!studio.length || !styleFields.test(name || '')) { return; }
        var overrides = themeOverrides(studio);
        if (overrides.indexOf(name) === -1) { overrides.push(name); setThemeOverrides(studio, overrides); }
    }
    function applyTheme(studio, selected) {
        var preset = theme(selected);
        if (!preset || preset.locked || !studio.length) { return; }
        studio.data('falussLinkApplyingTheme', true);
        studio.find('[name="selected_theme"]').val(preset.slug);
        setThemeOverrides(studio, []);
        studio.find('[name="page_background"]').val(preset.page_background || '#FFFDF5');
        studio.find('[name="hero_transition_color"]').val(preset.hero_transition_color || '#FFFDF5');
        var alignment = preset.alignment || 'left';
        var alignmentRadios = studio.find('[name="alignment"][type="radio"]');
        if (alignmentRadios.length) { alignmentRadios.prop('checked', false).filter('[value="' + alignment + '"]').prop('checked', true); }
        else { studio.find('[name="alignment"]').val(alignment); }
        studio.find('[name="social_variant"]').val(preset.social_variant || 'outline');
        var linkStyle = themeLinkStyle(preset.link_style);
        var linkStyleRadios = studio.find('[name="link_style"][type="radio"]');
        if (linkStyleRadios.length) { linkStyleRadios.prop('checked', false).filter('[value="' + linkStyle + '"]').prop('checked', true); }
        else { studio.find('[name="link_style"]').val(linkStyle); }
        studio.find('[name="name_color"][value="' + (preset.name_color || '#000000') + '"]').prop('checked', true);
        studio.find('.faluss-link-theme-picker__theme').attr('aria-pressed', 'false');
        studio.find('.faluss-link-theme-picker__theme[data-faluss-theme="' + preset.slug + '"]').attr('aria-pressed', 'true');
        studio.data('falussLinkApplyingTheme', false);
        update(studio);
    }

    function updateCursor(studio) {
        var tabs = studio.find('.faluss-link-studio__tabs'), active = tabs.find('[aria-selected="true"]');
        if (active.length && tabs.length) {
            tabs[0].style.setProperty('--faluss-link-tab-left', active[0].offsetLeft + 'px');
            tabs[0].style.setProperty('--faluss-link-tab-width', active.outerWidth() + 'px');
        }
        var dock = studio.find('.faluss-link-studio__dock-tabs'), dockActive = dock.find('[data-fl-tab][aria-pressed="true"]');
        if (dock.length && dockActive.length) {
            dock[0].style.setProperty('--fl-dock-left', dockActive[0].offsetLeft + 'px');
            dock[0].style.setProperty('--fl-dock-width', dockActive.outerWidth() + 'px');
        }
        studio.find('.faluss-link-studio__context-tabs:visible').each(function () {
            var nav = $(this), selected = nav.find('[data-fl-context-tab][aria-selected="true"]');
            if (selected.length) {
                nav[0].style.setProperty('--fl-context-left', selected[0].offsetLeft + 'px');
                nav[0].style.setProperty('--fl-context-width', selected.outerWidth() + 'px');
            }
        });
    }
    function activeTab(studio) {
        var tab = studio.find('[data-fl-tab][aria-pressed="true"], [data-fl-tab][aria-selected="true"]').first().data('fl-tab');
        return /^(profile|links|style)$/.test(tab || '') ? tab : 'links';
    }
    function activate(studio, name, focus) {
        if (studio.find('[data-fl-main-panel]').length) {
            name = name === 'profile' ? 'style' : name;
            if (!/^(links|style)$/.test(name || '')) { name = 'links'; }
            var main = studio.find('[data-fl-main-panel="' + name + '"]');
            studio.find('[data-fl-main-panel]').not(main).prop('hidden', true).attr('inert', '');
            main.prop('hidden', false).removeAttr('inert');
            studio.find('.faluss-link-studio__dock-tabs [data-fl-tab]').attr('aria-pressed', 'false');
            var dockTab = studio.find('.faluss-link-studio__dock-tabs [data-fl-tab="' + name + '"]').attr('aria-pressed', 'true');
            studio.find('[data-fl-active-tab]').val(name);
            studio.attr('data-faluss-studio-tab', name);
            var section = name === 'style' ? 'appearance' : 'all';
            var current = studio.find('[data-fl-active-section]').val();
            if ((name === 'style' && /^(appearance|header|link-style)$/.test(current || '')) || (name === 'links' && /^(all|collections|collection)$/.test(current || ''))) { section = current; }
            activateSection(studio, section, false);
            updateCursor(studio);
            if (focus) { dockTab.trigger('focus'); }
            return;
        }
        var next = studio.find('[data-fl-panel="' + name + '"]'), tabs = studio.find('[data-fl-tab]');
        if (!next.length) { return; }
        tabs.attr({ 'aria-selected': 'false', tabindex: '-1' });
        var tab = studio.find('[data-fl-tab="' + name + '"]').attr({ 'aria-selected': 'true', tabindex: '0' });
        studio.find('[data-fl-panel]').not(next).prop('hidden', true).removeClass('is-entering is-leaving');
        next.prop('hidden', false).addClass('is-entering');
        studio.find('[data-fl-active-tab]').val(name);
        studio.attr('data-faluss-studio-tab', name);
        requestAnimationFrame(function () { next.removeClass('is-entering'); });
        updateCursor(studio);
        if (focus) { tab.trigger('focus'); }
        if (tab[0] && tab[0].scrollIntoView) { tab[0].scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: reducedMotion() ? 'auto' : 'smooth' }); }
    }

    function activateSection(studio, name, focus) {
        var tab = activeTab(studio), allowed = tab === 'style' ? /^(appearance|header|link-style)$/ : /^(all|collections|collection)$/;
        if (!allowed.test(name || '')) { name = tab === 'style' ? 'appearance' : 'all'; }
        if (name === 'collection' && !studio.find('[data-fl-section-panel="collection"]').length) { name = 'collections'; }
        var main = studio.find('[data-fl-main-panel="' + tab + '"]'), panel = main.find('[data-fl-section-panel="' + name + '"]');
        main.find('[data-fl-section-panel]').not(panel).prop('hidden', true).attr('inert', '');
        panel.prop('hidden', false).removeAttr('inert').addClass('is-entering');
        requestAnimationFrame(function () { panel.removeClass('is-entering'); });
        var contextName = name === 'collection' ? 'collections' : name;
        main.find('[data-fl-context-tab]').attr({ 'aria-selected': 'false', tabindex: '-1' });
        var contextTab = main.find('[data-fl-context-tab="' + contextName + '"]').attr({ 'aria-selected': 'true', tabindex: '0' });
        studio.find('[data-fl-active-section]').val(name);
        studio.attr('data-faluss-studio-section', name);
        if (name !== 'collection') {
            studio.find('[data-fl-active-collection]').val('');
            studio.attr('data-faluss-studio-collection', '');
        }
        updateUrlState(studio);
        updateCreateAction(studio);
        updateCursor(studio);
        if (focus) { contextTab.trigger('focus'); }
    }

    function updateUrlState(studio) {
        if (!window.history || !window.history.replaceState) { return; }
        var url = new URL(window.location.href);
        url.searchParams.set('faluss_studio_tab', activeTab(studio));
        url.searchParams.set('faluss_studio_section', studio.find('[data-fl-active-section]').val() || 'all');
        var collection = studio.find('[data-fl-active-collection]').val() || '';
        if (collection) { url.searchParams.set('faluss_studio_collection', collection); }
        else { url.searchParams.delete('faluss_studio_collection'); }
        url.searchParams.delete('faluss_studio_notice');
        window.history.replaceState({}, '', url.toString());
    }

    function updateCreateAction(studio) {
        var create = studio.find('[data-fl-create]'), enabled = activeTab(studio) === 'links';
        create.prop('disabled', !enabled).attr('aria-disabled', enabled ? 'false' : 'true');
    }

    function renumberRows(studio) {
        studio.find('.faluss-link-studio__network-row').each(function (index) {
            $(this).find('select,input').each(function () {
                this.name = this.name.replace(/social_networks\[\d+\]/, 'social_networks[' + index + ']');
            });
        });
    }
    function blockId() {
        if (window.crypto && window.crypto.randomUUID) { return window.crypto.randomUUID(); }
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (value) {
            var random = Math.random() * 16 | 0, bit = value === 'x' ? random : (random & 3 | 8);
            return bit.toString(16);
        });
    }
    function renumberBlocks(studio) {
        var blocks = studio.find('[data-fl-block-store] [data-fl-stored-block]');
        if (!blocks.length) { blocks = studio.find('.faluss-link-content-block'); }
        blocks.each(function (index) {
            var block = $(this);
            block.find('[data-fl-block-field]').each(function () {
                this.name = 'content_blocks[' + index + '][' + $(this).data('fl-block-field') + ']';
            });
            block.find('[data-fl-block-action="up"]').prop('disabled', index === 0);
            block.find('[data-fl-block-action="down"]').prop('disabled', index === blocks.length - 1);
        });
    }
    function teaserFormatField(value) {
        var select = $('<select>', { 'data-fl-block-field': 'format', 'aria-label': 'Format de l’image' });
        Object.keys(teaserFormats).forEach(function (key) {
            select.append($('<option>', { value: key, text: teaserFormats[key], selected: (value || 'landscape') === key }));
        });
        return $('<label>', { text: 'Format image' }).append(select);
    }
    function teaserRights() {
        return (window.falussLinkCover && Array.isArray(falussLinkCover.teaserRights)) ? falussLinkCover.teaserRights.filter(function (right) {
            return right && typeof right.code === 'string' && right.code && typeof right.label === 'string' && right.label;
        }) : [];
    }
    function teaserAccessFields(mode, code) {
        var rights = teaserRights(), fieldset = $('<fieldset>', { 'class': 'faluss-link-content-block__access' });
        fieldset.append($('<legend>', { text: 'Accès au teaser' }));
        var access = $('<select>', { 'data-fl-block-field': 'access_mode', 'aria-label': 'Visibilité du teaser' });
        Object.keys(teaserAccessModes).forEach(function (key) {
            access.append($('<option>', { value: key, text: teaserAccessModes[key], selected: (mode || 'public') === key }));
        });
        fieldset.append($('<label>', { text: 'Visibilité' }).append(access));
        if (rights.length) {
            var entitlement = $('<select>', { 'data-fl-block-field': 'entitlement_code', 'aria-label': 'Droit requis', disabled: (mode || 'public') !== 'entitlement' });
            entitlement.append($('<option>', { value: '', text: 'Choisir un droit' }));
            rights.forEach(function (right) { entitlement.append($('<option>', { value: right.code, text: right.label, selected: code === right.code })); });
            fieldset.append($('<label>', { text: 'Droit requis' }).append(entitlement));
        } else {
            fieldset.append($('<p>', { 'class': 'faluss-link-content-block__access-hint', 'data-fl-entitlement-unavailable': '', text: 'Aucun droit lisible : vérifiez le Connector et la permission entitlements.read avant d’utiliser « Droit requis ».' }));
        }
        fieldset.append($('<p>', { 'class': 'faluss-link-content-block__access-state', 'data-fl-access-state': '', text: teaserAccessModes[mode] || teaserAccessModes.public }));
        return fieldset;
    }
    function syncTeaserAccess(block) {
        var mode = block.find('[data-fl-block-field="access_mode"]').val() || 'public';
        block.find('[data-fl-block-field="entitlement_code"]').prop('disabled', mode !== 'entitlement');
        block.find('[data-fl-access-state]').text(teaserAccessModes[mode] || teaserAccessModes.public);
    }
    function ensureTeaserFormats(studio) {
        studio.find('.faluss-link-content-block[data-block-type="media_teaser"]').each(function () {
            var block = $(this), source = block.next('.faluss-link-content-block__format-source'), format = source.val() || 'landscape';
            source.remove();
            if (!block.find('[data-fl-block-field="format"]').length) { block.find('.faluss-link-content-block__media').after(teaserFormatField(format)); }
            syncTeaserAccess(block);
        });
    }
    function contentBlock(type) {
        var titles = { section_title: 'Titre de section', text: 'Texte', link: 'Lien', media_teaser: 'Teaser média' }, id = blockId();
        var block = $('<article>', { 'class': 'faluss-link-content-block', 'data-block-id': id, 'data-block-type': type });
        var header = $('<header>', { 'class': 'faluss-link-content-block__header' });
        var actions = $('<div>', { 'class': 'faluss-link-content-block__actions' });
        actions.append($('<button>', { type: 'button', 'data-fl-block-action': 'up', 'aria-label': 'Monter cet élément', text: 'Monter' }));
        actions.append($('<button>', { type: 'button', 'data-fl-block-action': 'down', 'aria-label': 'Descendre cet élément', text: 'Descendre' }));
        actions.append($('<button>', { type: 'button', 'data-fl-block-action': 'remove', 'aria-label': 'Supprimer cet élément', text: 'Supprimer' }));
        header.append($('<strong>', { text: titles[type] || 'Texte' })).append(actions);
        block.append($('<input>', { type: 'hidden', 'data-fl-block-field': 'block_id', value: id }));
        block.append($('<input>', { type: 'hidden', 'data-fl-block-field': 'type', value: type })).append(header);
        if (type === 'section_title') {
            block.append($('<label>', { text: 'Titre' }).append($('<input>', { type: 'text', maxlength: 80, 'data-fl-block-field': 'value' })));
        } else if (type === 'link') {
            block.append($('<label>', { text: 'Libellé' }).append($('<input>', { type: 'text', maxlength: 80, 'data-fl-block-field': 'label' })));
            block.append($('<label>', { text: 'URL HTTPS' }).append($('<input>', { type: 'url', maxlength: 2048, placeholder: 'https://', 'data-fl-block-field': 'url' })));
        } else if (type === 'media_teaser') {
            block.append($('<div>', { 'class': 'faluss-link-content-block__media' })
                .append($('<input>', { type: 'hidden', 'data-fl-block-field': 'attachment_id' }))
                .append($('<button>', { type: 'button', 'class': 'faluss-link-content-block__select-teaser', text: 'Choisir une image' }))
                .append($('<button>', { type: 'button', 'class': 'faluss-link-content-block__remove-teaser', text: 'Retirer' }))
                .append($('<div>', { 'class': 'faluss-link-content-block__media-preview' })));
            block.append(teaserFormatField('landscape'));
            block.append($('<label>', { text: 'Titre facultatif' }).append($('<input>', { type: 'text', maxlength: 80, 'data-fl-block-field': 'title' })));
            block.append($('<label>', { text: 'Texte facultatif' }).append($('<textarea>', { maxlength: 240, rows: 3, 'data-fl-block-field': 'text' })));
            block.append(teaserAccessFields('public', ''));
        } else {
            block.append($('<label>', { text: 'Texte' }).append($('<textarea>', { maxlength: 480, rows: 3, 'data-fl-block-field': 'value' })));
        }
        return block;
    }
    function networkRow(index) {
        var select = $('<select>', { name: 'social_networks[' + index + '][network]', 'aria-label': 'Réseau' });
        catalog().forEach(function (item) { select.append($('<option>', { value: item.key, text: item.label })); });
        return $('<div>', { 'class': 'faluss-link-studio__network-row' })
            .append(select)
            .append($('<input>', { type: 'url', name: 'social_networks[' + index + '][url]', maxlength: 2048, placeholder: 'https://', 'aria-label': 'URL HTTPS' }))
            .append($('<button>', { type: 'button', 'class': 'faluss-link-studio__remove-row', text: 'Supprimer' }));
    }

    function blockData(block) {
        var data = { block_id: block.find('[data-fl-block-field="block_id"]').val() || block.data('block-id') || '', type: block.data('block-type') || '' };
        block.find('[data-fl-block-field]').each(function () { data[$(this).data('fl-block-field')] = $(this).val() || ''; });
        data.image = block.find('.faluss-link-content-block__media-preview img').attr('src') || '';
        return data;
    }
    var blockRenderers = {
        section_title: function (data) { return $.trim(data.value) ? $('<h3>', { 'class': 'faluss-link-card__section-title', text: data.value }) : null; },
        text: function (data) { return $.trim(data.value) ? $('<p>', { 'class': 'faluss-link-card__content-text', text: data.value }) : null; },
        link: function (data) { return $.trim(data.label) && safeURL(data.url) ? $('<a>', { 'class': 'faluss-link-card__link', href: data.url, target: '_blank', rel: 'noopener noreferrer nofollow', text: data.label }) : null; },
        media_teaser: function (data) {
            if (!data.attachment_id || !data.image) { return null; }
            var hasCopy = $.trim(data.title) || $.trim(data.text);
            var format = teaserFormats[data.format] ? data.format : 'landscape';
            var teaser = $('<section>', { 'class': 'faluss-link-card__media-teaser faluss-link-card__media-teaser--format-' + format + (hasCopy ? '' : ' faluss-link-card__media-teaser--image-only') });
            teaser.append($('<img>', { src: data.image, alt: data.title || '' }));
            if (hasCopy) {
                var copy = $('<div>', { 'class': 'faluss-link-card__media-teaser-copy' });
                if ($.trim(data.title)) { copy.append($('<h3>', { text: data.title })); }
                if ($.trim(data.text)) { copy.append($('<p>', { text: data.text })); }
                teaser.append(copy);
            }
            return teaser;
        }
    };
    function updateLinks(studio) {
        var preview = card(studio), wrap = preview.find('.faluss-link-card__content-blocks');
        if (!wrap.length) { wrap = $('<div>', { 'class': 'faluss-link-card__content-blocks faluss-link-card__links' }).insertAfter(preview.find('.faluss-link-card__social')); }
        wrap.empty();
        var blocks = studio.find('[data-fl-block-store] [data-fl-stored-block]');
        if (!blocks.length) { blocks = studio.find('.faluss-link-content-block'); }
        blocks.each(function () {
            var data = blockData($(this)), renderer = blockRenderers[data.type], rendered = renderer ? renderer(data) : null;
            if (rendered) { wrap.append(rendered); }
        });
        wrap.prop('hidden', wrap.children().length === 0);
    }
    function updateSocials(studio) {
        var wrap = card(studio).find('.faluss-link-card__social');
        var layout = studio.find('[name="social_layout"]').val() || 'bubbles';
        var variant = studio.find('[name="social_variant"]').val() || 'outline';
        var rendered = 0;
        wrap.empty().attr('class', 'faluss-link-card__social faluss-link-card__social--' + layout + ' faluss-link-card__social--variant-' + variant).attr('data-faluss-social-variant', variant);
        studio.find('.faluss-link-studio__network-row').each(function () {
            var row = $(this), key = row.find('select').val(), url = row.find('input[type="url"]').val(), item = network(key), image = networkIcon(key, variant);
            if (!item || !image || !safeURL(url)) { return; }
            rendered += 1;
            wrap.append($('<a>', { href: url, target: '_blank', rel: 'noopener noreferrer nofollow', 'aria-label': item.label, 'data-faluss-network': key }).append(image));
        });
        wrap.prop('hidden', rendered === 0);
    }

    function formState(studio) {
        var state = {};
        studio.find('.faluss-link-studio__form').find('input,select,textarea').each(function () {
            var field = $(this), name = field.attr('name'), type = (field.attr('type') || '').toLowerCase();
            if (!name || /^(?:faluss_link_studio_nonce|_wp_http_referer|faluss_studio_tab)$/.test(name)) { return; }
            if (type === 'radio' && !field.prop('checked')) { return; }
            if (type === 'checkbox' && !field.prop('checked')) { state[name] = ''; return; }
            if (type === 'file') {
                var file = this.files && this.files[0];
                state[name] = file ? [file.name, file.size, file.lastModified].join(':') : '';
                return;
            }
            state[name] = field.val() || '';
        });
        return state;
    }
    function dirtyCount(studio) {
        var initial = studio.data('falussLinkInitialState') || {}, current = formState(studio), keys = {};
        Object.keys(initial).forEach(function (key) { keys[key] = true; });
        Object.keys(current).forEach(function (key) { keys[key] = true; });
        return Object.keys(keys).filter(function (key) { return initial[key] !== current[key]; }).length;
    }
    function updateDirty(studio) {
        var count = dirtyCount(studio), badge = studio.find('[data-fl-dirty-count]');
        badge.text(count).prop('hidden', count === 0).attr('aria-label', count ? count + ' modification' + (count > 1 ? 's' : '') + ' non enregistrée' + (count > 1 ? 's' : '') : '');
        studio.find('[data-fl-preview-toggle]').toggleClass('has-changes', count > 0);
    }
    function updateColorFields(studio) {
        studio.find('[data-fl-color-value]').each(function () {
            var output = $(this), input = studio.find('[name="' + output.data('fl-color-value') + '"]'), value = input.val() || '';
            output.text(String(value).toUpperCase());
        });
    }
    function updatePalettes(studio) {
        studio.find('[data-fl-color-palette]').each(function () {
            var palette = $(this), field = palette.data('fl-color-palette'), value = String(studio.find('[name="' + field + '"]').val() || '').toUpperCase();
            palette.find('[data-fl-color]').each(function () { $(this).attr('aria-pressed', String($(this).data('fl-color')).toUpperCase() === value ? 'true' : 'false'); });
        });
        studio.find('.faluss-link-studio__style-preview').css('--fl-studio-button-color', studio.find('[name="button_color"]').val() || '#080808');
    }
    function updatePublication(studio) {
        var input = studio.find('[name="publication_status"]'), published = input.prop('checked');
        input.attr('aria-checked', published ? 'true' : 'false');
        studio.find('[data-fl-publication-state]').text(published ? 'Visible' : 'Masqué');
    }
    function update(studio) {
        var preview = card(studio);
        if (!preview.length) { return; }
        var name = studio.find('[name="display_name"]').val() || 'Mon Faluss';
        var slug = studio.find('[name="public_slug"]').val();
        var mode = studio.find('[name="bio_mode"]').val() || 'editorial';
        var announcement = studio.find('[name="announcement"]').val() || '';
        var treatment = studio.find('[name="name_treatment"]').val() || 'strong';
        var nameFont = studio.find('[name="name_font"] option:selected');
        var style = themeLinkStyle(studio.find('[name="link_style"]:checked').val() || studio.find('select[name="link_style"]').val() || 'solid');
        var align = studio.find('[name="alignment"]:checked').val() || studio.find('select[name="alignment"]').val() || 'left';
        var avatarField = studio.find('[name="avatar_visible"][type="checkbox"]');
        var avatar = avatarField.length ? avatarField.prop('checked') : true;
        var avatarBorderField = studio.find('[name="avatar_border"][type="checkbox"]');
        var avatarBorder = avatarBorderField.length ? avatarBorderField.prop('checked') : true;
        var nameColor = studio.find('[name="name_color"]:checked').val() || '#000000';
        var selectedTheme = studio.find('[name="selected_theme"]').val() || 'faluss-default';
        preview.find('.faluss-link-card__name').text(name).attr('class', 'faluss-link-card__name faluss-link-card__name--' + treatment);
        var treatmentOption = studio.find('[name="name_treatment"] option:selected');
        preview[0].style.setProperty('--fl-name-weight', treatmentOption.data('name-weight') || 800);
        preview[0].style.setProperty('--fl-name-tracking', treatmentOption.data('name-tracking') || '-.045em');
        preview[0].style.setProperty('--fl-name-font', nameFont.data('font-stack') || 'Outfit, ui-sans-serif, system-ui, sans-serif');
        preview.find('.faluss-link-card__handle').text(slug ? '@' + slug : '@—');
        preview.find('.faluss-link-card__bio').text(studio.find('[name="bio"]').val() || '').prop('hidden', mode === 'announcement');
        preview.find('.faluss-link-card__announcement').text(announcement).attr('class', 'faluss-link-card__announcement faluss-link-card__announcement--' + (studio.find('[name="announcement_variant"]').val() || 'accent')).prop('hidden', !(mode === 'announcement' && announcement));
        preview.find('.faluss-link-card__availability').prop('hidden', !studio.find('[name="available"][type="checkbox"]').prop('checked'));
        preview.find('.faluss-link-card__avatar').prop('hidden', !avatar);
        preview.removeClass('faluss-link-card--avatar-yes faluss-link-card--avatar-no faluss-link-card--avatar-border-yes faluss-link-card--avatar-border-no faluss-link-card--links-solid faluss-link-card--links-light faluss-link-card--links-outline faluss-link-card--align-left faluss-link-card--align-center')
            .addClass('faluss-link-card--avatar-' + (avatar ? 'yes' : 'no'))
            .addClass('faluss-link-card--avatar-border-' + (avatarBorder ? 'yes' : 'no'))
            .addClass('faluss-link-card--links-' + style)
            .addClass('faluss-link-card--align-' + align)
            .attr('data-faluss-card-theme', selectedTheme);
        preview[0].style.setProperty('--fl-page-background', studio.find('[name="page_background"]').val() || '#FFFDF5');
        preview[0].style.setProperty('--fl-action', studio.find('[name="button_color"]').val() || '#080808');
        preview[0].style.setProperty('--fl-name-color', nameColor);
        preview[0].style.setProperty('--fl-hero-transition-color', studio.find('[name="hero_transition_color"]').val() || '#FFFDF5');
        preview[0].style.setProperty('--fl-hero-transition-intensity', (studio.find('[name="hero_transition_intensity"]').val() || 82) + '%');
        preview[0].style.setProperty('--fl-hero-transition-position', (studio.find('[name="hero_transition_position"]').val() || 72) + '%');
        studio.find('[data-studio-member-name]').text(name);
        studio.find('[data-studio-member-handle]').text(slug ? '@' + slug : '@—');
        updateSocials(studio);
        updateLinks(studio);
        updateColorFields(studio);
        updatePalettes(studio);
        updatePublication(studio);
        updateDirty(studio);
        if (window.FalussLinkCard) { window.FalussLinkCard.refresh(preview[0]); }
    }

    function uploadCover(root, file, studio) {
        if (!file || !/^image\//.test(file.type || '') || !window.falussLinkCover) { return; }
        var data = new FormData();
        data.append('action', 'faluss_link_upload_cover');
        data.append('nonce', falussLinkCover.nonce);
        data.append('cover', file);
        root.addClass('is-uploading');
        $.ajax({ url: falussLinkCover.url, type: 'POST', data: data, contentType: false, processData: false }).done(function (response) {
            if (!response || !response.success) { return; }
            root.find('.faluss-link-editor__cover-id').val(response.data.id);
            root.find('.faluss-link-editor__cover-preview').empty().append($('<img>', { src: response.data.url, alt: '' }));
            root.find('.faluss-link-editor__remove-cover').prop('hidden', false);
            if (studio.length) {
                var preview = card(studio);
                preview.find('.faluss-link-card__cover').empty().append($('<img>', { src: response.data.url, alt: '' })).prop('hidden', false);
                preview.removeClass('faluss-link-card--cover-no').addClass('faluss-link-card--cover-yes');
                update(studio);
                enqueueStudioMutation(studio, 'save_appearance', { cover_attachment_id: response.data.id }, { key: 'appearance:cover_attachment_id' });
            }
        }).always(function () { root.removeClass('is-uploading'); });
    }
    function uploadTeaser(block, file, studio) {
        if (!file || !/^image\//.test(file.type || '') || !window.falussLinkCover || !falussLinkCover.teaserNonce) { return; }
        var data = new FormData();
        data.append('action', 'faluss_link_upload_teaser');
        data.append('nonce', falussLinkCover.teaserNonce);
        data.append('teaser', file);
        block.addClass('is-uploading');
        $.ajax({ url: falussLinkCover.url, type: 'POST', data: data, contentType: false, processData: false }).done(function (response) {
            if (!response || !response.success) { return; }
            block.find('[data-fl-block-field="attachment_id"]').val(response.data.id);
            block.find('.faluss-link-content-block__media-preview').empty().append($('<img>', { src: response.data.url, alt: '' }));
            update(studio);
        }).always(function () { block.removeClass('is-uploading'); });
    }
    function uploadStudioAvatar(studio, file) {
        if (!file || !/^image\//.test(file.type || '') || !window.falussLinkCover || !falussLinkCover.avatarNonce) { return; }
        var data = new FormData();
        data.append('action', 'faluss_link_upload_avatar'); data.append('nonce', falussLinkCover.avatarNonce); data.append('avatar', file);
        studio.addClass('is-uploading');
        $.ajax({ url: falussLinkCover.url, type: 'POST', data: data, contentType: false, processData: false }).done(function (response) {
            if (!response || !response.success || !response.data || !response.data.id) { showStatus(studio, 'L’avatar n’a pas pu être envoyé.', true); return; }
            studio.find('[name="faluss_identity_avatar_id"]').val(response.data.id);
            card(studio).find('.faluss-link-card__avatar').empty().append($('<img>', { src: response.data.url, alt: '' }));
            studio.find('.faluss-link-studio__avatar').empty().append($('<img>', { src: response.data.url, alt: '' }));
            update(studio);
            enqueueStudioMutation(studio, 'save_profile', { avatar_attachment_id: response.data.id }, { key: 'profile:avatar_attachment_id' });
        }).fail(function () { showStatus(studio, 'L’avatar n’a pas pu être envoyé.', true); }).always(function () { studio.removeClass('is-uploading'); });
    }

    function storedBlocks(studio) { return studio.find('[data-fl-block-store] [data-fl-stored-block]'); }
    function storedBlock(studio, id) { return storedBlocks(studio).filter('[data-block-id="' + id + '"]').first(); }
    function storedField(block, name) { return block.find('[data-fl-block-field="' + name + '"]'); }
    function setStoredField(block, name, value) {
        var field = storedField(block, name);
        if (!field.length) { field = $('<input>', { type: 'hidden', 'data-fl-block-field': name }).appendTo(block); }
        field.val(value || '');
    }
    function makeStoredBlock(type, values) {
        var id = (values && values.block_id) || blockId();
        var block = $('<div>', { 'data-fl-stored-block': '', 'data-block-id': id, 'data-block-type': type });
        setStoredField(block, 'block_id', id);
        setStoredField(block, 'type', type);
        Object.keys(values || {}).forEach(function (key) { if (key !== 'block_id' && key !== 'type') { setStoredField(block, key, values[key]); } });
        return block;
    }
    function hydrateCanonicalBlocks(studio, state) {
        var blocks = state && Array.isArray(state.blocks) ? state.blocks : null;
        if (!blocks) { return false; }
        var store = studio.find('[data-fl-block-store]').empty();
        blocks.forEach(function (block) {
            if (!block || typeof block.block_id !== 'string' || typeof block.type !== 'string') { return; }
            store.append(makeStoredBlock(block.type, block));
        });
        if (typeof state.links_html === 'string') {
            studio.find('[data-fl-main-panel="links"] [data-fl-section-panel="all"]').html(state.links_html);
        }
        if (typeof state.collections_html === 'string') { studio.find('[data-fl-section-panel="collections"]').html(state.collections_html); }
        if (typeof state.active_collection === 'string') {
            studio.attr('data-faluss-studio-collection', state.active_collection);
            studio.find('[data-fl-active-collection]').val(state.active_collection);
        }
        var collectionPanel = studio.find('[data-fl-section-panel="collection"]');
        if (state.collection_html) {
            if (!collectionPanel.length) { collectionPanel = $('<section>', { id: 'faluss-studio-panel-collection', role: 'tabpanel', 'aria-labelledby': 'faluss-studio-tab-collections', 'data-fl-section-panel': 'collection' }).appendTo(studio.find('[data-fl-main-panel="links"] .faluss-link-studio__content')); }
            collectionPanel.html(state.collection_html);
        } else { collectionPanel.remove(); }
        if (typeof state.preview_html === 'string' && state.preview_html) {
            var previewCard = card(studio), replacement = $(state.preview_html).first();
            if (previewCard.length && replacement.length) { previewCard.replaceWith(replacement); }
        }
        if (typeof state.version === 'string') { studio.attr('data-faluss-studio-version', state.version).find('[data-fl-aggregate-version]').val(state.version); }
        renumberBlocks(studio);
        update(studio);
        return true;
    }

    function setCanonicalField(studio, name, value) {
        var fields = studio.find('[name="' + name + '"]');
        if (!fields.length) { return; }
        var checkboxes = fields.filter('input[type="checkbox"]'), radios = fields.filter('input[type="radio"]');
        if (checkboxes.length) { checkboxes.prop('checked', value === true || value === 1 || value === '1' || value === 'published'); }
        else if (radios.length) { radios.prop('checked', false).filter('[value="' + value + '"]').prop('checked', true); }
        else { fields.val(value === null || typeof value === 'undefined' ? '' : value); }
    }
    function hydrateCanonicalForm(studio, state) {
        var profile = state.profile || {}, preferences = state.preferences || {};
        ['public_slug', 'display_name', 'bio', 'publication_status'].forEach(function (name) { if (Object.prototype.hasOwnProperty.call(profile, name)) { setCanonicalField(studio, name, profile[name]); } });
        setCanonicalField(studio, 'faluss_identity_avatar_id', profile.avatar_attachment_id || 0);
        ['cover_attachment_id', 'available', 'avatar_visible', 'avatar_border', 'name_font', 'name_treatment', 'name_color', 'alignment', 'social_layout', 'social_variant', 'link_style', 'page_background', 'button_color', 'hero_transition_color', 'hero_transition_intensity', 'hero_transition_position'].forEach(function (name) {
            if (Object.prototype.hasOwnProperty.call(preferences, name)) { setCanonicalField(studio, name, preferences[name]); }
        });
        setCanonicalField(studio, 'selected_theme', preferences.theme_reference || preferences.selected_theme || 'faluss-default');
        setCanonicalField(studio, 'theme_overrides', JSON.stringify(preferences.theme_overrides || []));
        studio.find('.faluss-link-theme-picker__theme').attr('aria-pressed', 'false').filter('[data-faluss-theme="' + (preferences.theme_reference || 'faluss-default') + '"]').attr('aria-pressed', 'true');
        var media = studio.find('.faluss-link-studio__cover-control'), coverURL = preferences.cover_url || '';
        media.find('.faluss-link-editor__cover-preview').empty();
        if (coverURL) { media.find('.faluss-link-editor__cover-preview').append($('<img>', { src: coverURL, alt: '' })); }
        media.find('.faluss-link-editor__remove-cover').prop('hidden', !coverURL);
        var list = studio.find('.faluss-link-studio__network-list').empty();
        (preferences.social_links || []).forEach(function (item, index) { var row = networkRow(index); row.find('select').val(item.network); row.find('input[type="url"]').val(item.url); list.append(row); });
        renumberRows(studio);
        update(studio);
        studio.data('falussLinkInitialState', formState(studio));
    }
    function insertStoredLink(studio, values, collectionId) {
        var store = studio.find('[data-fl-block-store]'), block = makeStoredBlock('link', values);
        if (collectionId) {
            var collection = storedBlock(studio, collectionId), next = collection.next();
            while (next.length && next.data('block-type') !== 'section_title') { collection = next; next = next.next(); }
            block.insertAfter(collection);
        } else {
            var firstCollection = store.children('[data-block-type="section_title"]').first();
            if (firstCollection.length) { block.insertBefore(firstCollection); } else { store.append(block); }
        }
        renumberBlocks(studio);
        update(studio);
        return block;
    }
    function insertStoredCollection(studio, name, description) {
        var store = studio.find('[data-fl-block-store]'), collection = makeStoredBlock('section_title', { value: name });
        store.append(collection);
        if ($.trim(description)) { store.append(makeStoredBlock('text', { value: description })); }
        renumberBlocks(studio);
        update(studio);
        return storedField(collection, 'block_id').val();
    }
    function collectionDescriptionBlock(collection) {
        var next = collection.next();
        return next.length && next.data('block-type') === 'text' ? next : $();
    }
    function syncLinkCard(studio, linkCard) {
        var block = storedBlock(studio, linkCard.data('block-id'));
        if (!block.length) { return false; }
        var label = $.trim(linkCard.find('[data-fl-link-label]').val() || '');
        var url = $.trim(linkCard.find('[data-fl-link-url]').val() || '');
        if (!label || !safeURL(url)) { showStatus(studio, 'Renseignez un nom et une URL HTTPS valide.', true); return false; }
        setStoredField(block, 'label', label);
        setStoredField(block, 'url', url);
        linkCard.find('.faluss-link-studio__link-summary span').first().text(label);
        renumberBlocks(studio);
        update(studio);
        return true;
    }
    function clearStatus(studio) {
        var notice = studio.find('.faluss-link-studio__notice');
        window.clearTimeout(studio.data('falussLinkStatusTimer'));
        window.clearTimeout(studio.data('falussLinkStatusFadeTimer'));
        studio.removeData('falussLinkStatusTimer').removeData('falussLinkStatusFadeTimer');
        notice.removeClass('is-visible').empty();
    }
    function showStatus(studio, message, error, temporary) {
        var notice = studio.find('.faluss-link-studio__notice');
        clearStatus(studio);
        notice.empty().append($('<p>', { 'class': 'faluss-link-notice' + (error ? ' is-error' : ''), text: message }));
        requestAnimationFrame(function () { notice.addClass('is-visible'); });
        if (temporary !== false) {
            studio.data('falussLinkStatusTimer', window.setTimeout(function () {
                notice.removeClass('is-visible');
                studio.data('falussLinkStatusFadeTimer', window.setTimeout(function () { clearStatus(studio); }, 180));
            }, statusLifetime));
        }
    }
    function appendMutationValue(data, key, value) {
        if (Array.isArray(value)) {
            value.forEach(function (item, index) {
                if (item && typeof item === 'object') { Object.keys(item).forEach(function (child) { data.append(key + '[' + index + '][' + child + ']', item[child]); }); }
                else { data.append(key + '[]', item); }
            });
        } else { data.append(key, value === null || typeof value === 'undefined' ? '' : value); }
    }
    function mutationData(studio, task) {
        var form = studio.find('.faluss-link-studio__form'), data = new FormData(), payload = typeof task.payload === 'function' ? task.payload() : task.payload;
        data.append('action', 'faluss_link_save_studio');
        data.append('faluss_studio_response', 'json');
        data.append('faluss_link_studio_nonce', form.find('[name="faluss_link_studio_nonce"]').val() || '');
        data.append('mutation', task.mutation);
        data.append('aggregate_version', studio.find('[data-fl-aggregate-version]').val() || studio.attr('data-faluss-studio-version') || '');
        data.append('faluss_studio_collection', studio.find('[data-fl-active-collection]').val() || '');
        Object.keys(payload || {}).forEach(function (key) { appendMutationValue(data, key, payload[key]); });
        return data;
    }
    function studioMutationQueue(studio) {
        var queue = studio.data('falussLinkMutationQueue');
        if (!queue) { queue = { running: false, pending: [], keyed: {} }; studio.data('falussLinkMutationQueue', queue); }
        return queue;
    }
    function drainStudioMutations(studio) {
        var queue = studioMutationQueue(studio), form = studio.find('.faluss-link-studio__form');
        if (queue.running || !queue.pending.length || !form.length) { return; }
        var task = queue.pending.shift();
        if (task.key) { delete queue.keyed[task.key]; }
        queue.running = true; studio.data('falussLinkSaving', true).addClass('is-saving');
        showStatus(studio, 'Enregistrement…', false, false);
        window.fetch(form.attr('action'), { method: 'POST', body: mutationData(studio, task), credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (response) { return response.json().catch(function () { return null; }).then(function (body) { return { ok: response.ok, body: body }; }); })
            .then(function (result) {
                var data = result.body && result.body.data ? result.body.data : {}, state = data.state || null;
                if (!result.ok || !result.body || !result.body.success) {
                    if (state) { hydrateCanonicalBlocks(studio, state); hydrateCanonicalForm(studio, state); }
                    throw new Error(data.message || 'Enregistrement impossible.');
                }
                if (state) { hydrateCanonicalBlocks(studio, state); }
                if (typeof task.onSaved === 'function') { task.onSaved(state || {}); }
                showStatus(studio, data.message || 'Studio enregistré.', false, true);
                if (!queue.pending.length) { studio.data('falussLinkInitialState', formState(studio)); }
                updateDirty(studio);
                task.resolvers.forEach(function (resolve) { resolve(true); });
            })
            .catch(function (error) { showStatus(studio, error.message || 'Enregistrement impossible.', true); task.resolvers.forEach(function (resolve) { resolve(false); }); })
            .finally(function () {
                queue.running = false; studio.data('falussLinkSaving', false).removeClass('is-saving');
                drainStudioMutations(studio);
            });
    }
    function enqueueStudioMutation(studio, mutation, payload, options) {
        options = options || {};
        return new Promise(function (resolve) {
            var queue = studioMutationQueue(studio), key = options.key || '';
            if (key && queue.keyed[key]) {
                queue.keyed[key].payload = (queue.keyed[key].payload && typeof queue.keyed[key].payload === 'object' && payload && typeof payload === 'object') ? Object.assign({}, queue.keyed[key].payload, payload) : payload;
                queue.keyed[key].onSaved = options.onSaved || queue.keyed[key].onSaved;
                queue.keyed[key].resolvers.push(resolve);
            } else {
                var task = { mutation: mutation, payload: payload || {}, key: key, onSaved: options.onSaved || null, resolvers: [resolve] };
                queue.pending.push(task); if (key) { queue.keyed[key] = task; }
            }
            drainStudioMutations(studio);
        });
    }
    function queueStudioSave(studio, mutation, payload, key) {
        var pendingKey = 'falussLinkPending-' + key;
        studio.data(pendingKey, Object.assign({}, studio.data(pendingKey) || {}, payload || {}));
        window.clearTimeout(studio.data('falussLinkSaveTimer-' + key));
        studio.data('falussLinkSaveTimer-' + key, window.setTimeout(function () {
            var consolidated = studio.data(pendingKey) || {}; studio.removeData(pendingKey);
            enqueueStudioMutation(studio, mutation, consolidated, { key: key });
        }, 450));
    }
    function studioFieldValue(studio, name) {
        var fields = studio.find('[name="' + name + '"]'), checkboxes = fields.filter('input[type="checkbox"]'), radios = fields.filter('input[type="radio"]'), first = fields.first();
        if (checkboxes.length) { return checkboxes.first().prop('checked') ? (checkboxes.first().val() || '1') : '0'; }
        if (radios.length) { return radios.filter(':checked').val() || ''; }
        return first.val() || '';
    }
    function studioFields(studio, names) {
        var payload = {};
        names.forEach(function (name) { if (studio.find('[name="' + name + '"]').length) { payload[name] = studioFieldValue(studio, name); } });
        return payload;
    }
    function socialNetworkPayload(studio) {
        var networks = [];
        studio.find('.faluss-link-studio__network-row').each(function () { networks.push({ network: $(this).find('select').val() || '', url: $(this).find('input[type="url"]').val() || '' }); });
        return networks;
    }
    function saveHeaderAndProfile(studio) {
        var profile = studioFields(studio, ['display_name', 'bio']);
        profile.publication_status = studio.find('input[name="publication_status"][type="checkbox"]').prop('checked') ? 'published' : 'draft';
        profile.avatar_attachment_id = studioFieldValue(studio, 'faluss_identity_avatar_id');
        return enqueueStudioMutation(studio, 'save_profile', profile).then(function (saved) {
            if (!saved) { return false; }
            var header = studioFields(studio, ['available', 'avatar_visible', 'avatar_border', 'name_font', 'name_treatment', 'name_color', 'alignment', 'social_layout', 'social_variant']);
            header.social_networks = socialNetworkPayload(studio);
            return enqueueStudioMutation(studio, 'save_header', header);
        });
    }
    function showScreen(studio, name) {
        studio.find('[data-fl-studio-screen]').each(function () {
            var screen = $(this), active = screen.data('fl-studio-screen') === name;
            screen.find('h2[tabindex]').removeAttr('tabindex');
            screen.prop('hidden', !active);
            if (active) { screen.removeAttr('inert'); } else { screen.attr('inert', ''); }
        });
        studio.attr('data-faluss-studio-screen', name);
        requestAnimationFrame(function () {
            /* Headings stay static. A create screen may focus its first real field,
               while the Ecosystem screen deliberately leaves focus on More. */
            if (name !== 'create-link' && name !== 'create-collection') { return; }
            var target = studio.find('[data-fl-studio-screen="' + name + '"] input:not([type="hidden"]), [data-fl-studio-screen="' + name + '"] textarea').first();
            if (target.length) { target.trigger('focus'); }
        });
    }
    function openCreate(studio) {
        if (activeTab(studio) !== 'links') { return; }
        var section = studio.find('[data-fl-active-section]').val() || 'all';
        showScreen(studio, section === 'collections' ? 'create-collection' : 'create-link');
    }
    function studioState(studio) {
        var expanded = studio.find('[data-fl-link-card] .faluss-link-studio__link-summary[aria-expanded="true"]').first().closest('[data-fl-link-card]');
        var collectionEditor = studio.find('[data-fl-collection-editor]').first();
        return {
            tab: activeTab(studio),
            section: studio.find('[data-fl-active-section]').val() || 'all',
            collection: studio.find('[data-fl-active-collection]').val() || '',
            expandedLink: expanded.length ? String(expanded.data('block-id')) : '',
            collectionEditorOpen: collectionEditor.length && !collectionEditor.prop('hidden'),
            scrollY: window.scrollY || window.pageYOffset || document.documentElement.scrollTop || 0
        };
    }
    function restoreEditorState(studio, state) {
        if (state.expandedLink) {
            var cardNode = studio.find('[data-fl-link-card][data-block-id="' + state.expandedLink + '"]').first();
            if (cardNode.length) {
                studio.find('[data-fl-link-card]').not(cardNode).find('.faluss-link-studio__link-summary').attr('aria-expanded', 'false');
                studio.find('[data-fl-link-card]').not(cardNode).find('.faluss-link-studio__link-details').prop('hidden', true);
                cardNode.find('.faluss-link-studio__link-summary').attr('aria-expanded', 'true');
                cardNode.find('.faluss-link-studio__link-details').prop('hidden', false);
            }
        }
        if (state.collectionEditorOpen && state.section === 'collection') {
            studio.find('[data-fl-collection-editor]').prop('hidden', false);
            studio.find('[data-fl-rename-collection]').attr('aria-expanded', 'true');
        }
    }
    function restoreStudioState(studio, state, focus) {
        state = state || { tab: 'links', section: 'all', collection: '', expandedLink: '', collectionEditorOpen: false, scrollY: 0 };
        showScreen(studio, 'main');
        activate(studio, state.tab, false);
        studio.find('[data-fl-active-collection]').val(state.collection || '');
        studio.attr('data-faluss-studio-collection', state.collection || '');
        activateSection(studio, state.section, false);
        restoreEditorState(studio, state);
        requestAnimationFrame(function () {
            updateCursor(studio);
            if (Number.isFinite(state.scrollY)) { window.scrollTo(0, Math.max(0, state.scrollY)); }
            if (focus) { studio.find('[data-fl-studio-back]').first().trigger('focus'); }
        });
    }
    function openEcosystem(studio) {
        if (!studio.length) { return; }
        if (!studio.find('[data-fl-preview]').prop('hidden')) { togglePreview(studio, false); }
        studio.data('falussLinkEcosystemReturn', studioState(studio));
        showScreen(studio, 'ecosystem');
    }
    function openSocialManager(studio) {
        if (!studio.length) { return; }
        showScreen(studio, 'main');
        activate(studio, 'style', false);
        activateSection(studio, 'header', false);
        requestAnimationFrame(function () {
            var target = studio.find('[data-fl-social-manager]').first();
            if (!target.length) { return; }
            if (target[0].scrollIntoView) { target[0].scrollIntoView({ block: 'center', behavior: reducedMotion() ? 'auto' : 'smooth' }); }
            target.trigger('focus');
        });
    }
    function closeTransient(studio) {
        if (!studio.find('[data-fl-preview]').prop('hidden')) { togglePreview(studio, false); return true; }
        var screen = studio.attr('data-faluss-studio-screen') || 'main';
        if (screen === 'create-link') { restoreStudioState(studio, { tab: 'links', section: 'all', collection: '' }, true); return true; }
        if (screen === 'create-collection') { restoreStudioState(studio, { tab: 'links', section: 'collections', collection: '' }, true); return true; }
        if (screen === 'ecosystem') { restoreStudioState(studio, studio.data('falussLinkEcosystemReturn'), true); return true; }
        if (screen !== 'main') { showScreen(studio, 'main'); return true; }
        if (studio.find('[data-fl-active-section]').val() === 'collection') { activateSection(studio, 'collections', true); return true; }
        return false;
    }
    function safeHistoryBack(studio) {
        var fallback = studio.attr('data-faluss-studio-back-url') || '/';
        try {
            var referrer = document.referrer ? new URL(document.referrer) : null;
            if (window.history.length > 1 && referrer && referrer.origin === window.location.origin && referrer.href !== window.location.href) {
                window.history.back();
                return;
            }
        } catch (error) { /* Fall through to the server-provided local URL. */ }
        window.location.assign(fallback);
    }
    function legacyCopyText(value) {
        return new Promise(function (resolve, reject) {
            var field = document.createElement('textarea');
            field.value = value;
            field.setAttribute('readonly', '');
            field.style.position = 'fixed';
            field.style.opacity = '0';
            document.body.appendChild(field);
            field.select();
            try { document.execCommand('copy') ? resolve() : reject(new Error('Copie indisponible.')); }
            catch (error) { reject(error); }
            finally { document.body.removeChild(field); }
        });
    }
    function copyText(value) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(value).catch(function () { return legacyCopyText(value); });
        }
        return legacyCopyText(value);
    }
    function sharePublicURL(studio, value) {
        var url;
        try { url = new URL(value, window.location.origin); }
        catch (error) { showStatus(studio, 'Partage indisponible.', true); return; }
        if (url.protocol !== 'https:' || !url.hostname || /\/mon-faluss\/?$/.test(url.pathname)) {
            showStatus(studio, 'Partage indisponible.', true);
            return;
        }
        if (navigator.share) {
            navigator.share({ title: 'Mon Faluss', url: url.href })
                .then(function () { showStatus(studio, 'Lien public partagé.', false, true); })
                .catch(function (error) {
                    if (error && error.name === 'AbortError') { return; }
                    copyText(url.href).then(function () { showStatus(studio, 'Lien public copié.', false, true); }, function () { showStatus(studio, 'Partage indisponible.', true); });
                });
            return;
        }
        copyText(url.href).then(function () { showStatus(studio, 'Lien public copié.', false, true); }, function () { showStatus(studio, 'Partage indisponible.', true); });
    }
    function togglePreview(studio) {
        var preview = studio.find('[data-fl-preview]'), button = studio.find('[data-fl-preview-toggle]');
        if (!preview.length) { return; }
        var requested = arguments.length > 1 ? arguments[1] : null;
        var opening = requested === null ? preview.prop('hidden') : !!requested;
        preview.prop('hidden', !opening);
        button.attr('aria-expanded', opening ? 'true' : 'false');
        if (opening) {
            requestAnimationFrame(function () {
                if (preview[0].scrollIntoView) { preview[0].scrollIntoView({ block: 'start', behavior: reducedMotion() ? 'auto' : 'smooth' }); }
                try { preview[0].focus({ preventScroll: true }); } catch (error) { preview.trigger('focus'); }
            });
        }
    }
    function initialize(root) {
        $(root).find('.faluss-link-studio').addBack('.faluss-link-studio').each(function () {
            var studio = $(this);
            if (studio.data('falussLinkStudioReady')) { updateCursor(studio); return; }
            studio.data('falussLinkStudioReady', true);
            clearStatus(studio);
            ensureTeaserFormats(studio);
            renumberBlocks(studio);
            showScreen(studio, 'main');
            activate(studio, activeTab(studio), false);
            update(studio);
            studio.data('falussLinkInitialState', formState(studio));
            updateDirty(studio);
            updateCreateAction(studio);
            if (/(?:^|[?&])faluss_studio_notice=/.test(window.location.search)) { window.scrollTo({ top: 0, behavior: 'auto' }); }
        });
    }

    $(document).off('.falussLink')
        .on('click.falussLink', '.faluss-link-editor__select-cover', function () {
            var root = $(this).closest('.faluss-link-editor__media');
            var input = $('<input>', { type: 'file', accept: 'image/jpeg,image/png,image/webp,image/gif' });
            var studio = $(this).closest('.faluss-link-studio');
            input.on('change', function () { uploadCover(root, this.files[0], studio); }).trigger('click');
        })
        .on('click.falussLink', '.faluss-link-editor__remove-cover', function () {
            var root = $(this).closest('.faluss-link-editor__media'), studio = $(this).closest('.faluss-link-studio');
            root.find('.faluss-link-editor__cover-id').val('');
            root.find('.faluss-link-editor__cover-preview').empty();
            root.find('.faluss-link-editor__remove-cover').prop('hidden', true);
            if (studio.length) {
                var preview = card(studio);
                preview.find('.faluss-link-card__cover').empty().prop('hidden', true);
                preview.removeClass('faluss-link-card--cover-yes').addClass('faluss-link-card--cover-no');
                update(studio);
                enqueueStudioMutation(studio, 'save_appearance', { cover_attachment_id: 0 }, { key: 'appearance:cover_attachment_id' });
            }
        })
        .on('click.falussLink', '.faluss-link-studio [data-fl-tab]', function () { activate($(this).closest('.faluss-link-studio'), $(this).data('fl-tab'), true); })
        .on('click.falussLink', '.faluss-link-studio [data-fl-context-tab]', function () { activateSection($(this).closest('.faluss-link-studio'), $(this).data('fl-context-tab'), true); })
        .on('click.falussLink', '.faluss-link-theme-picker__theme', function () { var studio = $(this).closest('.faluss-link-studio'), selected = $(this).data('faluss-theme'); applyTheme(studio, selected); enqueueStudioMutation(studio, 'save_appearance', { selected_theme: selected }, { key: 'appearance:selected_theme' }); })
        .on('click.falussLink', '.faluss-link-studio [data-fl-preview-toggle]', function () { togglePreview($(this).closest('.faluss-link-studio')); })
        .on('click.falussLink', '.faluss-link-studio [data-fl-preview-close]', function () { togglePreview($(this).closest('.faluss-link-studio'), false); })
        .on('click.falussLink', '.faluss-link-studio [data-fl-studio-back]', function () {
            var studio = $(this).closest('.faluss-link-studio');
            if (!closeTransient(studio)) { safeHistoryBack(studio); }
        })
        .on('click.falussLink', '.faluss-link-studio [data-fl-studio-share]', function () { sharePublicURL($(this).closest('.faluss-link-studio'), $(this).attr('data-public-url')); })
        .on('click.falussLink', '.faluss-link-studio [data-fl-studio-ecosystem]', function () { openEcosystem($(this).closest('.faluss-link-studio')); })
        .on('click.falussLink', '.faluss-link-studio [data-fl-open-social-manager]', function () { openSocialManager($(this).closest('.faluss-link-studio')); })
        .on('click.falussLink', '.faluss-link-studio [data-fl-create]', function () { openCreate($(this).closest('.faluss-link-studio')); })
        .on('submit.falussLink', '.faluss-link-studio__form', function (event) {
            event.preventDefault();
            var studio = $(this).closest('.faluss-link-studio'), section = studio.find('[data-fl-active-section]').val() || '';
            if (section === 'header') { saveHeaderAndProfile(studio); }
            else if (section === 'link-style') { enqueueStudioMutation(studio, 'save_link_style', studioFields(studio, ['button_color', 'link_style'])); }
            else { enqueueStudioMutation(studio, 'save_appearance', studioFields(studio, ['cover_attachment_id', 'page_background', 'hero_transition_color', 'hero_transition_intensity', 'hero_transition_position'])); }
        })
        .on('click.falussLink', '.faluss-link-studio [data-fl-create-link-submit]', function () {
            var studio = $(this).closest('.faluss-link-studio'), screen = $(this).closest('[data-fl-studio-screen]');
            var label = $.trim(screen.find('[data-fl-new-link-label]').val() || ''), url = $.trim(screen.find('[data-fl-new-link-url]').val() || '');
            if (!label || !safeURL(url)) { showStatus(studio, 'Renseignez un nom et une URL HTTPS valide.', true); return; }
            if (storedBlocks(studio).length >= 32) { showStatus(studio, 'Votre carte contient déjà le nombre maximal d’éléments.', true); return; }
            var blockID = blockId(), collectionID = studio.find('[data-fl-active-collection]').val() || '';
            enqueueStudioMutation(studio, 'create_link', { block_id: blockID, label: label, url: url, collection_id: collectionID }, { onSaved: function () {
                restoreStudioState(studio, { tab: 'links', section: 'all', collection: '' }, true);
            } });
        })
        .on('click.falussLink', '.faluss-link-studio [data-fl-create-collection-submit]', function () {
            var studio = $(this).closest('.faluss-link-studio'), screen = $(this).closest('[data-fl-studio-screen]');
            var name = $.trim(screen.find('[data-fl-new-collection-name]').val() || ''), description = $.trim(screen.find('[data-fl-new-collection-description]').val() || '');
            if (!name) { showStatus(studio, 'Le nom de la collection est requis.', true); return; }
            if (storedBlocks(studio).length + (description ? 2 : 1) > 32) { showStatus(studio, 'Votre carte contient déjà le nombre maximal d’éléments.', true); return; }
            enqueueStudioMutation(studio, 'create_collection', { block_id: blockId(), description_block_id: blockId(), name: name, description: description }, { onSaved: function () { restoreStudioState(studio, { tab: 'links', section: 'collections', collection: '' }, true); } });
        })
        .on('click.falussLink', '.faluss-link-studio [data-fl-link-card] .faluss-link-studio__link-summary', function () {
            var cardNode = $(this).closest('[data-fl-link-card]'), studio = cardNode.closest('.faluss-link-studio'), opening = $(this).attr('aria-expanded') !== 'true';
            studio.find('[data-fl-link-card]').not(cardNode).each(function () { $(this).find('.faluss-link-studio__link-summary').attr('aria-expanded', 'false'); $(this).find('.faluss-link-studio__link-details').prop('hidden', true); });
            $(this).attr('aria-expanded', opening ? 'true' : 'false');
            cardNode.find('.faluss-link-studio__link-details').prop('hidden', !opening);
        })
        .on('click.falussLink', '.faluss-link-studio [data-fl-save-link]', function () {
            var studio = $(this).closest('.faluss-link-studio'), linkCard = $(this).closest('[data-fl-link-card]'), label = $.trim(linkCard.find('[data-fl-link-label]').val() || ''), url = $.trim(linkCard.find('[data-fl-link-url]').val() || '');
            if (!label || !safeURL(url)) { showStatus(studio, 'Renseignez un nom et une URL HTTPS valide.', true); return; }
            enqueueStudioMutation(studio, 'update_link', { block_id: String(linkCard.data('block-id') || ''), label: label, url: url, attachment_id: linkCard.find('[data-fl-link-attachment]').val() || 0, visibility: linkCard.find('[data-fl-link-visibility]').val() || 'all' });
        })
        .on('click.falussLink', '.faluss-link-studio [data-fl-delete-link]', function () {
            var studio = $(this).closest('.faluss-link-studio'), linkCard = $(this).closest('[data-fl-link-card]');
            enqueueStudioMutation(studio, 'delete_link', { block_id: String(linkCard.data('block-id') || '') });
        })
        .on('click.falussLink', '.faluss-link-studio [data-fl-rename-collection]', function () {
            var editor = $(this).siblings('[data-fl-collection-editor]'), opening = editor.prop('hidden');
            editor.prop('hidden', !opening); $(this).attr('aria-expanded', opening ? 'true' : 'false');
        })
        .on('click.falussLink', '.faluss-link-studio [data-fl-save-collection]', function () {
            var studio = $(this).closest('.faluss-link-studio'), editor = $(this).closest('[data-fl-collection-editor]');
            var name = $.trim(editor.find('[data-fl-collection-name]').val() || ''), description = $.trim(editor.find('[data-fl-collection-description]').val() || ''), collectionID = String($(this).data('fl-save-collection') || '');
            if (!name || !collectionID) { showStatus(studio, 'Le nom de la collection est requis.', true); return; }
            enqueueStudioMutation(studio, 'update_collection', { block_id: collectionID, name: name, description: description });
        })
        .on('click.falussLink', '.faluss-link-studio [data-fl-dissolve-collection]', function () {
            var studio = $(this).closest('.faluss-link-studio'), collectionID = String($(this).data('fl-dissolve-collection') || '');
            if (!collectionID) { return; }
            enqueueStudioMutation(studio, 'dissolve_collection', { block_id: collectionID }, { onSaved: function () { activateSection(studio, 'collections', false); } });
        })
        .on('click.falussLink', '.faluss-link-studio [data-fl-color-field][data-fl-color]', function () {
            var studio = $(this).closest('.faluss-link-studio'), field = $(this).data('fl-color-field'), value = $(this).data('fl-color');
            studio.find('[name="' + field + '"]').val(value).trigger('change');
            studio.find('[data-fl-color-field="' + field + '"]').attr('aria-pressed', 'false'); $(this).attr('aria-pressed', 'true');
        })
        .on('click.falussLink', '.faluss-link-studio [data-fl-open-color]', function () {
            var studio = $(this).closest('.faluss-link-studio'), field = $(this).attr('data-fl-open-color');
            studio.find('.faluss-link-studio__native-color[name="' + field + '"]').trigger('click');
        })
        .on('keydown.falussLink', '.faluss-link-studio [data-fl-tab]', function (event) {
            if (!/ArrowLeft|ArrowRight|Home|End/.test(event.key)) { return; }
            var tabs = $(this).closest('.faluss-link-studio').find('[data-fl-tab]'), index = tabs.index(this);
            if (event.key === 'ArrowRight') { index = (index + 1) % tabs.length; }
            if (event.key === 'ArrowLeft') { index = (index + tabs.length - 1) % tabs.length; }
            if (event.key === 'Home') { index = 0; }
            if (event.key === 'End') { index = tabs.length - 1; }
            event.preventDefault();
            activate($(this).closest('.faluss-link-studio'), tabs.eq(index).data('fl-tab'), true);
        })
        .on('keydown.falussLink', '.faluss-link-studio [data-fl-context-tab]', function (event) {
            if (!/ArrowLeft|ArrowRight|Home|End/.test(event.key)) { return; }
            var tabs = $(this).closest('.faluss-link-studio__context-tabs').find('[data-fl-context-tab]'), index = tabs.index(this);
            if (event.key === 'ArrowRight') { index = (index + 1) % tabs.length; }
            if (event.key === 'ArrowLeft') { index = (index + tabs.length - 1) % tabs.length; }
            if (event.key === 'Home') { index = 0; }
            if (event.key === 'End') { index = tabs.length - 1; }
            event.preventDefault(); activateSection($(this).closest('.faluss-link-studio'), tabs.eq(index).data('fl-context-tab'), true);
        })
        .on('click.falussLink', '.faluss-link-content-composer__add-button', function () {
            var studio = $(this).closest('.faluss-link-studio'), composer = $(this).closest('.faluss-link-content-composer');
            var list = composer.find('.faluss-link-content-composer__list'), type = composer.find('.faluss-link-content-composer__type').val();
            if (list.find('.faluss-link-content-block').length < 32 && /^(section_title|text|link|media_teaser)$/.test(type || '')) {
                list.append(contentBlock(type)); renumberBlocks(studio); update(studio);
            }
        })
        .on('click.falussLink', '.faluss-link-studio__add-network', function () {
            var studio = $(this).closest('.faluss-link-studio'), list = $(this).siblings('.faluss-link-studio__network-list');
            list.append(networkRow(list.find('.faluss-link-studio__network-row').length)); update(studio);
        })
        .on('click.falussLink', '.faluss-link-content-block [data-fl-block-action]', function () {
            var studio = $(this).closest('.faluss-link-studio'), block = $(this).closest('.faluss-link-content-block'), action = $(this).data('fl-block-action');
            if (action === 'up') { block.prev('.faluss-link-content-block').before(block); }
            else if (action === 'down') { block.next('.faluss-link-content-block').after(block); }
            else { block.remove(); }
            renumberBlocks(studio); update(studio);
        })
        .on('click.falussLink', '.faluss-link-content-block__select-teaser', function () {
            var block = $(this).closest('.faluss-link-content-block'), input = $('<input>', { type: 'file', accept: 'image/jpeg,image/png,image/webp,image/gif' });
            input.on('change', function () { uploadTeaser(block, this.files[0], block.closest('.faluss-link-studio')); }).trigger('click');
        })
        .on('click.falussLink', '.faluss-link-content-block__remove-teaser', function () {
            var block = $(this).closest('.faluss-link-content-block');
            block.find('[data-fl-block-field="attachment_id"]').val(''); block.find('.faluss-link-content-block__media-preview').empty(); update(block.closest('.faluss-link-studio'));
        })
        .on('change.falussLink', '.faluss-link-content-block [data-fl-block-field="access_mode"]', function () {
            var block = $(this).closest('.faluss-link-content-block');
            syncTeaserAccess(block); update(block.closest('.faluss-link-studio'));
        })
        .on('click.falussLink', '.faluss-link-studio__remove-row', function () {
            var studio = $(this).closest('.faluss-link-studio');
            $(this).closest('.faluss-link-studio__network-row').remove(); renumberRows(studio); update(studio);
        })
        .on('input.falussLink change.falussLink', '.faluss-link-studio input,.faluss-link-studio textarea,.faluss-link-studio select', function () {
            var studio = $(this).closest('.faluss-link-studio');
            if (!studio.data('falussLinkApplyingTheme')) { markThemeOverride(studio, $(this).attr('name')); }
            update(studio);
            var name = $(this).attr('name') || '', type = (this.type || '').toLowerCase();
            if (type === 'radio' && !$(this).prop('checked')) { return; }
            var mutation = '';
            if (/^(?:page_background|hero_transition_color|hero_transition_intensity|hero_transition_position)$/.test(name)) { mutation = 'save_appearance'; }
            else if (/^(?:available|avatar_visible|avatar_border|name_font|name_treatment|name_color|alignment|social_layout|social_variant)$/.test(name)) { mutation = 'save_header'; }
            else if (/^(?:button_color|link_style)$/.test(name)) { mutation = 'save_link_style'; }
            else if (name === 'publication_status') { mutation = 'save_profile'; }
            if (mutation && (/^(?:checkbox|radio|color|range)$/.test(type) || this.tagName.toLowerCase() === 'select')) {
                var payload = {}; payload[name === 'publication_status' ? 'publication_status' : name] = name === 'publication_status' ? ($(this).prop('checked') ? 'published' : 'draft') : studioFieldValue(studio, name);
                queueStudioSave(studio, mutation, payload, mutation);
            }
        })
        .on('change.falussLink', '.faluss-link-studio [name="faluss_identity_avatar"]', function () {
            var studio = $(this).closest('.faluss-link-studio'), file = this.files[0];
            if (!file || !/^image\//.test(file.type || '')) { return; }
            var reader = new FileReader();
            reader.onload = function (event) { card(studio).find('.faluss-link-card__avatar').empty().append($('<img>', { src: event.target.result, alt: '' })); studio.find('.faluss-link-studio__avatar').empty().append($('<img>', { src: event.target.result, alt: '' })); update(studio); };
            reader.readAsDataURL(file);
            uploadStudioAvatar(studio, file);
        });

    $(document).on('keydown.falussLinkPreview', function (event) {
        if (event.key !== 'Escape') { return; }
        $('.faluss-link-studio').each(function () {
            var studio = $(this);
            if (!studio.find('[data-fl-preview]').prop('hidden') || (studio.attr('data-faluss-studio-screen') || 'main') !== 'main') { closeTransient(studio); }
        });
    });

    $(function () {
        initialize(document);
        if (window.elementorFrontend && window.elementorFrontend.hooks) {
            window.elementorFrontend.hooks.addAction('frontend/element_ready/faluss_link_studio.default', function (scope) { initialize(scope); });
            window.elementorFrontend.hooks.addAction('frontend/element_ready/faluss_link_appearance.default', function (scope) { initialize(scope); });
        }
    });
    $(window).on('elementor/frontend/init', function () {
        if (window.elementorFrontend && window.elementorFrontend.hooks) {
            window.elementorFrontend.hooks.addAction('frontend/element_ready/faluss_link_studio.default', function (scope) { initialize(scope); });
            window.elementorFrontend.hooks.addAction('frontend/element_ready/faluss_link_appearance.default', function (scope) { initialize(scope); });
        }
    });
}(jQuery));
