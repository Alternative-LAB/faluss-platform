(function () {
    'use strict';
    var root = document.querySelector('[data-faluss-studio-v3]');
    var config = window.falussStudioV3 || {};
    if (!root || !config.ajaxUrl) { return; }
    var panel = root.querySelector('[data-v3-panel]');
    var error = root.querySelector('[data-v3-error]');
    var status = root.querySelector('[data-v3-status]');
    var previewHost = root.querySelector('[data-v3-preview]');
    var dialog = root.querySelector('dialog');
    var pending = false, uploading = false, leaving = false, previewRequest = null;
    var management = root.querySelector('[data-v3-management]');
    function notice(message, failed) {
        if (error) { error.textContent = failed ? message : ''; error.hidden = !failed; }
        if (status) { status.textContent = failed ? '' : message; }
    }

    function selected(name) {
        var custom = root.querySelector('[data-v3-color="' + name + '"][data-selected="true"]');
        if (custom) { return custom.value; }
        var field = root.querySelector('[name="' + name + '"]');
        if (!field) { return ''; }
        if (field.type === 'radio') {
            var checked = root.querySelector('[name="' + name + '"]:checked');
            return checked ? checked.value : '';
        }
        return field.value;
    }

    function networks() {
        return Array.prototype.slice.call(root.querySelectorAll('[data-network]')).filter(function (row) {
            return row.querySelector('[data-v3-network-choice]').checked;
        }).map(function (row) {
            return { network: row.dataset.network, url: row.querySelector('[data-v3-network-url]').value.trim() };
        });
    }

    function fields() {
        var step = root.dataset.step;
        var names = {
            v3_mode: ['structure'], v3_identity: ['display_name', 'public_slug', 'avatar_attachment_id'],
            v3_colors: ['page_background', 'button_color'], v3_buttons: ['link_style', 'button_texture'],
            v3_avatar: ['avatar_attachment_id', 'avatar_shape', 'avatar_effect'],
            v3_wallpaper: ['cover_attachment_id', 'wallpaper_size', 'wallpaper_effect'],
            v3_name: ['name_font', 'name_weight', 'name_color'],
            v3_network_style: ['social_style', 'social_color']
        };
        var result = {};
        (names[step] || []).forEach(function (name) { result[name] = selected(name); });
        if (step === 'v3_socials') { result.social_networks = JSON.stringify(networks()); }

        return result;
    }

    function post(action, nonce, extra, signal) {
        var body = new URLSearchParams();
        body.set('action', action);
        body.set('nonce', nonce || '');
        Object.keys(extra || {}).forEach(function (key) { body.set(key, extra[key]); });
        return fetch(config.ajaxUrl, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body: body.toString(), signal: signal
        }).then(function (response) {
            return response.text().then(function (raw) {
                var payload;
                try { payload = JSON.parse(raw); } catch (ignored) { throw new Error('Réponse serveur illisible (HTTP ' + response.status + ').'); }
                payload.httpStatus = response.status;
                return payload;
            });
        });
    }

    function serverError(payload, fallback) {
        var data = payload && payload.data ? payload.data : {};
        var code = data.code || data.message || fallback;
        var messages = {
            stale_version: 'Le brouillon a changé dans une autre session. Vos champs restent ici. Rechargez la page pour reprendre la version enregistrée.',
            invalid_socials: 'Vérifiez les URL HTTPS des réseaux sélectionnés.',
            invalid_links: 'Chaque lien doit avoir un libellé et une URL HTTPS valides.',
            invalid_identity: 'Renseignez un nom et un identifiant valides.',
            slug_taken: 'Cet identifiant est déjà utilisé.', slug_immutable: 'Votre identifiant réservé ne peut plus être changé.',
            transaction_unavailable: 'La sauvegarde est temporairement indisponible. Réessayez.'
        };
        return messages[code] || (data.message ? data.message : 'Échec : ' + code + ' (HTTP ' + (payload.httpStatus || '?') + ').');
    }

    function upload(input) {
        var file = input.files && input.files[0];
        var kind = input.dataset.v3Upload;
        if (!file) { return; }
        if (!/^(image\/jpeg|image\/png|image\/gif|image\/webp)$/.test(file.type) || file.size > 8 * 1024 * 1024) {
            notice('Choisissez une image JPEG, PNG, GIF ou WebP de 8 Mo maximum.', true);
            return;
        }
        var action = kind === 'avatar' ? 'faluss_onboarding_v3_upload_avatar' : 'faluss_onboarding_v3_upload_cover';
        var nonce = kind === 'avatar' ? config.avatarNonce : config.coverNonce;
        var data = new FormData();
        data.append('action', action);
        data.append('nonce', nonce || '');
        data.append(kind, file, file.name);
        uploading = true;
        input.disabled = true;
        notice('Envoi de l’image…', false);
        fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data }).then(function (response) {
            return response.text().then(function (raw) {
                var payload;
                try { payload = JSON.parse(raw); } catch (ignored) { throw new Error('Réponse serveur illisible (HTTP ' + response.status + ').'); }
                payload.httpStatus = response.status;
                return payload;
            });
        }).then(function (payload) {
            if (!payload.success || !payload.data || !payload.data.id) { throw new Error(serverError(payload, 'upload_failed')); }
            var hidden = root.querySelector('[name="' + kind + '_attachment_id"]');
            if (hidden) { hidden.value = payload.data.id; }
            notice('Image prête.', false);
            refreshPreview();
        }).catch(function (failure) {
            notice(failure.message || 'Envoi impossible.', true);
        }).finally(function () { uploading = false; input.disabled = false; });
    }

    function itemId() {
        return window.crypto.randomUUID();
    }

    function saveItem(editor, removal) {
        if (pending || uploading) { return; }
        if (Array.from(root.querySelectorAll('[data-v3-editor]')).filter(editorDirty).some(function (other) { return other !== editor; })) {
            if (!window.confirm('Enregistrer cet élément et abandonner les modifications non enregistrées des autres éléments ?')) { return; }
        }
        if (removal && !window.confirm(removal === 'dissolve_collection' ? 'Dissoudre cette collection et conserver ses contenus ?' : 'Supprimer cet élément ?')) { return; }
        var values = {};
        var mutation = removal || editor.dataset.mutation;
        if (!removal) {
            editor.querySelectorAll('[data-field]').forEach(function (field) { values[field.dataset.field] = field.value; });
        }
        if (editor.dataset.id) { values.block_id = editor.dataset.id; }
        if (mutation.indexOf('create_') === 0) { values.block_id = itemId(); }
        if (mutation === 'create_collection') { values.description_block_id = itemId(); }
        if (mutation === 'reorder_blocks') { values.block_ids = Array.from(editor.querySelectorAll('[data-block-id]')).map(function (row) { return row.dataset.blockId; }); }
        pending = true;
        editor.querySelectorAll('button').forEach(function (button) { button.disabled = true; });
        notice('Enregistrement…', false);
        post('faluss_studio_v3_manage', config.managementNonce, {
            mutation: mutation, version: root.dataset.version || '', fields: JSON.stringify(values)
        }).then(function (response) {
            if (!response.success) { throw new Error(serverError(response, 'save_failed')); }
            leaving = true;
            window.location.reload();
        }).catch(function (failure) {
            notice(failure.message || 'Enregistrement impossible.', true);
            pending = false;
            editor.querySelectorAll('button').forEach(function (button) { button.disabled = false; });
        });
    }

    function uploadContent(input) {
        if (pending || uploading) { return; }
        var file = input.files && input.files[0];
        if (!file) { return; }
        if (!/^(image\/jpeg|image\/png|image\/gif|image\/webp)$/.test(file.type) || file.size > 8 * 1024 * 1024) {
            notice('Choisissez une image JPEG, PNG, GIF ou WebP de 8 Mo maximum.', true); return;
        }
        var editor = input.closest('[data-v3-editor]');
        var data = new FormData();
        data.append('action', 'faluss_studio_v3_upload_content');
        data.append('nonce', config.contentNonce || '');
        data.append('content_image', file, file.name);
        uploading = true; input.disabled = true;
        notice('Envoi de l’image…', false);
        fetch(config.ajaxUrl, {method: 'POST', credentials: 'same-origin', body: data}).then(function (response) {
            return response.text().then(function (raw) {
                var result;
                try { result = JSON.parse(raw); } catch (ignored) { throw new Error('Réponse serveur illisible (HTTP ' + response.status + ').'); }
                result.httpStatus = response.status; return result;
            });
        }).then(function (result) {
            if (!result.success || !result.data || !result.data.id) { throw new Error(serverError(result, 'upload_failed')); }
            editor.querySelector('[data-field="attachment_id"]').value = result.data.id;

            notice('Image prête. Enregistre cet élément pour l’appliquer à ta carte.', false);
        }).catch(function (failure) { notice(failure.message, true); })
            .finally(function () { uploading = false; input.disabled = false; });
    }

    function activateTab(tab, focus) {
        var group = tab.parentNode;
        group.querySelectorAll('[role="tab"]').forEach(function (candidate) {
            var active = candidate === tab;
            candidate.setAttribute('aria-selected', active ? 'true' : 'false');
            candidate.tabIndex = active ? 0 : -1;
        });
        panel.querySelectorAll('[data-v3-tab-panel]').forEach(function (candidate) {
            var active = candidate.dataset.v3TabPanel === tab.dataset.v3Tab;
            candidate.hidden = !active;
            candidate.toggleAttribute('inert', !active);
        });
        if (focus) { tab.focus(); }
    }


    function editorSnapshot(editor) {
        return JSON.stringify(Array.from(editor.querySelectorAll('[data-field]')).map(function (field) {
            return [field.dataset.field, field.value];
        }).concat(Array.from(editor.querySelectorAll('[data-block-id]')).map(function (row) { return row.dataset.blockId; })));
    }
    var baselines = new Map();
    root.querySelectorAll('[data-v3-editor]').forEach(function (editor) { baselines.set(editor, editorSnapshot(editor)); });
    var baseline = JSON.stringify(fields());
    function editorDirty(editor) { return editorSnapshot(editor) !== baselines.get(editor); }
    function isDirty() {
        return management ? Array.from(baselines.keys()).some(editorDirty) : JSON.stringify(fields()) !== baseline;
    }
    function refreshPreview() {
        if (!dialog.open) { return; }
        if (previewRequest) { previewRequest.abort(); }
        previewRequest = new AbortController();
        // Management previews show server-saved content; editors keep their independent drafts.
        post('faluss_onboarding_v3_preview', config.previewNonce, {fields: JSON.stringify(management ? {} : fields())}, previewRequest.signal)
            .then(function (response) {
                if (!response.success || !response.data || !response.data.preview_html) { throw new Error(serverError(response, 'preview_failed')); }
                previewHost.innerHTML = response.data.preview_html;
            }).catch(function (failure) { if (failure.name !== 'AbortError') { notice(failure.message, true); dialog.close(); } });
    }
    function save() {
        if (pending || uploading) { return; }
        pending = true;
        var button = root.querySelector('[data-v3-primary]');
        button.disabled = true;
        notice('Enregistrement…', false);
        post('faluss_studio_v3_save', config.studioNonce, {step: root.dataset.step, version: root.dataset.version, fields: JSON.stringify(fields())})
            .then(function (response) {
                if (!response.success) { throw new Error(serverError(response, 'save_failed')); }
                // Only a confirmed server response clears the navigation guard.
                leaving = true;
                window.location.reload();
            }).catch(function (failure) { notice(failure.message, true); pending = false; button.disabled = false; });
    }
    root.addEventListener('click', function (event) {
        var target = event.target;
        var navigation = target.closest('[data-studio-navigate], .faluss-studio-v3__header a');
        if (navigation) {
            if (pending || uploading || (isDirty() && !window.confirm('Quitter cette rubrique sans enregistrer les modifications ?'))) { event.preventDefault(); return; }
            leaving = true;
        }
        if (target.closest('[data-studio-preview-open]')) { dialog.showModal(); refreshPreview(); return; }
        if (target.closest('[data-studio-preview-close]')) { dialog.close(); return; }
        var tab = target.closest('[data-v3-tab]');
        if (tab) { activateTab(tab, false); return; }
        var editor = target.closest('[data-v3-editor]');
        if (editor) {
            if (target.closest('[data-v3-save-item]')) { saveItem(editor, ''); return; }
            var remove = target.closest('[data-v3-delete-item]');
            if (remove) { saveItem(editor, remove.dataset.v3DeleteItem); return; }
            if (target.closest('[data-v3-remove-image]')) {
                editor.querySelector('[data-field="attachment_id"]').value = '0';
                var image = editor.querySelector('img'); if (image) { image.remove(); }
                 return;
            }
            var order = target.closest('[data-v3-order]');
            if (order) {
                var row = order.closest('[data-block-id]');
                if (order.dataset.v3Order === 'up' && row.previousElementSibling) { row.parentNode.insertBefore(row, row.previousElementSibling); }
                if (order.dataset.v3Order === 'down' && row.nextElementSibling) { row.parentNode.insertBefore(row.nextElementSibling, row); }
                order.focus();  return;
            }
        }
    });
    root.addEventListener('keydown', function (event) {
        var tab = event.target.closest('[data-v3-tab]');
        if (!tab || !/^(ArrowLeft|ArrowRight|Home|End)$/.test(event.key)) { return; }
        var tabs = Array.prototype.slice.call(tab.parentNode.querySelectorAll('[data-v3-tab]'));
        var index = tabs.indexOf(tab);
        if (event.key === 'ArrowLeft') { index = (index + tabs.length - 1) % tabs.length; }
        if (event.key === 'ArrowRight') { index = (index + 1) % tabs.length; }
        if (event.key === 'Home') { index = 0; }
        if (event.key === 'End') { index = tabs.length - 1; }
        event.preventDefault(); activateTab(tabs[index], true);
    });
    root.addEventListener('change', function (event) {
        if (event.target.matches('[data-v3-content-upload]')) { uploadContent(event.target); return; }
        if (event.target.matches('[data-v3-upload]')) { upload(event.target); return; }
        if (event.target.matches('[data-v3-network-choice]')) {
            var row = event.target.closest('[data-network]');
            row.querySelector('[data-v3-network-url]').hidden = !event.target.checked;
        }
        if (event.target.matches('[data-v3-color]')) {
            var name = event.target.dataset.v3Color;
            var chosen = root.querySelector('[name="' + name + '"]:checked');
            if (chosen) { chosen.checked = false; }
            event.target.dataset.selected = 'true';
        }
        if (event.target.matches('[name="page_background"], [name="button_color"], [name="social_color"]')) {
            var custom = root.querySelector('[data-v3-color="' + event.target.name + '"]');
            if (custom) { custom.dataset.selected = 'false'; }
        }
        refreshPreview();
    });
    panel.addEventListener('submit', function (event) {
        event.preventDefault();
        if (management) {
            var editor = document.activeElement.closest('[data-v3-editor]');
            if (editor) { saveItem(editor, ''); }
        } else { save(); }
    });
    window.addEventListener('beforeunload', function (event) {
        if (!leaving && (isDirty() || pending || uploading)) { event.preventDefault(); event.returnValue = ''; }
    });
    // Keep the active contextual destination visible without moving the document.
    var activeTab = root.querySelector('.faluss-studio-v3__tabs [aria-current]');
    if (activeTab) { activeTab.parentNode.scrollLeft = activeTab.offsetLeft - activeTab.parentNode.offsetLeft - 16; }
}());
