const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const script = fs.readFileSync(path.join(__dirname, '../../assets/js/faluss-identity-passwordless-login.js'), 'utf8');
let onSubmit;
let resolveRequest;
let requests = 0;

class TestForm {
    constructor(action, component) {
        this.action = action;
        this.component = component;
        this.dataset = {};
        this.button = {
            disabled: false,
            attributes: {},
            setAttribute(name, value) { this.attributes[name] = value; },
            removeAttribute(name) { delete this.attributes[name]; },
        };
    }
    closest(selector) { return selector === '[data-faluss-identity-login]' ? this.component : null; }
    querySelector(selector) {
        if (selector === 'input[name="action"]') { return { value: this.action }; }
        if (selector === 'button[type="submit"]') { return this.button; }
        return null;
    }
}

class TestFormData {
    set() {}
}

const notice = {
    textContent: '',
    hidden: true,
    attributes: {},
    setAttribute(name, value) { this.attributes[name] = value; },
    removeAttribute(name) { delete this.attributes[name]; },
};
const component = { querySelector(selector) { return selector === '.faluss-identity-login__ajax-notice' ? notice : null; } };
const context = {
    HTMLFormElement: TestForm,
    FormData: TestFormData,
    document: { addEventListener(name, listener) { if (name === 'submit') { onSubmit = listener; } } },
    falussIdentityLogin: { url: '/wp-admin/admin-ajax.php' },
    window: { falussIdentityLogin: { url: '/wp-admin/admin-ajax.php' } },
    fetch() { requests++; return new Promise(resolve => { resolveRequest = resolve; }); },
};
vm.runInNewContext(script, context);

async function run() {
    const form = new TestForm('faluss_identity_request_code', component);
    const event = { target: form, preventDefault() {} };
    onSubmit(event);
    assert.equal(requests, 1);
    assert.equal(notice.textContent, 'Envoi en cours…');
    assert.equal(notice.hidden, false);
    assert.equal(notice.attributes['data-state'], 'pending');
    assert.equal(form.button.disabled, true);
    assert.equal(form.button.attributes['aria-busy'], 'true');
    onSubmit(event);
    assert.equal(requests, 1, 'double submission stays blocked');

    resolveRequest({ json: async () => ({ success: false, data: { notice: 'Réessaie plus tard.' } }) });
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(notice.textContent, 'Réessaie plus tard.');
    assert.equal(notice.attributes['data-state'], undefined);
    assert.equal(form.button.disabled, false);
    assert.equal(form.button.attributes['aria-busy'], undefined);

    const verify = new TestForm('faluss_identity_verify_code', component);
    onSubmit({ target: verify, preventDefault() {} });
    assert.equal(notice.textContent, 'Vérification en cours…');
    assert.equal(verify.button.disabled, true);
    console.log('Passwordless pending feedback and duplicate guard: OK');
}

run().catch(error => { console.error(error); process.exitCode = 1; });
