(function () {
    'use strict';

    var initialized = new WeakSet();
    var previewRequests = new WeakMap();
    var order = ['name', 'avatar', 'header', 'style', 'socials', 'links', 'finish'];
    var transitionDuration = 230;
    var layouts = { name: 'name', avatar: 'upload', socials: 'list', links: 'list' };

    function config() { return window.falussLinkOnboarding || {}; }
    function onboardingTheme(slug) { return (config().themes || []).filter(function (item) { return item && item.slug === slug; })[0] || null; }
    function applyOnboardingTheme(root, slug) {
        var theme = onboardingTheme(slug), selected = root.querySelector('[name="selected_theme"]'), overrides = root.querySelector('[name="theme_overrides"]');
        if (!theme || theme.locked || !selected) { showError(root, theme && theme.locked ? 'Ce thème nécessite un droit actif.' : 'Ce thème est indisponible.'); return false; }
        root.dataset.applyingTheme = '1'; selected.value = theme.slug;
        if (overrides) { overrides.value = '[]'; }
        var background = root.querySelector('[name="page_background"]'); if (background) { background.value = theme.page_background || '#FFFDF5'; }
        root.querySelectorAll('[name="link_style"]').forEach(function (input) { input.checked = input.value === (theme.link_style === 'dark' ? 'solid' : theme.link_style); });
        root.querySelectorAll('.faluss-link-theme-picker__theme').forEach(function (button) { button.setAttribute('aria-pressed', button.dataset.falussTheme === theme.slug ? 'true' : 'false'); });
        syncBackgroundSwatches(root); syncChoiceSelections(root); preview(root); delete root.dataset.applyingTheme; scheduleSharedPreview(root); return true;
    }
    function markOnboardingOverride(root, name) {
        if (root.dataset.applyingTheme || !/^(page_background|link_style)$/.test(name || '')) { return; }
        var input = root.querySelector('[name="theme_overrides"]'), values = [];
        if (!input) { return; }
        try { values = JSON.parse(input.value || '[]'); } catch (error) { values = []; }
        if (!Array.isArray(values)) { values = []; }
        if (values.indexOf(name) === -1) { values.push(name); input.value = JSON.stringify(values); }
    }
    function stepName(value) { return order.indexOf(value) !== -1 ? value : 'name'; }
    function panel(root, step) { return root.querySelector('[data-onboarding-panel="' + stepName(step) + '"]'); }
    function reduceMotion() { return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches; }
    function layoutName(step) { return layouts[stepName(step)] || 'preview'; }
    function prepareViewportHost(root) {
        var body = document.body;
        if (!body || (!body.classList.contains('faluss-link-onboarding-route') && !body.classList.contains('faluss-identity-onboarding-route'))) { return; }
        document.documentElement.classList.add('faluss-link-onboarding-document');
        var ancestor = root.parentElement;
        while (ancestor && ancestor !== body) {
            ancestor.setAttribute('data-faluss-onboarding-host', '');
            ancestor = ancestor.parentElement;
        }
    }
    function request(root, action, form, extra) {
        var data = new FormData(form);
        data.set('action', action);
        data.set('nonce', (extra && extra.avatar) ? (config().avatarNonce || '') : (config().nonce || ''));
        Object.keys(extra || {}).forEach(function (key) { if (key !== 'avatar') { data.set(key, extra[key]); } });
        return fetch(config().url || '', { method: 'POST', credentials: 'same-origin', body: data }).then(function (response) {
            return response.json().catch(function () { return { success: false }; });
        });
    }
    function announce(root, message) {
        var target = root.querySelector('.faluss-link-onboarding__status');
        if (!target) { return; }
        target.textContent = message || '';
    }
    function showError(root, message) {
        var target = root.querySelector('.faluss-link-onboarding__error');
        if (!target) { return; }
        target.textContent = message || '';
        target.hidden = !message;
    }
    function setPending(root, value) {
        root.querySelectorAll('[data-onboarding-next],[data-onboarding-back],[data-onboarding-skip],[data-onboarding-finish]').forEach(function (button) {
            button.disabled = value;
            button.setAttribute('aria-busy', value ? 'true' : 'false');
        });
    }
    function setPanelAvailability(target, active) {
        target.hidden = !active;
        if (active) { target.removeAttribute('inert'); } else { target.setAttribute('inert', ''); }
        target.setAttribute('aria-hidden', active ? 'false' : 'true');
        target.classList.toggle('is-active', active);
    }
    function updateChrome(root, step) {
        var current = stepName(step), index = order.indexOf(current), currentPanel = panel(root, current);
        var gauge = root.querySelector('[role="progressbar"]'), text = root.querySelector('[data-onboarding-progress-text]');
        var title = currentPanel ? currentPanel.querySelector('h2') : null;
        var progressText = 'Étape ' + (index + 1) + ' sur ' + order.length + (title ? ' : ' + title.textContent.trim() : '');
        root.dataset.currentStep = current;
        root.dataset.onboardingLayout = layoutName(current);
        if (document.body && document.body.classList.contains('faluss-link-onboarding-route')) {
            document.body.dataset.falussOnboardingLayout = layoutName(current);
        }
        root.style.setProperty('--flo-progress-scale', String((index + 1) / order.length));
        if (gauge) {
            gauge.setAttribute('aria-valuenow', String(index + 1));
            gauge.setAttribute('aria-valuetext', progressText);
        }
        if (text) { text.textContent = progressText; }
        var back = root.querySelector('[data-onboarding-back]'), skip = root.querySelector('[data-onboarding-skip]'), next = root.querySelector('[data-onboarding-next]'), finish = root.querySelector('[data-onboarding-finish]');
        if (back) { back.hidden = index === 0; }
        if (skip) { skip.hidden = current !== 'avatar' && current !== 'socials' && current !== 'links'; }
        if (next) { next.hidden = current === 'finish'; }
        if (finish) { finish.hidden = current !== 'finish'; }
        if (current === 'links') { socialInputs(root); }
    }
    function focusPanel(root, step) {
        var heading = panel(root, step);
        heading = heading ? heading.querySelector('h2') : null;
        if (heading) { heading.setAttribute('tabindex', '-1'); heading.focus({ preventScroll: true }); }
    }
    function choiceTabs(root, group) {
        return Array.prototype.slice.call(root.querySelectorAll('[data-onboarding-choice-tab][data-onboarding-choice-group="' + group + '"]'));
    }
    function setChoicePanel(root, group, target, focus) {
        var tabs = choiceTabs(root, group), selected = null, selectedIndex = 0, animate = !!focus && !reduceMotion();
        tabs.forEach(function (tab, index) {
            var active = tab.dataset.onboardingChoiceTarget === target;
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
            tab.tabIndex = active ? 0 : -1;
            if (active) { selected = tab; selectedIndex = index; }
        });
        if (tabs[0] && tabs[0].parentElement) { tabs[0].parentElement.dataset.activeIndex = String(selectedIndex); }
        root.querySelectorAll('[data-onboarding-choice-panel][data-onboarding-choice-group="' + group + '"]').forEach(function (choicePanel) {
            var active = choicePanel.dataset.onboardingChoicePanelName === target;
            choicePanel.hidden = !active;
            choicePanel.classList.remove('is-entering');
            if (active) {
                choicePanel.removeAttribute('inert');
                if (animate) {
                    choicePanel.classList.add('is-entering');
                    window.requestAnimationFrame(function () {
                        window.requestAnimationFrame(function () { choicePanel.classList.remove('is-entering'); });
                    });
                }
            } else {
                choicePanel.setAttribute('inert', '');
            }
        });
        if (focus && selected) { selected.focus({ preventScroll: true }); }
    }
    function initialiseChoicePanels(root) {
        ['header', 'style'].forEach(function (group) {
            var active = choiceTabs(root, group).filter(function (tab) { return tab.getAttribute('aria-selected') === 'true'; })[0];
            if (active) { setChoicePanel(root, group, active.dataset.onboardingChoiceTarget || '', false); }
        });
    }
    function syncBackgroundSwatches(root) {
        var color = root.querySelector('[name="page_background"]');
        if (!color) { return; }
        var normalized = String(color.value || '').toUpperCase();
        root.querySelectorAll('[data-onboarding-background-choice]').forEach(function (choice) {
            choice.checked = String(choice.value || '').toUpperCase() === normalized;
        });
    }
    function syncChoiceSelections(root) {
        root.querySelectorAll('.faluss-link-onboarding__choice input,.faluss-link-onboarding__button-choice input').forEach(function (input) {
            var choice = input.closest('label');
            if (choice) { choice.classList.toggle('is-selected', !!input.checked); }
        });
    }
    function showInitial(root, step) {
        var current = stepName(step);
        root.querySelectorAll('[data-onboarding-panel]').forEach(function (item) {
            setPanelAvailability(item, item.getAttribute('data-onboarding-panel') === current);
        });
        updateChrome(root, current);
    }
    function transition(root, step, direction) {
        var next = stepName(step), current = stepName(root.dataset.currentStep), currentPanel = panel(root, current), nextPanel = panel(root, next);
        if (!currentPanel || !nextPanel || current === next || reduceMotion()) {
            showInitial(root, next);
            focusPanel(root, next);
            return Promise.resolve();
        }
        return new Promise(function (resolve) {
            root.dataset.transitionDirection = direction === 'backward' ? 'backward' : 'forward';
            root.classList.add('is-transitioning');
            nextPanel.hidden = false;
            nextPanel.removeAttribute('inert');
            nextPanel.setAttribute('aria-hidden', 'false');
            nextPanel.classList.add('is-entering');
            currentPanel.setAttribute('inert', '');
            currentPanel.setAttribute('aria-hidden', 'true');
            window.requestAnimationFrame(function () {
                window.requestAnimationFrame(function () {
                    currentPanel.classList.remove('is-active');
                    currentPanel.classList.add('is-leaving');
                    nextPanel.classList.remove('is-entering');
                    nextPanel.classList.add('is-active');
                    updateChrome(root, next);
                    window.setTimeout(function () {
                        currentPanel.hidden = true;
                        currentPanel.classList.remove('is-leaving');
                        root.classList.remove('is-transitioning');
                        focusPanel(root, next);
                        resolve();
                    }, transitionDuration);
                });
            });
        });
    }
    function preview(root) {
        var card = root.querySelector('[data-onboarding-preview-card] .faluss-link-card');
        if (!card) { return; }
        var name = root.querySelector('[name="display_name"]'), background = root.querySelector('[name="page_background"]:checked,[name="page_background"]');
        var treatment = root.querySelector('[name="name_treatment"]'), nameFont = root.querySelector('[name="name_font"]'), avatarBorder = root.querySelector('[name="avatar_border"]:checked'), linkStyle = root.querySelector('[name="link_style"]:checked');
        var nameTarget = card.querySelector('.faluss-link-card__name');
        var avatarName = root.querySelector('[data-onboarding-avatar-name]');
        if (nameTarget && name && name.value.trim()) { nameTarget.textContent = name.value.trim(); }
        if (avatarName && name && name.value.trim()) { avatarName.textContent = name.value.trim(); }
        if (background && /^#[0-9a-f]{6}$/i.test(background.value || '')) {
            card.style.setProperty('--fl-page-background', background.value);
            card.style.setProperty('--fl-canvas', background.value);
        }
        if (nameFont && nameFont.selectedOptions && nameFont.selectedOptions[0]) { card.style.setProperty('--fl-name-font', nameFont.selectedOptions[0].dataset.fontStack || 'Outfit, ui-sans-serif, system-ui, sans-serif'); }
        if (nameTarget && treatment) {
            nameTarget.classList.remove('faluss-link-card__name--strong', 'faluss-link-card__name--editorial');
            nameTarget.classList.add('faluss-link-card__name--' + treatment.value);
            if (treatment.selectedOptions && treatment.selectedOptions[0]) {
                card.style.setProperty('--fl-name-weight', treatment.selectedOptions[0].dataset.nameWeight || '800');
                card.style.setProperty('--fl-name-tracking', treatment.selectedOptions[0].dataset.nameTracking || '-.045em');
            }
        }
        card.classList.toggle('faluss-link-card--avatar-border-yes', !!(avatarBorder && avatarBorder.value === '1'));
        card.classList.toggle('faluss-link-card--avatar-border-no', !!(avatarBorder && avatarBorder.value !== '1'));
        if (linkStyle) {
            card.classList.remove('faluss-link-card--links-solid', 'faluss-link-card--links-outline', 'faluss-link-card--links-light');
            card.classList.add('faluss-link-card--links-' + linkStyle.value);
        }
        var alignment = root.querySelector('[name="alignment"]');
        if (alignment) {
            card.classList.remove('faluss-link-card--align-left', 'faluss-link-card--align-center');
            card.classList.add('faluss-link-card--align-' + alignment.value);
        }
        if (window.FalussLinkCard) { window.FalussLinkCard.refresh(card); }
    }
    function replacePreview(root, markup) {
        var target = root.querySelector('[data-onboarding-preview-card]');
        if (!target || !markup) { return; }
        target.replaceChildren();
        target.insertAdjacentHTML('beforeend', markup);
        if (window.FalussLinkCard) { window.FalussLinkCard.initialize(target); }
    }
    function cancelSharedPreview(root) {
        var state = previewRequests.get(root);
        if (!state) { return; }
        if (state.timer) { window.clearTimeout(state.timer); }
        if (state.controller) { state.controller.abort(); }
        previewRequests.delete(root);
    }
    function scheduleSharedPreview(root) {
        preview(root);
        var previous = previewRequests.get(root) || {};
        if (previous.timer) { window.clearTimeout(previous.timer); }
        if (previous.controller) { previous.controller.abort(); }
        var state = { timer: 0, controller: null };
        state.timer = window.setTimeout(function () {
            var form = root.querySelector('form'), data = form ? new FormData(form) : null;
            if (!data) { return; }
            var controller = typeof AbortController === 'function' ? new AbortController() : null;
            state.controller = controller;
            data.set('action', 'faluss_link_onboarding_preview');
            data.set('nonce', config().nonce || '');
            fetch(config().url || '', { method: 'POST', credentials: 'same-origin', body: data, signal: controller ? controller.signal : undefined }).then(function (response) {
                return response.json().catch(function () { return { success: false }; });
            }).then(function (result) {
                if (result && result.success && result.data && result.data.preview) { replacePreview(root, result.data.preview); preview(root); }
            }).catch(function (error) {
                if (!error || error.name !== 'AbortError') { announce(root, 'L’aperçu sera actualisé à la prochaine étape.'); }
            });
        }, 140);
        previewRequests.set(root, state);
    }
    function selectedNetworks(root) {
        return Array.prototype.slice.call(root.querySelectorAll('[name="social_selected[]"]:checked')).map(function (input) { return input.value; });
    }
    function socialInputs(root) {
        var wrap = root.querySelector('[data-onboarding-social-urls]');
        if (!wrap) { return; }
        var selected = selectedNetworks(root), retained = {};
        wrap.querySelectorAll('[data-onboarding-social-url]').forEach(function (row) { retained[row.getAttribute('data-onboarding-social-url')] = row.querySelector('input') ? row.querySelector('input').value : ''; });
        wrap.replaceChildren();
        selected.forEach(function (network) {
            var label = document.createElement('label'), input = document.createElement('input');
            label.dataset.onboardingSocialUrl = network;
            label.append(document.createTextNode(network.charAt(0).toUpperCase() + network.slice(1)));
            input.name = 'social_urls[' + network + ']'; input.type = 'text'; input.maxLength = 2048; input.placeholder = '@identifiant ou https://'; input.value = retained[network] || '';
            label.append(input); wrap.append(label);
        });
    }
    function linkRow() {
        var row = document.createElement('div'), id = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (value) { var random = Math.random() * 16 | 0; return (value === 'x' ? random : (random & 3 | 8)).toString(16); });
        row.className = 'faluss-link-onboarding__free-link';
        row.innerHTML = '<input name="wizard_links[' + id + '][block_id]" type="hidden" value="' + id + '"><label>Libellé<input name="wizard_links[' + id + '][label]" type="text" maxlength="80"></label><label>URL HTTPS<input name="wizard_links[' + id + '][url]" type="url" maxlength="2048" placeholder="https://"></label><button type="button" class="faluss-link-onboarding__remove-link" aria-label="Supprimer ce lien">×</button>';
        return row;
    }
    function uploadAvatar(root, file) {
        if (!file || !/^image\//.test(file.type || '')) { showError(root, 'Choisissez une image valide.'); return; }
        cancelSharedPreview(root);
        var form = root.querySelector('form'), data = new FormData(form);
        data.set('action', 'faluss_link_onboarding_upload_avatar'); data.set('nonce', config().avatarNonce || ''); data.set('avatar', file);
        setPending(root, true);
        showError(root, ''); announce(root, 'Ajout de la photo en cours.');
        fetch(config().url || '', { method: 'POST', credentials: 'same-origin', body: data }).then(function (response) { return response.json(); }).then(function (result) {
            if (!result || !result.success || !result.data || !result.data.id) { throw new Error('avatar'); }
            var input = form.querySelector('[name="faluss_identity_avatar_id"]'), previewTarget = root.querySelector('[data-onboarding-avatar-preview]'), cardAvatar = root.querySelector('[data-onboarding-preview-card] .faluss-link-card__avatar'), card = root.querySelector('[data-onboarding-preview-card] .faluss-link-card');
            if (input) { input.value = result.data.id; }
            if (previewTarget) { previewTarget.innerHTML = ''; var image = document.createElement('img'); image.src = result.data.url; image.alt = ''; previewTarget.append(image); }
            if (cardAvatar) { cardAvatar.hidden = false; cardAvatar.innerHTML = ''; var cardImage = document.createElement('img'); cardImage.src = result.data.url; cardImage.alt = ''; cardAvatar.append(cardImage); }
            if (card) { card.classList.remove('faluss-link-card--avatar-no'); card.classList.add('faluss-link-card--avatar-yes'); }
            scheduleSharedPreview(root); announce(root, 'Photo ajoutée.');
        }).catch(function () { showError(root, 'L’image n’a pas pu être ajoutée. Vous pouvez réessayer ou passer cette étape.'); announce(root, 'Échec de l’ajout de la photo.'); }).finally(function () { setPending(root, false); });
    }
    function saveCurrent(root, direction) {
        var current = stepName(root.dataset.currentStep), form = root.querySelector('form');
        if (current === 'name') {
            var name = form.querySelector('[name="display_name"]');
            if (!name || !name.value.trim()) { showError(root, 'Ajoutez un nom affiché pour continuer.'); if (name) { name.focus(); } return; }
        }
        cancelSharedPreview(root); setPending(root, true); showError(root, ''); announce(root, 'Enregistrement en cours.');
        request(root, 'faluss_link_onboarding_save', form, { step: current, direction: direction === 'backward' ? 'backward' : 'forward' }).then(function (result) {
            if (!result || !result.success || !result.data) { throw new Error('save'); }
            var next = String(result.data.step || '').replace(/^wizard_/, '');
            replacePreview(root, result.data.preview || '');
            return transition(root, next, direction).then(function () { preview(root); announce(root, 'Étape enregistrée.'); });
        }).catch(function () { showError(root, 'Cette étape n’a pas pu être enregistrée. Vérifiez les informations puis réessayez.'); announce(root, 'Échec de l’enregistrement.'); }).finally(function () { setPending(root, false); });
    }
    function init(root) {
        if (!(root instanceof HTMLElement) || initialized.has(root)) { return; }
        initialized.add(root); prepareViewportHost(root); initialiseChoicePanels(root); syncBackgroundSwatches(root); syncChoiceSelections(root); showInitial(root, root.dataset.currentStep || 'name'); preview(root);
        var form = root.querySelector('form');
        if (form) { form.addEventListener('submit', function (event) { event.preventDefault(); saveCurrent(root, 'forward'); }); }
        root.addEventListener('click', function (event) {
            var target = event.target.closest('button'); if (!target || !root.contains(target)) { return; }
            if (target.matches('[data-onboarding-skip]')) { event.preventDefault(); saveCurrent(root, 'forward'); }
            if (target.matches('[data-onboarding-back]')) { event.preventDefault(); saveCurrent(root, 'backward'); }
            if (target.matches('[data-onboarding-add-link]')) { event.preventDefault(); var wrap = root.querySelector('[data-onboarding-free-links]'); if (wrap) { wrap.append(linkRow()); scheduleSharedPreview(root); } }
            if (target.matches('.faluss-link-onboarding__remove-link')) { event.preventDefault(); target.closest('.faluss-link-onboarding__free-link').remove(); scheduleSharedPreview(root); }
            if (target.matches('[data-onboarding-avatar-select]')) { event.preventDefault(); var input = document.createElement('input'); input.type = 'file'; input.accept = 'image/jpeg,image/png,image/webp,image/gif'; input.addEventListener('change', function () { uploadAvatar(root, input.files && input.files[0]); }); input.click(); }
            if (target.matches('[data-onboarding-choice-tab]')) { event.preventDefault(); setChoicePanel(root, target.dataset.onboardingChoiceGroup || '', target.dataset.onboardingChoiceTarget || '', true); }
            if (target.matches('.faluss-link-theme-picker__theme')) { event.preventDefault(); applyOnboardingTheme(root, target.dataset.falussTheme || ''); }
            if (target.matches('[data-onboarding-finish]')) {
                event.preventDefault(); cancelSharedPreview(root); setPending(root, true); showError(root, ''); announce(root, 'Publication en cours.');
                request(root, 'faluss_link_onboarding_finish', root.querySelector('form'), {}).then(function (result) {
                    if (!result || !result.success || !result.data || !result.data.redirect) { throw new Error('finish'); }
                    window.location.assign(result.data.redirect);
                }).catch(function () { showError(root, 'La publication n’a pas pu être terminée. Vérifiez votre connexion puis réessayez.'); announce(root, 'Échec de la publication.'); }).finally(function () { setPending(root, false); });
            }
        });
        root.addEventListener('keydown', function (event) {
            var target = event.target;
            if (!target || !target.matches('[data-onboarding-choice-tab]')) { return; }
            var group = target.dataset.onboardingChoiceGroup || '', tabs = choiceTabs(root, group), index = tabs.indexOf(target), next = index;
            if (event.key === 'ArrowRight' || event.key === 'ArrowDown') { next = (index + 1) % tabs.length; }
            else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') { next = (index - 1 + tabs.length) % tabs.length; }
            else if (event.key === 'Home') { next = 0; }
            else if (event.key === 'End') { next = tabs.length - 1; }
            else { return; }
            event.preventDefault(); setChoicePanel(root, group, tabs[next].dataset.onboardingChoiceTarget || '', true);
        });
        root.addEventListener('change', function (event) {
            if (!event.target) { return; }
            if (event.target.matches('[name="social_selected[]"]')) { socialInputs(root); }
            if (event.target.matches('[data-onboarding-background-choice]')) {
                var color = root.querySelector('[name="page_background"]'); if (color) { color.value = event.target.value; }
            }
            if (event.target.matches('[name="page_background"]')) { syncBackgroundSwatches(root); }
            markOnboardingOverride(root, event.target.name || '');
            if (event.target.matches('.faluss-link-onboarding__choice input,.faluss-link-onboarding__button-choice input')) { syncChoiceSelections(root); }
            scheduleSharedPreview(root);
        });
        root.addEventListener('input', function (event) { if (event.target && event.target.matches('[name="page_background"]')) { syncBackgroundSwatches(root); } if (event.target) { markOnboardingOverride(root, event.target.name || ''); } scheduleSharedPreview(root); });
    }
    function boot(scope) { (scope || document).querySelectorAll('[data-faluss-link-onboarding]').forEach(init); }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', function () { boot(document); }, { once: true }); } else { boot(document); }
    window.addEventListener('elementor/frontend/init', function () { boot(document); });
    if (window.elementorFrontend && window.elementorFrontend.hooks) { window.elementorFrontend.hooks.addAction('frontend/element_ready/faluss_identity_onboarding.default', function (scope) { boot(scope && scope[0] ? scope[0] : document); }); }
}());
