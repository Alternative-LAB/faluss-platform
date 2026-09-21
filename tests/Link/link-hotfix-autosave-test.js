'use strict';

const fs = require('fs');
const vm = require('vm');
const path = require('path');

function assert(condition, message) {
    if (!condition) { throw new Error(message); }
}

const root = process.env.FALUSS_HOTFIX_SOURCE_ROOT ? path.resolve(process.env.FALUSS_HOTFIX_SOURCE_ROOT) : path.resolve(__dirname, '../..');
let source = fs.readFileSync(path.join(root, 'assets/link/js/faluss-link-editor.js'), 'utf8');
source = source.replace(/}\(jQuery\)\);\s*$/, "window.__falussHotfixTest = { enqueueStudioMutation: enqueueStudioMutation, hydrateCanonicalBlocks: hydrateCanonicalBlocks, studioFieldValue: studioFieldValue, setCanonicalField: setCanonicalField, saveHeaderAndProfile: saveHeaderAndProfile };}(jQuery));");

function makeChain(length) {
    const values = Object.create(null);
    let proxy;
    const target = { length: length || 0 };
    proxy = new Proxy(target, {
        get(object, property) {
            if (property in object) { return object[property]; }
            if (property === 'val' || property === 'text' || property === 'html') {
                return function (value) { if (arguments.length) { values[property] = value; return proxy; } return values[property] || ''; };
            }
            if (property === 'attr' || property === 'data' || property === 'prop') {
                return function (key, value) { if (arguments.length > 1) { values[property + ':' + key] = value; return proxy; } return values[property + ':' + key] || (property === 'prop' ? false : ''); };
            }
            if (property === 'each') { return function () { return proxy; }; }
            if (property === 'map') { return function () { return { get: function () { return []; } }; }; }
            if (property === 'find' || property === 'filter' || property === 'first' || property === 'children' || property === 'next' || property === 'closest' || property === 'siblings') { return function () { return makeChain(0); }; }
            return function () { return proxy; };
        }
    });
    return proxy;
}

class FieldCollection {
    constructor(elements) { this.elements = elements || []; this.length = this.elements.length; }
    first() { return new FieldCollection(this.elements.slice(0, 1)); }
    filter(selector) {
        let match;
        if (selector === 'input[type="checkbox"]') { return new FieldCollection(this.elements.filter((field) => field.tagName === 'INPUT' && field.type === 'checkbox')); }
        if (selector === 'input[type="radio"]') { return new FieldCollection(this.elements.filter((field) => field.tagName === 'INPUT' && field.type === 'radio')); }
        if (selector === ':checked') { return new FieldCollection(this.elements.filter((field) => field.checked)); }
        match = selector.match(/^\[value="([^"]*)"\]$/);
        return new FieldCollection(match ? this.elements.filter((field) => field.value === match[1]) : []);
    }
    prop(name, value) {
        if (arguments.length === 1) { return this.length ? this.elements[0][name] : undefined; }
        this.elements.forEach((field) => { field[name] = value; });
        return this;
    }
    val(value) {
        if (!arguments.length) { return this.length ? this.elements[0].value : '' ; }
        this.elements.forEach((field) => { field.value = value; });
        return this;
    }
    attr(name, value) {
        if (arguments.length === 1) { return this.length ? (this.elements[0][name] || '') : ''; }
        this.elements.forEach((field) => { field[name] = value; });
        return this;
    }
}

function input(name, type, value, checked) {
    return { tagName: 'INPUT', name: name, type: type, value: value, checked: !!checked };
}

class FakeStudio {
    constructor(version) {
        this.store = Object.create(null);
        this.attributes = { 'data-faluss-studio-version': version, 'data-faluss-studio-tab': 'style', 'data-faluss-studio-section': 'appearance', 'data-faluss-studio-screen': 'create-collection' };
        this.version = version;
        this.tab = 'style';
        this.section = 'appearance';
        this.screen = 'create-collection';
        this.collection = '';
        this.fields = Object.create(null);
        this.form = makeChain(1);
        this.form.attr = (name) => name === 'action' ? 'https://faluss.test/wp-admin/admin-post.php' : '';
        this.form.find = (selector) => {
            if (selector.indexOf('faluss_link_studio_nonce') !== -1) { const field = makeChain(1); field.val = () => 'nonce'; return field; }
            return makeChain(0);
        };
    }
    data(key, value) { if (arguments.length > 1) { this.store[key] = value; return this; } return this.store[key]; }
    removeData(key) { delete this.store[key]; return this; }
    addClass() { return this; }
    removeClass() { return this; }
    addField(field) { if (!this.fields[field.name]) { this.fields[field.name] = []; } this.fields[field.name].push(field); return field; }
    attr(key, value) {
        if (arguments.length > 1) {
            this.attributes[key] = value;
            if (key === 'data-faluss-studio-version') { this.version = value; }
            if (key === 'data-faluss-studio-tab') { this.tab = value; }
            if (key === 'data-faluss-studio-section') { this.section = value; }
            if (key === 'data-faluss-studio-screen') { this.screen = value; }
            return this;
        }
        return this.attributes[key] || '';
    }
    find(selector) {
        let match = selector.match(/^\[name="([^"]+)"\]$/);
        if (match) { return new FieldCollection(this.fields[match[1]] || []); }
        match = selector.match(/^input\[name="([^"]+)"\]\[type="checkbox"\]$/);
        if (match) { return new FieldCollection((this.fields[match[1]] || []).filter((field) => field.tagName === 'INPUT' && field.type === 'checkbox')); }
        if (selector === '.faluss-link-studio__form') { return this.form; }
        if (selector === '[data-fl-main-panel]') { return makeChain(1); }
        if (selector.indexOf('[data-fl-tab][aria-pressed="true"]') === 0) { const field = makeChain(1), owner = this; field.first = function () { return field; }; field.data = function (key) { return key === 'fl-tab' ? owner.tab : ''; }; return field; }
        if (selector === '[data-fl-active-tab]') { const field = makeChain(1), owner = this; field.val = function (value) { if (arguments.length) { owner.tab = value; return field; } return owner.tab; }; return field; }
        if (selector === '[data-fl-active-section]') { const field = makeChain(1), owner = this; field.val = function (value) { if (arguments.length) { owner.section = value; return field; } return owner.section; }; return field; }
        if (selector === '[data-fl-aggregate-version]') { const field = makeChain(1), owner = this; field.val = function (value) { if (arguments.length) { owner.version = value; return field; } return owner.version; }; return field; }
        if (selector === '[data-fl-active-collection]') { const field = makeChain(1), owner = this; field.val = function (value) { if (arguments.length) { owner.collection = value; return field; } return owner.collection; }; return field; }
        return makeChain(selector === '.faluss-link-studio__notice' ? 1 : 0);
    }
}

const sent = [];
const pendingFetches = [];
const eventHandlers = Object.create(null);
let collectionButton = null;
let collectionButtonStudio = null;
let collectionButtonScreen = null;
const fakeWindow = {
    setTimeout: function () { return 1; },
    clearTimeout: function () {},
    requestAnimationFrame: function (callback) { callback(); },
    matchMedia: function () { return { matches: false }; },
    addEventListener: function () {},
    fetch: function (url, options) {
        sent.push(Object.fromEntries(options.body.entries()));
        return new Promise(function (resolve) { pendingFetches.push(resolve); });
    }
};
const fakeDocument = {};
const documentChain = {
    off: function () { return documentChain; },
    on: function (event, selector, handler) { if (typeof selector === 'string' && typeof handler === 'function') { eventHandlers[selector] = handler; } return documentChain; }
};
function jquery(value) {
    if (typeof value === 'function') { return makeChain(0); }
    if (value === fakeDocument) { return documentChain; }
    if (value === collectionButton) { return { closest: function (selector) { return selector === '.faluss-link-studio' ? collectionButtonStudio : collectionButtonScreen; } }; }
    return makeChain(value && typeof value === 'string' && value.charAt(0) === '<' ? 1 : 0);
}
jquery.trim = function (value) { return String(value || '').trim(); };

const context = {
    window: fakeWindow,
    document: fakeDocument,
    jQuery: jquery,
    URL: URL,
    FormData: FormData,
    Promise: Promise,
    Object: Object,
    Array: Array,
    String: String,
    JSON: JSON,
    Error: Error,
    console: console,
    requestAnimationFrame: fakeWindow.requestAnimationFrame,
    FileReader: function () {}
};
vm.runInNewContext(source, context, { filename: 'faluss-link-editor.js' });
const enqueue = fakeWindow.__falussHotfixTest.enqueueStudioMutation;
const hydrate = fakeWindow.__falussHotfixTest.hydrateCanonicalBlocks;
const fieldValue = fakeWindow.__falussHotfixTest.studioFieldValue;
const setCanonicalField = fakeWindow.__falussHotfixTest.setCanonicalField;
const saveHeaderAndProfile = fakeWindow.__falussHotfixTest.saveHeaderAndProfile;
const tick = () => new Promise((resolve) => setImmediate(resolve));
const response = (ok, code, version) => ({
    ok: ok,
    json: () => Promise.resolve({ success: ok, data: { code: code, message: ok ? 'saved' : 'conflict', state: { blocks: [], version: version, profile: {}, preferences: {}, links_html: '', collections_html: '', collection_html: '', preview_html: '' } } })
});

function headerStudio(version, enabled, published) {
    const studio = new FakeStudio(version);
    ['available', 'avatar_visible', 'avatar_border'].forEach(function (name) {
        studio.addField(input(name, 'hidden', '0', false));
        studio.addField(input(name, 'checkbox', '1', enabled));
    });
    studio.addField(input('publication_status', 'checkbox', 'published', published));
    studio.addField(input('display_name', 'text', 'Membre', false));
    studio.addField(input('bio', 'text', 'Bio', false));
    studio.addField(input('faluss_identity_avatar_id', 'hidden', '0', false));
    studio.addField(input('name_font', 'text', 'outfit', false));
    studio.addField(input('name_treatment', 'text', 'strong', false));
    studio.addField(input('name_color', 'text', '#000000', false));
    studio.addField(input('alignment', 'radio', 'left', false));
    studio.addField(input('alignment', 'radio', 'center', true));
    studio.addField(input('social_layout', 'text', 'bubbles', false));
    studio.addField(input('social_variant', 'text', 'outline', false));
    return studio;
}

(async function () {
    const studio = new FakeStudio('a'.repeat(64));
    const first = enqueue(studio, 'save_appearance', { page_background: '#111111' }, { key: 'appearance' });
    assert(sent.length === 1, 'The first mutation must start immediately.');
    const second = enqueue(studio, 'save_appearance', { page_background: '#222222' }, { key: 'appearance' });
    const third = enqueue(studio, 'save_appearance', { hero_transition_color: '#333333' }, { key: 'appearance' });
    assert(sent.length === 1, 'A running mutation must serialize later changes.');
    pendingFetches.shift()(response(true, 'saved', 'b'.repeat(64)));
    await tick(); await tick(); await tick();
    assert(sent.length === 2, 'A consolidated pending mutation must start immediately after the running save.');
    assert(sent[1].page_background === '#222222' && sent[1].hero_transition_color === '#333333', 'Changes made during autosave must be consolidated, not dropped.');
    assert(sent[1].aggregate_version === 'b'.repeat(64), 'The pending mutation must use the server-returned canonical version.');
    pendingFetches.shift()(response(true, 'saved', 'c'.repeat(64)));
    assert(await first && await second && await third, 'Every consolidated caller must resolve after persistence.');
    assert(sent.length === 2, 'Consolidation must avoid duplicate saves.');

    const conflictStudio = new FakeStudio('d'.repeat(64));
    const stale = enqueue(conflictStudio, 'save_header', { available: '1' }, { key: 'header' });
    const postConflictChange = enqueue(conflictStudio, 'save_header', { available: '0' }, { key: 'header' });
    pendingFetches.shift()(response(false, 'stale_version', 'e'.repeat(64)));
    await tick(); await tick(); await tick();
    assert(sent.length === 4, 'A user change made during a stale request must remain queued after canonical rehydration.');
    assert(sent[3].available === '0' && sent[3].aggregate_version === 'e'.repeat(64), 'The post-conflict pending change must run against the new canonical version.');
    pendingFetches.shift()(response(true, 'saved', 'f'.repeat(64)));
    assert(!(await stale) && await postConflictChange, 'The stale mutation must be refused while the later pending change is persisted.');

    const collectionId = '11111111-2222-4333-8444-555555555555';
    hydrate(conflictStudio, { blocks: [], active_collection: collectionId, version: 'f'.repeat(64), links_html: '', collections_html: '', collection_html: '', preview_html: '' });
    assert(conflictStudio.collection === collectionId && conflictStudio.attr('data-faluss-studio-collection') === collectionId, 'Canonical hydration must restore the active collection as well as its panels.');

    const enabledStudio = headerStudio('1'.repeat(64), true, true);
    ['available', 'avatar_visible', 'avatar_border'].forEach(function (name) {
        assert(enabledStudio.find('[name="' + name + '"]').length === 2, name + ' must faithfully expose the hidden fallback and checkbox with the same name.');
        assert(fieldValue(enabledStudio, name) === '1', name + ' must read the checked checkbox instead of the first hidden fallback.');
        setCanonicalField(enabledStudio, name, 0);
        assert(enabledStudio.find('[name="' + name + '"]').filter('input[type="checkbox"]').prop('checked') === false, name + ' must become visually unchecked after canonical hydration.');
        assert(enabledStudio.find('[name="' + name + '"]').filter('input[type="checkbox"]').val() === '1', name + ' must retain its business HTML value after canonical hydration.');
        assert(enabledStudio.find('[name="' + name + '"]').first().val() === '0', name + ' must leave the hidden HTML fallback unchanged.');
        setCanonicalField(enabledStudio, name, 1);
        assert(enabledStudio.find('[name="' + name + '"]').filter('input[type="checkbox"]').prop('checked') === true, name + ' must become visually checked after canonical hydration.');
    });
    setCanonicalField(enabledStudio, 'publication_status', 'draft');
    assert(enabledStudio.find('[name="publication_status"]').prop('checked') === false && enabledStudio.find('[name="publication_status"]').val() === 'published', 'Canonical draft hydration must uncheck publication without replacing its published HTML value.');
    setCanonicalField(enabledStudio, 'publication_status', 'published');
    const enabledStart = sent.length;
    const enabledSave = saveHeaderAndProfile(enabledStudio);
    assert(sent[enabledStart].mutation === 'save_profile' && sent[enabledStart].publication_status === 'published', 'A manual save of a visible profile must explicitly send publication_status=published.');
    pendingFetches.shift()(response(true, 'saved', '2'.repeat(64)));
    await tick(); await tick(); await tick();
    assert(sent[enabledStart + 1].mutation === 'save_header', 'The header mutation must run after the visible profile mutation.');
    ['available', 'avatar_visible', 'avatar_border'].forEach((name) => assert(sent[enabledStart + 1][name] === '1', name + '=1 must be present in the sent FormData.'));
    pendingFetches.shift()(response(true, 'saved', '3'.repeat(64)));
    assert(await enabledSave, 'The visible manual save and its header mutation must both complete.');

    const disabledStudio = headerStudio('4'.repeat(64), false, false);
    ['available', 'avatar_visible', 'avatar_border'].forEach((name) => assert(fieldValue(disabledStudio, name) === '0', name + ' must produce 0 when its checkbox is unchecked.'));
    const disabledStart = sent.length;
    const disabledSave = saveHeaderAndProfile(disabledStudio);
    assert(sent[disabledStart].mutation === 'save_profile' && sent[disabledStart].publication_status === 'draft', 'A manual save of a hidden profile must explicitly send publication_status=draft.');
    pendingFetches.shift()(response(true, 'saved', '5'.repeat(64)));
    await tick(); await tick(); await tick();
    assert(sent[disabledStart + 1].mutation === 'save_header', 'The header mutation must still run after the hidden profile mutation.');
    ['available', 'avatar_visible', 'avatar_border'].forEach((name) => assert(sent[disabledStart + 1][name] === '0', name + '=0 must be present in the sent FormData.'));
    pendingFetches.shift()(response(true, 'saved', '6'.repeat(64)));
    assert(await disabledSave, 'The hidden manual save and its header mutation must both complete.');

    const collectionStudio = new FakeStudio('7'.repeat(64));
    const collectionValues = { '[data-fl-new-collection-name]': 'Nouvelle collection canonique', '[data-fl-new-collection-description]': 'Description' };
    collectionButtonStudio = collectionStudio;
    collectionButtonScreen = { find: function (selector) { const field = makeChain(1); field.val = function () { return collectionValues[selector] || ''; }; return field; } };
    collectionButton = {};
    const createCollectionHandler = eventHandlers['.faluss-link-studio [data-fl-create-collection-submit]'];
    assert(typeof createCollectionHandler === 'function', 'The real delegated collection creation handler must be registered.');
    const beforeCollectionCreation = sent.length;
    createCollectionHandler.call(collectionButton);
    assert(sent.length === beforeCollectionCreation + 1 && sent[beforeCollectionCreation].mutation === 'create_collection', 'Collection creation must issue exactly its targeted mutation.');
    pendingFetches.shift()(response(true, 'saved', '8'.repeat(64)));
    await tick(); await tick(); await tick();
    assert(collectionStudio.screen === 'main' && collectionStudio.tab === 'links' && collectionStudio.section === 'collections' && collectionStudio.collection === '', 'A successful collection callback must restore main > links > collections with no active collection.');
    assert(sent.length === beforeCollectionCreation + 1, 'Returning to the canonical Collections panel must not issue a second mutation.');

    process.stdout.write('FL-HOTFIX-01.2 Studio collection navigation, switches and autosave: OK\n');
})().catch(function (error) { process.stderr.write('FAIL: ' + error.message + '\n'); process.exit(1); });
