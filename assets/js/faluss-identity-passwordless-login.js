(function () {
    'use strict';

    function setNotice(component, message) {
        var notice = component.querySelector('.faluss-identity-login__ajax-notice');
        if (!notice) { return; }
        notice.textContent = message || '';
        notice.hidden = !message;
    }

    function replaceStage(form, markup, expectedStage) {
        if (typeof markup !== 'string') { return false; }
        var current = form.closest('.faluss-identity-login__otp-stage') || form;
        var wrapper = document.createElement('div');
        wrapper.innerHTML = markup;
        var stage = wrapper.firstElementChild;
        if (!stage || stage.getAttribute('data-faluss-login-stage') !== expectedStage) { return false; }
        current.replaceWith(stage);
        return true;
    }

    function replaceWithOtp(form, markup) {
        return replaceStage(form, markup, 'otp');
    }

    function replaceWithEmail(form, markup) {
        return replaceStage(form, markup, 'email');
    }

    function setSubmitting(form, submitting) {
        form.dataset.falussIdentityPending = submitting ? '1' : '0';
        var submit = form.querySelector('button[type="submit"]');
        if (submit) {
            submit.disabled = submitting;
            if (submitting) { submit.setAttribute('aria-busy', 'true'); }
            else { submit.removeAttribute('aria-busy'); }
        }
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement)) { return; }
        var component = form.closest('[data-faluss-identity-login]');
        if (!component || !window.falussIdentityLogin) { return; }
        var action = form.querySelector('input[name="action"]');
        if (!action || (action.value !== 'faluss_identity_request_code' && action.value !== 'faluss_identity_verify_code')) { return; }
        event.preventDefault();
        if (form.dataset.falussIdentityPending === '1') { return; }
        setSubmitting(form, true);
        var body = new FormData(form);
        body.set('action', action.value + '_ajax');
        setNotice(component, '');
        fetch(falussIdentityLogin.url, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (response) { return response.json(); })
            .then(function (result) {
                if (!result || !result.success) {
                    var stageReplaced = false;
                    if (action.value === 'faluss_identity_verify_code' && result && result.data && result.data.reset_to_email) {
                        stageReplaced = replaceWithEmail(form, result.data.email_html);
                    }
                    if (!stageReplaced) { setSubmitting(form, false); }
                    setNotice(component, result && result.data ? result.data.notice : 'Nous ne pouvons pas poursuivre cette vérification.');
                    return;
                }
                if (action.value === 'faluss_identity_verify_code') {
                    window.location.assign(result.data.redirect);
                    return;
                }
                setNotice(component, result.data.notice);
                if (!replaceWithOtp(form, result.data.otp_html)) {
                    setSubmitting(form, false);
                    setNotice(component, 'Nous ne pouvons pas poursuivre cette vérification.');
                    return;
                }
                var otp = component.querySelector('input[name="otp"]');
                if (otp) { otp.focus(); }
            })
            .catch(function () {
                setSubmitting(form, false);
                setNotice(component, 'Nous ne pouvons pas poursuivre cette vérification.');
            });
    });
}());
