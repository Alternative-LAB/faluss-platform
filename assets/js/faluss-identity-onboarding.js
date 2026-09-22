(function () {
    'use strict';

    var initialized = new WeakSet();

    function request(component, action, values) {
        var body = new URLSearchParams();
        body.set('action', action);
        body.set('nonce', component.getAttribute('data-nonce') || '');
        Object.keys(values || {}).forEach(function (key) { body.set(key, values[key]); });
        return fetch(component.getAttribute('data-ajax-url'), {
            method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: body.toString()
        }).then(function (response) { return response.json(); });
    }

    function notice(component, message, state) {
        var element = component.querySelector('.faluss-identity-onboarding__notice');
        if (!element) { return; }
        element.textContent = message || '';
        element.hidden = !message;
        element.setAttribute('data-state', state || '');
    }

    function availability(component, message, state) {
        var element = component.querySelector('.faluss-identity-onboarding__availability');
        if (!element) { return; }
        element.textContent = message || '';
        element.setAttribute('data-state', state || '');
    }

    function setPending(control, value) {
        if (!control) { return; }
        control.disabled = value;
        control.setAttribute('aria-busy', value ? 'true' : 'false');
    }

    function showIdentifier(component) {
        var choice = component.querySelector('[data-onboarding-step="choice"]');
        var identifier = component.querySelector('[data-onboarding-step="identifier"]');
        if (choice) { choice.hidden = true; }
        if (identifier) { identifier.hidden = false; }
        var input = identifier && identifier.querySelector('input[name="slug"]');
        if (input) { input.focus(); }
    }

    function showChoice(component) {
        var choice = component.querySelector('[data-onboarding-step="choice"]');
        var identifier = component.querySelector('[data-onboarding-step="identifier"]');
        if (identifier) { identifier.hidden = true; }
        if (choice) { choice.hidden = false; }
        availability(component, '', '');
    }

    function init(component) {
        if (!(component instanceof HTMLElement) || initialized.has(component) || !component.getAttribute('data-ajax-url')) { return; }
        initialized.add(component);
        var timer = 0;

        component.addEventListener('click', function (event) {
            var choice = event.target.closest('[data-onboarding-choice]');
            if (choice && component.contains(choice)) {
                event.preventDefault();
                setPending(choice, true);
                request(component, 'faluss_identity_onboarding_choice', { choice: choice.getAttribute('data-onboarding-choice') || '' })
                    .then(function (result) {
                        if (!result || !result.success) { throw new Error('onboarding'); }
                        if (result.data.choice === 'create_card') { showIdentifier(component); }
                        else if (result.data.redirect) { window.location.assign(result.data.redirect); }
                    }).catch(function () { notice(component, 'Nous ne pouvons pas poursuivre pour le moment.', 'error'); })
                    .finally(function () { setPending(choice, false); });
                return;
            }
            if (event.target.closest('[data-onboarding-back]')) { event.preventDefault(); showChoice(component); }
        });

        component.addEventListener('input', function (event) {
            var input = event.target;
            if (!(input instanceof HTMLInputElement) || input.name !== 'slug') { return; }
            window.clearTimeout(timer);
            availability(component, '', '');
            if (!input.value) { return; }
            timer = window.setTimeout(function () {
                request(component, 'faluss_identity_onboarding_availability', { slug: input.value })
                    .then(function (result) {
                        if (!result || !result.success) { throw new Error('availability'); }
                        availability(component, result.data.message, result.data.state);
                    }).catch(function () { availability(component, 'Nous ne pouvons pas vérifier cet identifiant pour le moment.', 'error'); });
            }, 260);
        });

        component.addEventListener('submit', function (event) {
            var form = event.target;
            if (!(form instanceof HTMLFormElement) || !form.matches('[data-onboarding-step="identifier"]')) { return; }
            event.preventDefault();
            var input = form.querySelector('input[name="slug"]');
            var submit = form.querySelector('button[type="submit"]');
            if (!input || !input.value) { availability(component, 'Choisissez un identifiant public valide.', 'invalid'); return; }
            setPending(submit, true);
            request(component, 'faluss_identity_onboarding_reserve_slug', { slug: input.value })
                .then(function (result) {
                    if (!result || !result.success || !result.data.redirect) {
                        availability(component, result && result.data ? result.data.message : 'Nous ne pouvons pas réserver cet identifiant.', 'error');
                        return;
                    }
                    window.location.assign(result.data.redirect);
                }).catch(function () { availability(component, 'Nous ne pouvons pas réserver cet identifiant pour le moment.', 'error'); })
                .finally(function () { setPending(submit, false); });
        });
    }

    function initAll(scope) {
        (scope || document).querySelectorAll('[data-faluss-identity-onboarding]').forEach(init);
    }

    function boot() {
        initAll(document);
        if (!window.elementorFrontend || !window.elementorFrontend.hooks) { return; }
        window.elementorFrontend.hooks.addAction('frontend/element_ready/faluss_identity_onboarding.default', function (scope) { initAll(scope && scope[0] ? scope[0] : document); });
    }

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot, { once: true }); }
    else { boot(); }
    window.addEventListener('elementor/frontend/init', boot);
}());
