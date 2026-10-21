/*
 * Behavioural test for the "add gateway" dialog's port blocks.
 *
 * The bug: the device fields live inside <template id="tpl-gateway-create">, so
 * `document.querySelector('[data-gateway-model]')` ran before the template was
 * ever copied into the dialog and returned null. The sync never ran, so all
 * eight port blocks stayed visible even for a one-port GoIP.
 *
 * Grepping the view for "syncLines" would prove nothing about that, so the real
 * controller is pulled out of the Blade file and driven against a stubbed DOM
 * that mirrors the dialog: the template content stays a detached fragment until
 * the dialog is opened, the model <select> is a fresh node after every open,
 * and a `change` bubbles up to the dialog body where the delegated listener sits.
 *
 *   load (the dialog is empty)
 *     -> open on a one-port model: exactly one port is visible AND submitted
 *     -> switch to the eight-port model: eight ports become visible
 *     -> switch back to one port: one port again
 *     -> close and reopen: the initial state is re-applied to the new nodes
 *     -> reopen with a rejected form's old input (eight ports): eight ports
 *
 * The last block is a negative control: the same open is replayed against a copy
 * of the controller with the observer registration removed, and it must
 * reproduce the reported bug (every port block visible on open).
 *
 * Run with: node tests/js/gateway-ports.probe.mjs
 */
import fs from 'node:fs';

const VIEW = fs.readFileSync(
    new URL('../../resources/views/company/gateways.blade.php', import.meta.url),
    'utf8'
);

/* ------------------------------------------------------------------ *
 * Extract the real controller out of the Blade view
 * ------------------------------------------------------------------ */

function extractController(source) {
    const push = /@push\('scripts'\)([\s\S]*?)@endpush/.exec(source);
    if (!push) {
        throw new Error('the scripts push block was not found in the view');
    }

    const script = /<script>([\s\S]*?)<\/script>/.exec(push[1]);
    if (!script) {
        throw new Error('the inline controller was not found');
    }

    const body = script[1].replace(/@json\(__\('[^']*'\)\)/g, "'stub'");

    if (body.indexOf('@json') !== -1 || body.indexOf('{{') !== -1 || body.indexOf('<?php') !== -1) {
        throw new Error('a Blade directive survived extraction');
    }

    return body;
}

const CONTROLLER = extractController(VIEW);

/* ------------------------------------------------------------------ *
 * A very small DOM: only what the controller actually touches
 * ------------------------------------------------------------------ */

function selectorMatches(el, selector) {
    const attr = /^\[([^\]]+)\]$/.exec(selector);
    if (attr) {
        return el.hasAttribute(attr[1]);
    }
    if (selector.charAt(0) === '.') {
        return String(el.className).split(/\s+/).indexOf(selector.slice(1)) !== -1;
    }
    return el.tagName === selector.toUpperCase();
}

function descendants(el, out = []) {
    el.children.forEach((child) => {
        out.push(child);
        descendants(child, out);
    });
    return out;
}

class El {
    constructor(tag, attributes = {}) {
        this.tagName = String(tag).toUpperCase();
        this.attributes = {};
        this.children = [];
        this.parentNode = null;
        this.listeners = {};
        this.observers = [];
        this.className = '';
        this.textContent = '';
        this.value = '';
        this.options = [];
        this.selectedIndex = 0;
        // `[hidden]{display:none!important}` comes from Bootstrap, which every
        // company page loads; a one-port GoIP must therefore also disable the
        // surplus inputs so the browser never submits them.
        this.hidden = false;
        this.disabled = false;
        Object.keys(attributes).forEach((key) => this.setAttribute(key, attributes[key]));
    }
    setAttribute(name, value) {
        this.attributes[name] = String(value);
    }
    getAttribute(name) {
        return Object.prototype.hasOwnProperty.call(this.attributes, name)
            ? this.attributes[name]
            : null;
    }
    hasAttribute(name) {
        return Object.prototype.hasOwnProperty.call(this.attributes, name);
    }
    appendChild(child) {
        child.parentNode = this;
        this.children.push(child);
        return child;
    }
    addEventListener(type, fn) {
        (this.listeners[type] = this.listeners[type] || []).push(fn);
    }
    dispatchEvent(event) {
        // `change` bubbles, which is what makes the delegated listener work.
        let node = this;
        while (node) {
            (node.listeners[event.type] || []).slice().forEach((fn) => fn.call(node, event));
            node = node.parentNode;
        }
        return true;
    }
    closest(selector) {
        let node = this;
        while (node) {
            if (selectorMatches(node, selector)) {
                return node;
            }
            node = node.parentNode;
        }
        return null;
    }
    querySelector(selector) {
        return this.querySelectorAll(selector)[0] || null;
    }
    querySelectorAll(selector) {
        return descendants(this).filter((el) => selectorMatches(el, selector));
    }
}

class MutationObserverStub {
    constructor(callback) {
        this.callback = callback;
        MutationObserverStub.created += 1;
    }
    observe(target) {
        this.target = target;
        target.observers.push(this);
        MutationObserverStub.observed.push(target);
    }
    disconnect() {}
    takeRecords() {
        return [];
    }
}
MutationObserverStub.created = 0;
MutationObserverStub.observed = [];

/** Replace a node's children the way `dialogBody.innerHTML = ...` does. */
function replaceChildren(target, nodes) {
    target.children = [];
    nodes.forEach((node) => target.appendChild(node));
    target.observers.slice().forEach((observer) => observer.callback([{ type: 'childList', target }]));
}

/* ------------------------------------------------------------------ *
 * The <template> content, exactly as the view renders it
 * ------------------------------------------------------------------ */

const PROFILES = [
    { key: 'goip-1', lines: 1 },
    { key: 'goip-4', lines: 4 },
    { key: 'goip-8', lines: 8 },
];

const TOTAL_ROWS = 8;

function buildTemplate(selected = 'goip-1', oldLabels = null) {
    const select = new El('select', { 'data-gateway-model': '', name: 'model' });
    select.options = PROFILES.map((profile) => {
        const option = new El('option', { 'data-lines': String(profile.lines), value: profile.key });
        option.selected = profile.key === selected;
        return option;
    });
    select.options.forEach((option) => select.appendChild(option));
    select.selectedIndex = PROFILES.findIndex((profile) => profile.key === selected);
    select.value = selected;

    const notes = PROFILES.map((profile) => new El('span', { 'data-gateway-note': profile.key }));

    const container = new El('div', { 'data-gateway-lines': '' });
    const rows = [];
    for (let index = 0; index < TOTAL_ROWS; index += 1) {
        const row = new El('div', { 'data-company-row': '' });
        row.appendChild(new El('input', { name: `lines[${index}][label]`, value: (oldLabels && oldLabels[index]) || '' }));
        row.appendChild(new El('input', { name: `lines[${index}][sim_number]` }));
        row.appendChild(new El('input', { name: `lines[${index}][enabled]`, type: 'checkbox' }));
        container.appendChild(row);
        rows.push(row);
    }

    return { select, notes, container, rows, nodes: [select, ...notes, container] };
}

/* ------------------------------------------------------------------ *
 * Harness
 * ------------------------------------------------------------------ */

function boot(source) {
    const modalBody = new El('div', { id: 'modalBody' });
    const byId = { modalBody };

    const document = {
        getElementById: (id) => byId[id] || null,
        createElement: (tag) => new El(tag),
        querySelectorAll: () => [],
        querySelector: () => null,
        addEventListener: () => {},
    };

    const run = new Function('document', 'MutationObserver', 'fetch', 'console', source);
    run(
        document,
        MutationObserverStub,
        () => Promise.resolve({ ok: true, json: () => Promise.resolve({}) }),
        { log: () => {}, error: () => {}, warn: () => {} }
    );

    return { modalBody };
}

/** The controls a browser would actually submit: named and not disabled. */
function submittedNames(root) {
    return descendants(root)
        .filter((el) => el.hasAttribute('name') && el.disabled === false)
        .map((el) => el.getAttribute('name'));
}

function portFieldCount(root) {
    return submittedNames(root).filter((name) => name.indexOf('lines[') === 0).length;
}

function visibleRows(fixture) {
    return fixture.rows.filter((row) => row.hidden === false).length;
}

function selectModel(fixture, key) {
    fixture.select.selectedIndex = PROFILES.findIndex((profile) => profile.key === key);
    fixture.select.value = key;
    fixture.select.dispatchEvent({ type: 'change', target: fixture.select });
}

function visibleNotes(fixture) {
    return fixture.notes
        .filter((note) => note.hidden === false)
        .map((note) => note.getAttribute('data-gateway-note'));
}

/* ------------------------------------------------------------------ *
 * Probe
 * ------------------------------------------------------------------ */

const results = [];
function check(name, condition, detail) {
    results.push({ name, ok: !!condition, detail: detail === undefined ? '' : String(detail) });
}

function openDialog(ctx, fixture) {
    replaceChildren(ctx.modalBody, fixture.nodes);
}

function main() {
    const ctx = boot(CONTROLLER);

    /* --- load: the fields are still a detached template ---------------- */
    check(
        'load: nothing is bound inside the dialog yet (the template is inert)',
        ctx.modalBody.querySelector('[data-gateway-model]') === null,
        'modal body children=' + ctx.modalBody.children.length
    );
    check(
        'load: the port blocks are not in the document until the dialog opens',
        ctx.modalBody.querySelectorAll('[data-company-row]').length === 0,
        'rows=' + ctx.modalBody.querySelectorAll('[data-company-row]').length
    );

    /* --- open on the one-port model ------------------------------------ */
    const first = buildTemplate('goip-1');
    openDialog(ctx, first);

    check(
        'open (1 port): exactly one port block is visible',
        visibleRows(first) === 1,
        'visible=' + visibleRows(first)
    );
    check(
        'open (1 port): the seven surplus blocks are hidden',
        first.rows.slice(1).every((row) => row.hidden === true),
        'hidden=' + first.rows.slice(1).filter((row) => row.hidden).length
    );
    check(
        'open (1 port): the surplus inputs are disabled so they are not submitted',
        portFieldCount(ctx.modalBody) === 3,
        'submitted port fields=' + portFieldCount(ctx.modalBody)
    );
    check(
        'open (1 port): only the matching device note is shown',
        visibleNotes(first).length === 1 && visibleNotes(first)[0] === 'goip-1',
        JSON.stringify(visibleNotes(first))
    );

    /* --- switch to eight ports ----------------------------------------- */
    selectModel(first, 'goip-8');

    check(
        'switch to 8: all eight port blocks are visible',
        visibleRows(first) === 8,
        'visible=' + visibleRows(first)
    );
    check(
        'switch to 8: every port field is submitted again',
        portFieldCount(ctx.modalBody) === 24,
        'submitted port fields=' + portFieldCount(ctx.modalBody)
    );
    check(
        'switch to 8: the note follows the selection',
        visibleNotes(first).length === 1 && visibleNotes(first)[0] === 'goip-8',
        JSON.stringify(visibleNotes(first))
    );

    /* --- back to one port ---------------------------------------------- */
    selectModel(first, 'goip-1');

    check(
        'switch back to 1: one port block is visible again',
        visibleRows(first) === 1,
        'visible=' + visibleRows(first)
    );
    check(
        'switch back to 1: only that port is submitted',
        portFieldCount(ctx.modalBody) === 3,
        'submitted port fields=' + portFieldCount(ctx.modalBody)
    );

    /* --- close and reopen ---------------------------------------------- */
    replaceChildren(ctx.modalBody, []);
    const reopened = buildTemplate('goip-1');
    openDialog(ctx, reopened);

    check(
        'reopen: the fresh nodes are synced from the observer',
        visibleRows(reopened) === 1 && portFieldCount(ctx.modalBody) === 3,
        'visible=' + visibleRows(reopened) + ' fields=' + portFieldCount(ctx.modalBody)
    );

    /* --- reopen after a rejected form (old input kept) ------------------ */
    const rejected = buildTemplate('goip-8', ['SIM 1', 'SIM 2']);
    openDialog(ctx, rejected);

    check(
        'reopen after a rejected form: the old eight-port choice is honoured',
        visibleRows(rejected) === 8,
        'visible=' + visibleRows(rejected)
    );
    check(
        'reopen after a rejected form: the old port labels survived',
        rejected.rows[0].children[0].getAttribute('value') === 'SIM 1',
        rejected.rows[0].children[0].getAttribute('value')
    );

    /* --- the delegated listener survives a reopen ----------------------- */
    selectModel(rejected, 'goip-4');
    check(
        'delegated change: works on a node created after the first open',
        visibleRows(rejected) === 4,
        'visible=' + visibleRows(rejected)
    );

    /* --- negative control ------------------------------------------------ */
    // Replay the same open against a copy with the observer registration
    // removed. If the root cause is right, the reported bug must come back: the
    // initial state is never applied, so all eight blocks stay visible.
    const WITHOUT_OBSERVER = CONTROLLER.replace(
        '.observe(modalBody, {childList: true});',
        '/* observer registration removed for the negative control */;'
    );
    check(
        'negative control: the controller really was mutated',
        WITHOUT_OBSERVER !== CONTROLLER && WITHOUT_OBSERVER.indexOf('.observe(modalBody') === -1,
        'mutation applied'
    );

    const broken = boot(WITHOUT_OBSERVER);
    const brokenFixture = buildTemplate('goip-1');
    openDialog(broken, brokenFixture);

    check(
        'negative control: WITHOUT the observer the one-port model still shows eight ports — the bug',
        visibleRows(brokenFixture) === 8,
        'visible=' + visibleRows(brokenFixture)
    );

    /* --- report ---------------------------------------------------------- */
    const failed = results.filter((result) => !result.ok);
    results.forEach((result) => {
        console.log((result.ok ? 'PASS  ' : 'FAIL  ') + result.name + (result.detail ? '   [' + result.detail + ']' : ''));
    });
    console.log('');
    console.log(results.length - failed.length + '/' + results.length + ' checks passed');
    if (failed.length) {
        process.exitCode = 1;
    }
}

main();
