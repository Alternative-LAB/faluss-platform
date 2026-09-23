(function () {
    'use strict';

    var config = window.falussMeStudioOnboarding || {};
    var root = document.querySelector('[data-me-studio-onboarding]');
    if (!root) { return; }

    var stepFields = {
        wizard_structure: ['structure'],
        wizard_atomic_warning: [],
        wizard_atomic_colors: ['page_background', 'button_color'],
        wizard_atomic_buttons: ['link_style', 'button_texture'],
        wizard_atomic_avatar_upload: ['avatar_attachment_id'],
        wizard_atomic_avatar: ['avatar_shape', 'avatar_effect'],
        wizard_atomic_wallpaper_upload: ['cover_attachment_id'],
        wizard_atomic_wallpaper: ['wallpaper_size', 'wallpaper_effect'],
        wizard_atomic_networks: ['social_style', 'social_color']
    };
    var previewTimer = 0;

    function message(text, error) {
        var status = root.querySelector('.faluss-me-onboarding-v2__status');
        var alert = root.querySelector('.faluss-me-onboarding-v2__error');
        if (status) { status.textContent = error ? '' : (text || ''); }
        if (alert) { alert.textContent = error ? (text || '') : ''; alert.hidden = !error; }
    }

    function selected(name) {
        var checked = root.querySelector('[name="' + name + '"]:checked');
        var field = root.querySelector('[name="' + name + '"]');
        if (field && field.type === 'radio') { return checked ? checked.value : ''; }
        return (checked || field) ? (checked || field).value : '';
    }

    function currentFields() {
        var result = {};
        (stepFields[root.dataset.step] || []).forEach(function (name) { result[name] = selected(name); });
        return result;
    }

    function encodedPost(data) {
        var body = new URLSearchParams();
        Object.keys(data).forEach(function (key) { body.append(key, data[key]); });
        return fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body: body.toString()
        }).then(function (response) {
            return response.json().then(function (payload) {
                payload.httpStatus = response.status;
                return payload;
            });
        });
    }

    function replacePreview(html) {
        var host = root.querySelector('[data-me-preview]');
        if (!host || !html) { return; }
        host.innerHTML = html;
    }

    function preview() {
        if (!root.querySelector('[data-me-preview]')) { return; }
        var data = currentFields();
        data.action = 'faluss_me_studio_preview';
        data.nonce = config.previewNonce || '';
        data.context = 'onboarding';
        encodedPost(data).then(function (response) {
            if (!response.success || !response.data) { throw new Error('preview'); }
            replacePreview(response.data.preview_html || '');
            message('Aperçu à jour.', false);
        }).catch(function () { message('L’aperçu n’a pas pu être actualisé.', true); });
    }

    function schedulePreview() {
        window.clearTimeout(previewTimer);
        previewTimer = window.setTimeout(preview, 160);
    }

    function submit(direction) {
        var data = currentFields();
        var button = root.querySelector('.faluss-me-onboarding-v2__continue');
        data.action = 'faluss_me_studio_onboarding';
        data.nonce = config.saveNonce || '';
        data.step = root.dataset.step || config.step || '';
        data.direction = direction;
        data.aggregate_version = root.dataset.aggregateVersion || '';
        if (button) { button.disabled = true; }
        message('Enregistrement…', false);
        encodedPost(data).then(function (response) {
            if (!response.success) {
                if (response.httpStatus === 409) { window.location.reload(); return; }
                throw new Error((response.data || {}).code || 'save');
            }
            window.location.reload();
        }).catch(function () {
            message('Cette étape n’a pas pu être enregistrée. Réessaie.', true);
            if (button) { button.disabled = false; }
        });
    }

    function activateTab(button, focus) {
        var tabs = Array.prototype.slice.call(button.parentNode.querySelectorAll('[role="tab"]'));
        var panels = Array.prototype.slice.call(root.querySelectorAll('[data-me-tab-panel]'));
        tabs.forEach(function (tab) {
            var active = tab === button;
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
            tab.tabIndex = active ? 0 : -1;
        });
        panels.forEach(function (panel) {
            var active = panel.getAttribute('data-me-tab-panel') === button.getAttribute('data-me-tab');
            panel.hidden = !active;
            if (active) { panel.removeAttribute('inert'); } else { panel.setAttribute('inert', ''); }
        });
        if (focus) { button.focus(); }
    }

    function tabKeydown(event) {
        if (!/^(ArrowLeft|ArrowRight|Home|End)$/.test(event.key)) { return; }
        var tabs = Array.prototype.slice.call(event.currentTarget.parentNode.querySelectorAll('[role="tab"]'));
        var index = tabs.indexOf(event.currentTarget);
        if (event.key === 'ArrowLeft') { index = (index + tabs.length - 1) % tabs.length; }
        if (event.key === 'ArrowRight') { index = (index + 1) % tabs.length; }
        if (event.key === 'Home') { index = 0; }
        if (event.key === 'End') { index = tabs.length - 1; }
        event.preventDefault();
        activateTab(tabs[index], true);
    }

    function syncStructure(source) {
        if (!source || !/^(simple|atomic)$/.test(source.value)) { return; }
        root.querySelectorAll('[name="structure"], [name="structure_card"]').forEach(function (radio) {
            radio.checked = radio.value === source.value;
        });
        var card = root.querySelector('[name="structure_card"][value="' + source.value + '"]');
        if (card && card.closest('label')) { card.closest('label').scrollIntoView({ block: 'nearest', inline: 'center' }); }
    }

    function syncCustomColor(input) {
        var name = input.dataset.colorTarget;
        if (!name) { return; }
        var existing = root.querySelector('[name="' + name + '"][value="' + input.value.toUpperCase() + '"]');
        if (existing) { existing.checked = true; return; }
        var synthetic = root.querySelector('[data-custom-radio="' + name + '"]');
        if (!synthetic) {
            synthetic = document.createElement('input');
            synthetic.type = 'radio';
            synthetic.name = name;
            synthetic.dataset.customRadio = name;
            synthetic.hidden = true;
            input.parentNode.appendChild(synthetic);
        }
        synthetic.value = input.value;
        synthetic.checked = true;
    }

    function upload(input) {
        var file = input.files && input.files[0];
        var kind = input.dataset.meUpload;
        if (!file || !/^(avatar|cover)$/.test(kind || '') || !/^image\//.test(file.type) || file.size > 8 * 1024 * 1024) {
            message('Choisis une image valide de 8 Mo maximum.', true);
            return;
        }
        var action = kind === 'avatar' ? 'faluss_me_studio_upload_avatar' : 'faluss_me_studio_upload_cover';
        var nonce = kind === 'avatar' ? config.avatarNonce : config.coverNonce;
        var data = new FormData();
        data.append('action', action);
        data.append('nonce', nonce || '');
        data.append(kind, file);
        input.disabled = true;
        message('Envoi de l’image…', false);
        fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
            .then(function (response) { return response.json(); })
            .then(function (response) {
                if (!response.success || !response.data || !response.data.id) { throw new Error('upload'); }
                var hidden = root.querySelector('[name="' + kind + '_attachment_id"]');
                if (hidden) { hidden.value = response.data.id; }
                var area = input.closest('label').querySelector('.faluss-me-onboarding-v2__upload-area');
                if (area) {
                    area.innerHTML = '';
                    var image = document.createElement('img');
                    image.src = response.data.url;
                    image.alt = '';
                    area.appendChild(image);
                }
                message('Image prête.', false);
            })
            .catch(function () { message('L’image n’a pas pu être envoyée.', true); })
            .finally(function () { input.disabled = false; });
    }

    root.querySelectorAll('[role="tab"]').forEach(function (tab) {
        tab.addEventListener('click', function () { activateTab(tab, false); });
        tab.addEventListener('keydown', tabKeydown);
    });

    root.addEventListener('change', function (event) {
        if (event.target.matches('[name="structure"], [name="structure_card"]')) { syncStructure(event.target); }
        if (event.target.matches('[name="social_style"]')) {
            var socialSelection = root.querySelector('[data-me-social-selection]');
            if (socialSelection) { socialSelection.textContent = event.target.dataset.socialStyleLabel || ''; }
        }
        if (event.target.matches('[data-color-target]')) { syncCustomColor(event.target); }
        if (event.target.matches('[data-me-upload]')) { upload(event.target); return; }
        if (event.target.matches('input')) { schedulePreview(); }
    });
    root.addEventListener('input', function (event) {
        if (event.target.matches('[data-color-target]')) { syncCustomColor(event.target); schedulePreview(); }
    });
    root.addEventListener('click', function (event) {
        if (event.target.closest('[data-me-onboarding-back]')) { submit('back'); }
        if (event.target.closest('[data-me-onboarding-skip]')) { submit('skip'); }
    });
    var form = root.querySelector('form');
    if (form) {
        form.addEventListener('submit', function (event) { event.preventDefault(); submit('next'); });
    }
    var initialStructure = root.querySelector('[name="structure"]:checked, [name="structure_card"]:checked');
    if (initialStructure) { syncStructure(initialStructure); }
}());
