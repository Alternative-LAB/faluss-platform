(function () {
    'use strict';
    // The Studio owns transport, its pending guard and canonical rehydration.
    window.falussLinkImport = function (host, options) {
        if (!host) { return null; }
        var source = host.querySelector('[data-import-source]');
        var file = host.querySelector('[data-import-file]');
        var format = host.querySelector('[data-import-format]');
        var list = host.querySelector('[data-import-rows]');
        var commit = host.querySelector('[data-import-commit]');
        var review = host.querySelector('[data-import-review]');
        var analyseButton = host.querySelector('[data-import-analyse]');
        var sourceStage = host.querySelector('[data-import-source-stage]');
        var preview = host.querySelector('[data-import-preview]');
        var notice = host.querySelector('[data-import-notice]');
        var summary = host.querySelector('[data-import-summary]');
        var fileName = host.querySelector('[data-import-file-name]');
        var tabs = Array.from(host.querySelectorAll('[data-import-method]'));
        var rows = [], capacity = 0, dirty = false, reviewed = false, busy = false, revision = 0, method = 'file';
        // A file selection must never replace a separately pasted draft.
        var drafts = {file: {source: '', format: 'auto', name: ''}, text: {format: 'auto'}};
        var messages = {
            too_large: 'Le fichier dépasse 512 Ko.', too_many_rows: 'Limite de 500 lignes par aperçu.',
            invalid_json: 'Le JSON est illisible. Vérifie le fichier ou choisis un autre format.',
            invalid_csv: 'Le CSV est illisible. Vérifie les colonnes et les guillemets.',
            no_links: 'Aucun lien détecté. Colle des URL ou choisis un export CSV / JSON.',
            unsupported_html: 'L’analyse HTML est indisponible. Colle les URL en texte ou utilise un fichier CSV / JSON.',
            invalid_rows: 'Vérifie les champs de chaque ligne.', import_empty: 'Aucun nouveau lien valide à importer.',
            import_capacity: 'La carte est limitée à 32 blocs. Décoche des liens avant de confirmer.',
            legacy_blocks_not_initialized: 'Tes liens historiques sont en lecture seule. Leur migration doit être finalisée avant cet import.'
        };
        function say(message, failed) {
            notice.textContent = message; notice.hidden = !message; notice.dataset.kind = failed ? 'error' : 'info';
            if (failed && message) { notice.scrollIntoView({block: 'nearest', behavior: 'instant'}); }
        }
        function error(payload) { return messages[payload.data && payload.data.code] || options.serverError(payload, 'import_failed'); }
        function selectedRows() {
            return rows.filter(function (row) { return row.selected; }).map(function (row) { return {label: row.label, url: row.url, order: row.order}; });
        }
        function countReady() { return rows.filter(function (row) { return row.selected && row.status === 'ready'; }).length; }
        function sourceValue() { return method === 'file' ? drafts.file.source : source.value; }
        function showStage(stage) {
            sourceStage.hidden = stage !== 'source'; preview.hidden = stage !== 'preview';
            host.querySelectorAll('[data-import-step]').forEach(function (step) {
                if (step.dataset.importStep === stage) { step.setAttribute('aria-current', 'step'); }
                else { step.removeAttribute('aria-current'); }
            });
        }
        function addStat(count, singular, plural, kind) {
            if (!count && kind !== 'ready' && kind !== 'capacity') { return; }
            var stat = document.createElement('span'); stat.className = 'faluss-link-import__stat';
            stat.dataset.kind = kind; stat.textContent = count + ' ' + (count === 1 ? singular : plural); summary.appendChild(stat);
        }
        function controls() {
            var count = countReady();
            commit.disabled = busy || !reviewed || !count || count > capacity;
            commit.hidden = !reviewed && rows.length > 0;
            commit.textContent = busy && reviewed ? 'Importation…' : 'Importer ' + count + ' lien' + (count > 1 ? 's' : '');
            review.textContent = busy ? 'Vérification…' : 'Vérifier les modifications';
            review.hidden = reviewed || !rows.length; review.disabled = busy;
            analyseButton.disabled = busy || !sourceValue().trim();
            summary.replaceChildren();
            addStat(count, 'prêt', 'prêts', 'ready');
            addStat(rows.filter(function (r) { return r.status === 'duplicate'; }).length, 'doublon', 'doublons', 'duplicate');
            addStat(rows.filter(function (r) { return r.status === 'invalid'; }).length, 'à corriger', 'à corriger', 'invalid');
            addStat(capacity, 'place disponible', 'places disponibles', 'capacity');
            list.querySelectorAll('input[type=checkbox]').forEach(function (checkbox, index) { checkbox.disabled = busy || rows[index].status !== 'ready'; });
            if (count > capacity) { say(messages.import_capacity, true); }
            else if (notice.textContent === messages.import_capacity) { say(''); }
        }
        function setBusy(value) {
            options.busy(value); busy = value || options.blocked();
            host.setAttribute('aria-busy', String(busy));
            host.querySelectorAll('button, input, textarea, select').forEach(function (control) { control.disabled = busy; });
            controls();
        }
        function selectMethod(next, focus) {
            if (busy || options.isBusy()) { return; }
            drafts[method].format = format.value; method = next; format.value = drafts[method].format;
            tabs.forEach(function (tab) {
                var active = tab.dataset.importMethod === method;
                tab.setAttribute('aria-selected', String(active)); tab.tabIndex = active ? 0 : -1;
                if (active && focus) { tab.focus(); }
            });
            host.querySelectorAll('[data-import-method-panel]').forEach(function (panel) { panel.hidden = panel.dataset.importMethodPanel !== method; });
            say(''); reviewed = false; controls();
        }
        tabs.forEach(function (tab, index) {
            tab.addEventListener('click', function () { selectMethod(tab.dataset.importMethod, false); });
            tab.addEventListener('keydown', function (event) {
                if (!/^(ArrowLeft|ArrowRight|Home|End)$/.test(event.key)) { return; }
                event.preventDefault();
                var next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (index + (event.key === 'ArrowLeft' ? -1 : 1) + tabs.length) % tabs.length;
                selectMethod(tabs[next].dataset.importMethod, true);
            });
        });
        function labelInput(parent, text, type, value) {
            var label = document.createElement('label'), input = document.createElement('input');
            label.appendChild(document.createTextNode(text)); input.type = type; input.value = value;
            label.appendChild(input); parent.appendChild(label); return input;
        }
        function element(tag, className, text) {
            var node = document.createElement(tag); node.className = className;
            if (text) { node.textContent = text; } return node;
        }
        function hostname(url) {
            try { return new URL(url).hostname || 'URL à vérifier'; } catch (ignored) { return 'URL à vérifier'; }
        }
        function render() {
            list.replaceChildren();
            rows.forEach(function (row, index) {
                var item = document.createElement('li'); item.dataset.importStatus = row.status;
                var details = element('details', 'faluss-link-import__row');
                var heading = document.createElement('summary');
                var select = element('label', 'faluss-link-import__select');
                select.appendChild(element('span', 'faluss-link-import__sr-only', 'Importer ce lien'));
                var checkbox = document.createElement('input'); checkbox.type = 'checkbox'; checkbox.checked = row.selected;
                select.appendChild(checkbox); heading.appendChild(select);
                var text = element('span', 'faluss-link-import__row-text');
                var titleText = element('span', 'faluss-link-import__row-title', row.label || 'Lien sans titre');
                var hostText = element('span', 'faluss-link-import__row-host', hostname(row.url));
                text.append(titleText, hostText); heading.appendChild(text);
                heading.appendChild(element('span', 'faluss-link-import__badge', row.status === 'ready' ? 'Prêt' : row.status === 'duplicate' ? 'Déjà là' : 'À corriger'));
                var chevron = element('span', 'faluss-link-import__chevron', '⌄'); chevron.setAttribute('aria-hidden', 'true'); heading.appendChild(chevron);
                details.appendChild(heading);
                var fields = element('div', 'faluss-link-import__row-fields');
                var title = labelInput(fields, 'Titre', 'text', row.label); title.maxLength = 80;
                var url = labelInput(fields, 'URL HTTPS', 'text', row.url); url.maxLength = 2048;
                var order = labelInput(fields, 'Ordre', 'number', row.order); order.step = '1'; order.parentElement.className = 'faluss-link-import__order';
                var state = element('p', 'faluss-link-import__row-message', row.message || 'Prêt à importer'); state.id = 'import-row-status-' + index;
                [title, url, checkbox].forEach(function (input) { input.setAttribute('aria-describedby', state.id); });
                url.setAttribute('aria-invalid', String(row.status === 'invalid')); fields.appendChild(state);
                details.appendChild(fields); item.appendChild(details); list.appendChild(item);
                // Selecting a row must not also open its editor.
                select.addEventListener('click', function (event) { event.stopPropagation(); });
                checkbox.addEventListener('change', function () { row.selected = checkbox.checked; dirty = true; controls(); });
                [title, url, order].forEach(function (input) {
                    input.addEventListener('input', function () {
                        row.label = title.value; row.url = url.value; row.order = Number.isSafeInteger(Number(order.value)) ? Number(order.value) : index;
                        titleText.textContent = row.label || 'Lien sans titre'; hostText.textContent = hostname(row.url);
                        dirty = true; reviewed = false; controls(); say('Tes modifications sont prêtes à être vérifiées.');
                    });
                });
            });
            showStage('preview'); controls();
            host.querySelector('[data-import-heading]').focus({preventScroll: true});
            preview.scrollIntoView({block: 'start', behavior: 'instant'});
        }
        function analyse(editing) {
            if (options.isBusy() || busy) { return; }
            if (editing && !rows.length) { return; }
            var selections = rows.map(function (row) { return row.selected; });
            var request = editing ? {rows: JSON.stringify(rows.map(function (row) { return {label:row.label,url:row.url,order:row.order}; }))} : {source:sourceValue(),format:format.value};
            setBusy(true); say('Analyse des liens…');
            options.post('faluss_studio_link_import_preview', options.config().importNonce, request).then(function (payload) {
                if (!payload.success) { throw new Error(error(payload)); }
                rows = payload.data.rows;
                // Explicit exclusions survive revalidation; corrected errors become selectable.
                rows.forEach(function (row, i) {
                    if (editing) { row.selected = row.status === 'ready' && selections[i]; }
                    else { row.order = i + 1; }
                });
                capacity = payload.data.capacity; dirty = true; reviewed = true; render(); say('');
            }).catch(function (failure) { say(failure.message || 'Analyse impossible. Réessaie.', true); }).finally(function () { setBusy(false); });
        }
        host.querySelector('[data-import-back]').addEventListener('click', function () {
            if (busy || options.isBusy()) { return; }
            reviewed = false; showStage('source'); controls(); say('');
            tabs.find(function (tab) { return tab.dataset.importMethod === method; }).focus({preventScroll: true});
            sourceStage.scrollIntoView({block: 'start', behavior: 'instant'});
        });
        file.addEventListener('change', function () {
            var chosen = file.files && file.files[0], token = ++revision;
            if (!chosen) { return; }
            dirty = true; reviewed = false; drafts.file.source = ''; drafts.file.name = ''; fileName.textContent = 'Choisir un fichier'; controls();
            if (chosen.size > 524288) { say(messages.too_large, true); file.value = ''; return; }
            if (!/\.(csv|json)$/i.test(chosen.name)) { say('Choisis un fichier CSV ou JSON.', true); file.value = ''; return; }
            setBusy(true); say('Lecture du fichier…');
            chosen.text().then(function (text) {
                if (token !== revision || !host.isConnected) { return; }
                drafts.file.source = text; drafts.file.name = chosen.name;
                drafts.file.format = /\.json$/i.test(chosen.name) ? 'json' : 'csv';
                format.value = drafts.file.format; fileName.textContent = chosen.name;
                controls(); say('Fichier prêt. Prévisualise tes liens.');
            }).catch(function () { say('Lecture du fichier impossible.', true); }).finally(function () { setBusy(false); });
        });
        source.addEventListener('input', function () { dirty = true; reviewed = false; controls(); say(''); });
        format.addEventListener('change', function () { drafts[method].format = format.value; dirty = true; reviewed = false; controls(); });
        analyseButton.addEventListener('click', function () { analyse(false); });
        review.addEventListener('click', function () { analyse(true); });
        commit.addEventListener('click', function () {
            if (options.isBusy() || busy || !reviewed || commit.disabled) { return; }
            if (!options.confirmOtherDrafts()) { return; }
            setBusy(true); say('Importation…');
            options.post('faluss_studio_v3_manage', options.config().managementNonce, {
                mutation:'import_links', version:options.version(), fields:JSON.stringify({rows:selectedRows()})
            }).then(function (payload) {
                if (!payload.success) { throw new Error(error(payload)); }
                var count = payload.data.imported;
                dirty = false; reviewed = false;
                return options.saved().then(function () { if (!options.blocked()) { options.notice(count + ' lien' + (count > 1 ? 's' : '') + ' importé' + (count > 1 ? 's' : '') + '.', false); } });
            }).catch(function (failure) { say(failure.message || 'Import impossible. Les lignes sont conservées.', true); }).finally(function () { setBusy(false); });
        });
        controls();
        return {isDirty:function () { return dirty; }};
    };
})();
