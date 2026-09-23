(function () {
    'use strict';

    var config = window.falussMeStudioV2 || {};
    var atomicFields = [
        'structure', 'button_texture', 'avatar_shape', 'avatar_effect', 'wallpaper_size', 'wallpaper_effect',
        'social_style', 'social_color', 'links_mode', 'link_width'
    ];
    var previewTimer = 0;

    function owner(node) {
        return node.closest('.faluss-link-studio');
    }

    function extension(studio) {
        return studio ? studio.querySelector('[data-me-studio-v2-design]') : null;
    }

    function status(studio, message, error) {
        var target = extension(studio) && extension(studio).querySelector('.faluss-me-studio-v2__status');
        if (!target) { return; }
        target.textContent = message || '';
        target.classList.toggle('is-error', Boolean(error));
    }

    function selected(root, name) {
        var checked = root.querySelector('[name="' + name + '"]:checked');
        var field = root.querySelector('[name="' + name + '"]');
        if (field && field.type === 'radio') { return checked ? checked.value : ''; }
        if (field && field.type === 'checkbox') { return field.checked ? (field.value || '1') : '0'; }
        return (checked || field) ? (checked || field).value : '';
    }

    function setField(root, name, value) {
        root.querySelectorAll('[name="' + name + '"]').forEach(function (node) {
            if (node.type === 'radio') { node.checked = node.value === String(value || ''); }
            else if (node.type === 'checkbox') { node.checked = String(value) === '1'; }
            else { node.value = value === null || typeof value === 'undefined' ? '' : value; }
        });
    }

    function fields(studio) {
        var root = extension(studio);
        var result = {};
        if (!root) { return result; }
        atomicFields.forEach(function (name) { result[name] = selected(root, name); });
        ['page_background', 'button_color', 'link_style', 'cover_attachment_id', 'avatar_visible', 'name_font'].forEach(function (name) {
            var value = selected(studio, name);
            if (value !== '') { result[name] = value; }
        });
        return result;
    }

    function post(data) {
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

    function previewCard(studio) {
        return studio.querySelector('.faluss-link-studio__preview .faluss-link-card, [data-fl-preview] .faluss-link-card');
    }

    function replacePreview(studio, html) {
        if (!html) { return; }
        var template = document.createElement('template');
        template.innerHTML = html.trim();
        var replacement = template.content.firstElementChild;
        var current = previewCard(studio);
        if (replacement && current) { current.replaceWith(replacement); }
    }

    function preview(studio) {
        var data = fields(studio);
        data.action = 'faluss_me_studio_preview';
        data.nonce = config.previewNonce || '';
        data.context = 'studio';
        status(studio, 'Aperçu en cours…', false);
        post(data).then(function (response) {
            if (!response.success || !response.data) { throw new Error('preview'); }
            replacePreview(studio, response.data.preview_html || '');
            status(studio, 'Aperçu à jour.', false);
        }).catch(function () { status(studio, 'L’aperçu n’a pas pu être actualisé.', true); });
    }

    function schedulePreview(studio) {
        window.clearTimeout(previewTimer);
        previewTimer = window.setTimeout(function () { preview(studio); }, 180);
    }

    function syncSocialColor(root, field) {
        if (!root || !field || !field.matches('[data-me-studio-social-color]')) { return; }
        var socialColor = root.querySelector('[name="social_color"]');
        if (socialColor) { socialColor.value = field.value; }
    }

    function hydrate(studio, state) {
        if (!state) { return; }
        var root = extension(studio);
        if (!root) { return; }
        var preferences = state.preferences || {};
        atomicFields.forEach(function (name) {
            setField(root, name, preferences[name]);
        });
        ['page_background', 'button_color', 'link_style', 'cover_attachment_id', 'avatar_visible', 'name_font'].forEach(function (name) {
            if (Object.prototype.hasOwnProperty.call(preferences, name)) { setField(studio, name, preferences[name]); }
        });
        var socialColor = root.querySelector('[data-me-studio-social-color]');
        if (socialColor) { socialColor.value = preferences.social_color || '#ED4343'; }
        if (state.version) {
            studio.dataset.falussStudioVersion = state.version;
            var version = studio.querySelector('[data-fl-aggregate-version]');
            if (version) { version.value = state.version; }
        }
        replacePreview(studio, state.preview_html || '');
    }

    function save(button) {
        var studio = owner(button);
        if (!studio) { return; }
        var data = fields(studio);
        data.action = 'faluss_me_studio_save';
        data.nonce = config.saveNonce || '';
        data.aggregate_version = studio.dataset.falussStudioVersion || (studio.querySelector('[data-fl-aggregate-version]') || {}).value || '';
        button.disabled = true;
        status(studio, 'Enregistrement…', false);
        post(data).then(function (response) {
            var payload = response.data || {};
            if (payload.state) { hydrate(studio, payload.state); }
            if (!response.success) {
                if (response.httpStatus === 409) { status(studio, 'Conflit détecté : la version canonique a été rechargée.', true); return; }
                throw new Error(payload.code || 'save');
            }
            status(studio, payload.message || 'Design enregistré.', false);
        }).catch(function () { status(studio, 'Le design n’a pas pu être enregistré.', true); }).finally(function () { button.disabled = false; });
    }

    function uploadLinkImage(button) {
        var studio = owner(button);
        var card = button.closest('[data-fl-link-card]');
        if (!studio || !card) { return; }
        var input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/jpeg,image/png,image/gif,image/webp';
        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            if (!file || !/^image\//.test(file.type) || file.size > 8 * 1024 * 1024) {
                status(studio, 'Choisissez une image valide de 8 Mo maximum.', true);
                return;
            }
            var data = new FormData();
            data.append('action', 'faluss_me_studio_upload_link_image');
            data.append('nonce', config.uploadNonce || '');
            data.append('link_image', file);
            button.disabled = true;
            status(studio, 'Envoi de l’image…', false);
            fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
                .then(function (response) { return response.json(); })
                .then(function (response) {
                    if (!response.success || !response.data || !response.data.id) { throw new Error('upload'); }
                    var attachment = card.querySelector('[data-fl-link-attachment]');
                    if (attachment) { attachment.value = response.data.id; }
                    button.textContent = 'Changer l’image';
                    var remove = card.querySelector('[data-me-link-image-remove]');
                    if (remove) { remove.hidden = false; }
                    status(studio, 'Image prête. Enregistrez le lien.', false);
                })
                .catch(function () { status(studio, 'L’image n’a pas pu être envoyée.', true); })
                .finally(function () { button.disabled = false; });
        });
        input.click();
    }

    document.addEventListener('input', function (event) {
        var root = event.target.closest('[data-me-studio-v2-design]');
        syncSocialColor(root, event.target);
        if (root) { schedulePreview(owner(root)); }
    });
    document.addEventListener('change', function (event) {
        var root = event.target.closest('[data-me-studio-v2-design]');
        syncSocialColor(root, event.target);
        if (root) { schedulePreview(owner(root)); }
    });
    document.addEventListener('click', function (event) {
        var saveButton = event.target.closest('[data-me-studio-save]');
        if (saveButton) { save(saveButton); return; }
        var imageButton = event.target.closest('[data-me-link-image]');
        if (imageButton) { uploadLinkImage(imageButton); return; }
        var removeButton = event.target.closest('[data-me-link-image-remove]');
        if (removeButton) {
            var card = removeButton.closest('[data-fl-link-card]');
            var attachment = card && card.querySelector('[data-fl-link-attachment]');
            if (attachment) { attachment.value = '0'; }
            var add = card && card.querySelector('[data-me-link-image]');
            if (add) { add.textContent = 'Ajouter une image'; }
            removeButton.hidden = true;
            status(owner(removeButton), 'Image retirée. Enregistrez le lien.', false);
        }
    });
}());
