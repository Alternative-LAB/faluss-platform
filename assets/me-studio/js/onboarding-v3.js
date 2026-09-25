(function () {
    'use strict';

    var root = document.querySelector('[data-faluss-onboarding-v3]');
    var config = window.falussOnboardingV3 || {};
    if (!root || !config.ajaxUrl) { return; }

    var panel = root.querySelector('[data-v3-panel]');
    var scroll = root.querySelector('[data-v3-scroll]');
    var grabber = root.querySelector('[data-v3-grabber]');
    var error = root.querySelector('[data-v3-error]');
    var status = root.querySelector('[data-v3-status]');
    var previewHost = root.querySelector('[data-v3-preview]');
    var timer = 0;
    var previewRequest = null;
    var previewRevision = 0;
    var draftTimer = 0;
    var draftRevision = 0;
    var draftQueue = Promise.resolve();
    var pending = false;
    var uploading = false;
    var expanded = false;
    var drag = null;
    var suppressGrabberClick = false;

    var studio = root.dataset.studio === 'true';
    var dirty = false;
    var management = root.querySelector('[data-v3-management]');
    var layoutHeight = window.innerHeight;
    var layoutWidth = window.innerWidth;
    var collapsedHeight = 300;
    var keyboardOpen = false;
    var scrollBeforeKeyboard = 0;
    var keyboardSpacer = document.createElement('div');
    keyboardSpacer.dataset.v3KeyboardSpacer = '';
    keyboardSpacer.setAttribute('aria-hidden', 'true');
    scroll.appendChild(keyboardSpacer);

    function fitPanel() {
        if (keyboardOpen || drag) { return; }
        // Measure intrinsic controls without expanding the sheet or counting the keyboard spacer.
        scroll.style.flex = '0 0 auto';
        scroll.style.height = '0px';
        var contentHeight = scroll.scrollHeight;
        scroll.style.removeProperty('height');
        scroll.style.removeProperty('flex');
        var actions = root.querySelector('.faluss-onboarding-v3__actions');
        var natural = contentHeight + grabber.offsetHeight + (actions ? actions.offsetHeight : 0) + 2;
        // Text-entry steps reserve enough visible sheet above a typical overlay keyboard.
        var textEntry = root.dataset.step === 'v3_identity' || root.dataset.step === 'v3_socials';
        var minimum = textEntry ? layoutHeight * .64 : 230;
        collapsedHeight = Math.min(maxHeight(), Math.max(minimum, Math.min(natural, layoutHeight * .66)));
        root.style.setProperty('--v3-collapsed-height', Math.ceil(collapsedHeight) + 'px');
    }

    function revealActiveField() {
        var active = document.activeElement;
        if (!keyboardOpen || !active || !scroll.contains(active) || !active.matches('input, select, textarea')) { return; }
        var viewport = window.visualViewport;
        var box = scroll.getBoundingClientRect();
        var visibleBottom = Math.min(box.bottom, viewport ? viewport.offsetTop + viewport.height : layoutHeight) - 10;
        var visibleTop = box.top + 8;
        var field = active.getBoundingClientRect();
        var delta = field.bottom > visibleBottom ? field.bottom - visibleBottom : Math.min(0, field.top - visibleTop);
        if (Math.abs(delta) > 1) { scroll.scrollTo({ top: scroll.scrollTop + delta, behavior: 'instant' }); }
    }

    function updateViewportHeight() {
        var viewport = window.visualViewport;
        if (viewport && viewport.scale !== 1) { return; } // Preserve intentional pinch zoom.
        var inset = viewport ? Math.max(0, layoutHeight - viewport.height - viewport.offsetTop) : 0;
        var opening = inset > 80;
        if (!opening || window.innerWidth !== layoutWidth) {
            layoutHeight = window.innerHeight;
            layoutWidth = window.innerWidth;
        }
        root.style.setProperty('--v3-height', layoutHeight + 'px');
        if (opening && !keyboardOpen) { scrollBeforeKeyboard = scroll.scrollTop; }
        var closing = keyboardOpen && !opening;
        keyboardOpen = opening;
        root.classList.toggle('is-keyboard-open', keyboardOpen);
        // Only add scroll range behind the keyboard. The sheet and footer never move.
        var box = scroll.getBoundingClientRect();
        var visibleBottom = viewport ? viewport.offsetTop + viewport.height : layoutHeight;
        keyboardSpacer.style.height = keyboardOpen ? Math.max(0, box.bottom - visibleBottom + 18) + 'px' : '0px';
        if (closing) { scroll.scrollTo({ top: scrollBeforeKeyboard, behavior: 'instant' }); }
        if (!keyboardOpen) { fitPanel(); }
        revealActiveField();
    }
    updateViewportHeight();
    var viewportFrame = 0;
    function scheduleViewportUpdate() {
        if (viewportFrame) { return; }
        viewportFrame = window.requestAnimationFrame(function () { viewportFrame = 0; updateViewportHeight(); });
    }
    window.addEventListener('resize', scheduleViewportUpdate);
    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', scheduleViewportUpdate);
        // Safari owns visual-viewport panning. Never answer its scroll with another scroll.
    }
    if (document.fonts) { document.fonts.ready.then(fitPanel); }
    function scalePreview() {
        if (!previewHost || !previewHost.firstElementChild) { return; }
        var card = previewHost.firstElementChild;
        card.style.width = '390px';
        card.style.setProperty('--fl-card-min-height', Math.max(440, window.innerWidth <= 768 ? layoutHeight * 390 / window.innerWidth : 844) + 'px');
        card.style.zoom = String(previewHost.clientWidth / 390);
    }
    if (window.ResizeObserver && previewHost) { new ResizeObserver(scalePreview).observe(previewHost); }
    scalePreview();

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

    function links() {
        return Array.prototype.slice.call(root.querySelectorAll('[data-v3-link]')).map(function (row) {
            return { block_id: row.dataset.id, label: row.querySelector('[data-v3-link-label]').value.trim(), url: row.querySelector('[data-v3-link-url]').value.trim() };
        }).filter(function (row) { return row.label !== '' || row.url !== ''; });
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
        if (step === 'v3_links') { result.links = JSON.stringify(links()); }
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

    function preview() {
        if (!previewHost || root.dataset.step === 'v3_success') { return; }
        if (previewRequest) { previewRequest.abort(); }
        previewRequest = new AbortController();
        var revision = ++previewRevision;
        post('faluss_onboarding_v3_preview', config.previewNonce, { fields: JSON.stringify(fields()) }, previewRequest.signal)
            .then(function (payload) {
                if (revision !== previewRevision) { return; }
                if (!payload.success || !payload.data || !payload.data.preview_html) { throw new Error(serverError(payload, 'preview_failed')); }
                previewHost.innerHTML = payload.data.preview_html;
                scalePreview();
                if (status) { status.textContent = 'Aperçu à jour.'; }
            }).catch(function (failure) {
                if (failure.name !== 'AbortError' && revision === previewRevision) { notice(failure.message || 'Aperçu indisponible.', true); }
            });
    }

    function schedulePreview() {
        if (management) { return; }
        ++previewRevision;
        if (previewRequest) { previewRequest.abort(); }
        window.clearTimeout(timer);
        timer = window.setTimeout(preview, 120);
    }

    function saveIdentityDraftNow() {
        if (studio || root.dataset.step !== 'v3_identity') { return Promise.resolve(); }
        window.clearTimeout(draftTimer);
        var revision = ++draftRevision;
        var snapshot = JSON.stringify(fields());
        draftQueue = draftQueue.catch(function () {}).then(function () {
            if (revision !== draftRevision || pending) { return; }
            return post('faluss_onboarding_v3_identity_draft', config.identityDraftNonce, {
                version: root.dataset.version || '', fields: snapshot
            }).then(function (response) {
                if (!response.success) { throw new Error(serverError(response, 'save_failed')); }
            });
        });
        return draftQueue;
    }

    function scheduleIdentityDraft() {
        if (studio || root.dataset.step !== 'v3_identity') { return; }
        window.clearTimeout(draftTimer);
        ++draftRevision;
        draftTimer = window.setTimeout(function () {
            saveIdentityDraftNow().catch(function (failure) {
                if (!pending) { notice(failure.message || 'Brouillon indisponible.', true); }
            });
        }, 350);
    }

    function submit(direction) {
        if (pending || uploading) { return; }
        pending = true;
        window.clearTimeout(draftTimer);
        ++draftRevision;
        var button = root.querySelector('[data-v3-primary]');
        if (button) { button.disabled = true; }
        notice(direction === 'publish' ? 'Publication…' : 'Enregistrement…', false);
        var action = direction === 'publish' ? 'faluss_onboarding_v3_publish' : 'faluss_onboarding_v3_transition';
        var nonce = direction === 'publish' ? config.publishNonce : config.transitionNonce;
        if (studio) { action = 'faluss_studio_v3_save'; nonce = config.studioNonce; }
        var payload = direction === 'publish' ? { version: root.dataset.version || '' } : {
            step: root.dataset.step || '', direction: direction, version: root.dataset.version || '', fields: JSON.stringify(fields())
        };
        draftQueue.catch(function () {}).then(function () { return post(action, nonce, payload); }).then(function (response) {
            if (!response.success) { throw new Error(serverError(response, direction === 'publish' ? 'publish_failed' : 'save_failed')); }
            dirty = false;
            window.location.reload();
        }).catch(function (failure) {
            notice(failure.message || 'Action impossible. Réessayez.', true);
            pending = false;
            if (button) { button.disabled = false; }
        });
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
        dirty = true;
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
            if (kind === 'avatar' && root.dataset.step === 'v3_identity') {
                return saveIdentityDraftNow().then(function () {
                    notice('Image prête.', false);
                    schedulePreview();
                });
            }
            notice('Image prête.', false);
            schedulePreview();
        }).catch(function (failure) {
            notice(failure.message || 'Envoi impossible.', true);
        }).finally(function () { uploading = false; input.disabled = false; });
    }

    function itemId() {
        return window.crypto.randomUUID();
    }

    function saveItem(editor, removal) {
        if (pending || uploading) { return; }
        if (Array.from(root.querySelectorAll('[data-v3-editor][data-dirty]')).some(function (other) { return other !== editor; })) {
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
            dirty = false;
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
            editor.dataset.dirty = 'true'; dirty = true;
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
        fitPanel();
        if (focus) { tab.focus(); }
    }

    function setExpanded(value) {
        expanded = value;
        panel.classList.toggle('is-expanded', expanded);
        panel.style.removeProperty('height');
        grabber.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        grabber.setAttribute('aria-label', expanded ? 'Réduire le panneau' : 'Agrandir le panneau');
        if (!expanded && !keyboardOpen) { scroll.scrollTop = 0; }
        window.requestAnimationFrame(updateViewportHeight);
    }

    function panelHeight() { return panel.getBoundingClientRect().height; }
    function maxHeight() { return root.getBoundingClientRect().height - root.querySelector('.faluss-onboarding-v3__header').getBoundingClientRect().height - 8; }

    grabber.addEventListener('click', function () {
        if (suppressGrabberClick) { suppressGrabberClick = false; return; }
        setExpanded(!expanded);
    });
    grabber.addEventListener('keydown', function (event) {
        if (event.key === 'ArrowUp' || event.key === 'Home') { event.preventDefault(); setExpanded(true); }
        if (event.key === 'ArrowDown' || event.key === 'End') { event.preventDefault(); if (scroll.scrollTop > 0) { scroll.scrollTo({ top: 0, behavior: 'smooth' }); } else { setExpanded(false); } }
    });
    grabber.addEventListener('pointerdown', function (event) {
        drag = { y: event.clientY, height: panelHeight(), moved: false };
        panel.classList.add('is-dragging');
        grabber.setPointerCapture(event.pointerId);
    });
    grabber.addEventListener('pointermove', function (event) {
        if (!drag) { return; }
        var distance = drag.y - event.clientY;
        if (Math.abs(distance) > 5) { drag.moved = true; }
        if (drag.moved) { panel.style.height = Math.max(collapsedHeight, Math.min(maxHeight(), drag.height + distance)) + 'px'; }
    });
    function finishDrag() {
        if (!drag) { return; }
        var moved = drag.moved;
        drag = null;
        panel.classList.remove('is-dragging');
        if (moved) {
            suppressGrabberClick = true;
            setExpanded(panelHeight() > (maxHeight() + collapsedHeight) / 2);
        }
    }
    grabber.addEventListener('pointerup', finishDrag);
    grabber.addEventListener('pointercancel', finishDrag);
    panel.addEventListener('wheel', function (event) {
        if (keyboardOpen) { return; }
        if (!expanded && event.deltaY > 0) { event.preventDefault(); setExpanded(true); }
        else if (expanded && scroll.scrollTop <= 0 && event.deltaY < 0) { event.preventDefault(); setExpanded(false); }
    }, { passive: false });

    var touchDrag = null;
    panel.addEventListener('touchstart', function (event) {
        if (!keyboardOpen && event.touches.length === 1 && !event.target.closest('input, select, textarea, button, a')) {
            touchDrag = { y: event.touches[0].clientY, height: panelHeight(), moved: false };
        }
    }, { passive: true });
    panel.addEventListener('touchmove', function (event) {
        if (!touchDrag || event.touches.length !== 1) { return; }
        var delta = touchDrag.y - event.touches[0].clientY;
        if (Math.abs(delta) < 8 && !touchDrag.moved) { return; }
        if (expanded && delta > 0) { touchDrag = null; return; }
        if (expanded && scroll.scrollTop > 0) { touchDrag = null; return; }
        if (!expanded && delta < 0) { touchDrag = null; return; }
        touchDrag.moved = true;
        event.preventDefault();
        panel.classList.add('is-dragging');
        panel.style.height = Math.max(collapsedHeight, Math.min(maxHeight(), touchDrag.height + delta)) + 'px';
    }, { passive: false });
    panel.addEventListener('touchend', function () {
        if (!touchDrag) { return; }
        var moved = touchDrag.moved;
        touchDrag = null;
        panel.classList.remove('is-dragging');
        if (moved) { setExpanded(panelHeight() > (maxHeight() + collapsedHeight) / 2); }
    }, { passive: true });

    root.addEventListener('click', function (event) {
        var target = event.target;
        var editor = target.closest('[data-v3-editor]');
        if (editor) {
            if (target.closest('[data-v3-save-item]')) { saveItem(editor, ''); return; }
            var remove = target.closest('[data-v3-delete-item]');
            if (remove) { saveItem(editor, remove.dataset.v3DeleteItem); return; }
            if (target.closest('[data-v3-remove-image]')) {
                editor.querySelector('[data-field="attachment_id"]').value = '0';
                var image = editor.querySelector('img'); if (image) { image.remove(); }
                editor.dataset.dirty = 'true'; dirty = true; return;
            }
            var order = target.closest('[data-v3-order]');
            if (order) {
                var row = order.closest('[data-block-id]');
                if (order.dataset.v3Order === 'up' && row.previousElementSibling) { row.parentNode.insertBefore(row, row.previousElementSibling); }
                if (order.dataset.v3Order === 'down' && row.nextElementSibling) { row.parentNode.insertBefore(row.nextElementSibling, row); }
                order.focus(); editor.dataset.dirty = 'true'; dirty = true; return;
            }
        }
        if (target.closest('[data-v3-back]')) { submit('back'); return; }
        if (target.closest('[data-v3-skip]')) { submit('skip'); return; }
        var tab = target.closest('[data-v3-tab]');
        if (tab) { activateTab(tab, false); return; }
        if (target.closest('[data-v3-add-link]')) {
            var list = root.querySelector('[data-v3-links]');
            var id = window.crypto && window.crypto.randomUUID ? window.crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (letter) {
                var value = Math.floor(Math.random() * 16);
                return (letter === 'x' ? value : (value & 3) | 8).toString(16);
            });
            var row = document.createElement('div');
            row.className = 'faluss-onboarding-v3__link'; row.dataset.v3Link = ''; row.dataset.id = id;
            row.innerHTML = '<label>Libellé<input data-v3-link-label maxlength="80"></label><label>URL HTTPS<input type="url" data-v3-link-url maxlength="2048" placeholder="https://"></label><div><button type="button" data-v3-move="up" aria-label="Monter ce lien">↑</button><button type="button" data-v3-move="down" aria-label="Descendre ce lien">↓</button></div>';
            list.appendChild(row); row.querySelector('input').focus(); setExpanded(true); return;
        }
        var move = target.closest('[data-v3-move]');
        if (move) {
            var item = move.closest('[data-v3-link]');
            if (move.dataset.v3Move === 'up' && item.previousElementSibling) { item.parentNode.insertBefore(item, item.previousElementSibling); }
            if (move.dataset.v3Move === 'down' && item.nextElementSibling) { item.parentNode.insertBefore(item.nextElementSibling, item); }
            move.focus(); schedulePreview();
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
        if (event.target.matches('[data-v3-section]')) {
            if (dirty && !window.confirm('Quitter cette rubrique sans enregistrer les modifications ?')) { event.target.value = root.dataset.step; return; }
            var url = new URL(window.location.href);
            url.searchParams.set('v3_section', event.target.value);
            window.location.assign(url.href);
            return;
        }
        dirty = true;
        if (management) {
            var editor = event.target.closest('[data-v3-editor]');
            if (editor) { editor.dataset.dirty = 'true'; }
            if (event.target.matches('[data-v3-content-upload]')) { uploadContent(event.target); }
            return;
        }
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
        fitPanel();
        schedulePreview();
        scheduleIdentityDraft();
    });
    root.addEventListener('input', function (event) {
        if (management) { var editor = event.target.closest('[data-v3-editor]'); if (editor) { editor.dataset.dirty = 'true'; dirty = true; } return; }
        if (event.target.matches('[data-v3-upload]')) { return; }
        if (event.target.matches('[data-v3-color]')) { event.target.dataset.selected = 'true'; }
        if (event.target.matches('input, select')) { dirty = true; schedulePreview(); scheduleIdentityDraft(); }
    });
    panel.addEventListener('submit', function (event) {
        event.preventDefault();
        if (management) { var editor = event.target.closest('[data-v3-editor]') || document.activeElement.closest('[data-v3-editor]'); if (editor) { saveItem(editor, ''); } return; }
        submit(root.dataset.step === 'v3_review' ? 'publish' : 'next');
    });
    panel.addEventListener('focusin', function (event) {
        if (event.target.matches('input, select, textarea')) { updateViewportHeight(); }
    });
    root.querySelector('h1').focus({ preventScroll: true });
}());
