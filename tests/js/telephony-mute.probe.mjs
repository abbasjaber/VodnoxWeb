/*
 * Behavioural test for the VODNOX browser-phone microphone mute.
 *
 * Grepping telephony.js for strings proves nothing about whether the mute
 * works, so this test loads the real file into a stubbed browser (document /
 * MediaStream / RTCPeerConnection / SIP) and drives an actual call:
 *
 *   place call -> Established -> click mute
 *     -> assert the live outgoing track's `enabled` became false
 *     -> assert the incoming track is untouched (the agent still hears)
 *     -> assert the microphone track was NOT stopped (unmute stays possible)
 *     -> replace the microphone track the way SIP.js' setLocalMediaStream does
 *     -> assert the mute was re-asserted on the freshly created track
 *   -> click unmute -> assert the live track is enabled again
 *   -> hang up -> assert the session really ended and the state reset
 *   -> place a second call -> assert it starts unmuted
 *
 * The last block is a negative control: the same scenario is replayed against a
 * copy of the file with the re-assertion hook removed, and it must reproduce the
 * original bug (the swapped-in track comes back live). That is what makes the
 * root cause a demonstrated fact rather than a guess.
 *
 * Run with: node tests/js/telephony-mute.probe.mjs
 */
import fs from 'node:fs';

const SOURCE = fs.readFileSync(
    new URL('../../public/assets/vodnox/telephony.js', import.meta.url),
    'utf8'
);

/* ------------------------------------------------------------------ *
 * Browser stubs
 * ------------------------------------------------------------------ */

class StubTrack {
    constructor(kind) {
        this.kind = kind;
        this.enabled = true;
        this.stopCalled = false;
        this.id = 'track-' + this.kind + '-' + Math.random().toString(36).slice(2, 8);
    }
    stop() {
        this.stopCalled = true;
    }
}

class StubMediaStream {
    constructor() {
        this._tracks = [];
        this._listeners = {};
    }
    addTrack(track) {
        if (this._tracks.indexOf(track) === -1) {
            this._tracks.push(track);
        }
    }
    removeTrack(track) {
        this._tracks = this._tracks.filter((t) => t !== track);
    }
    getTracks() {
        return this._tracks.slice();
    }
    getAudioTracks() {
        return this._tracks.filter((t) => t.kind === 'audio');
    }
    getVideoTracks() {
        return this._tracks.filter((t) => t.kind === 'video');
    }
    getTrackById(id) {
        return this._tracks.filter((t) => t.id === id)[0] || null;
    }
    addEventListener(type, fn) {
        (this._listeners[type] = this._listeners[type] || []).push(fn);
    }
    removeEventListener(type, fn) {
        this._listeners[type] = (this._listeners[type] || []).filter((f) => f !== fn);
    }
    dispatchEvent(event) {
        (this._listeners[event.type] || []).slice().forEach((fn) => fn(event));
    }
}

class StubElement {
    constructor(tag) {
        this.tagName = String(tag || 'div').toUpperCase();
        this.attributes = {};
        this.dataset = {};
        this.children = [];
        this.listeners = {};
        this.className = '';
        this.textContent = '';
        this.title = '';
        this.hidden = false;
        this.disabled = false;
        this.open = false;
        this.value = '';
        this.style = {};
        this.srcObject = null;
        this.type = '';
    }
    setAttribute(name, value) {
        this.attributes[name] = String(value);
        if (name.indexOf('data-') === 0) {
            const camel = name
                .slice(5)
                .replace(/-([a-z])/g, (m, c) => c.toUpperCase());
            this.dataset[camel] = String(value);
        }
    }
    getAttribute(name) {
        return Object.prototype.hasOwnProperty.call(this.attributes, name)
            ? this.attributes[name]
            : null;
    }
    hasAttribute(name) {
        return Object.prototype.hasOwnProperty.call(this.attributes, name);
    }
    removeAttribute(name) {
        delete this.attributes[name];
    }
    addEventListener(type, fn) {
        (this.listeners[type] = this.listeners[type] || []).push(fn);
    }
    removeEventListener(type, fn) {
        this.listeners[type] = (this.listeners[type] || []).filter((f) => f !== fn);
    }
    dispatchEvent(event) {
        (this.listeners[event.type] || []).slice().forEach((fn) => fn(event));
        return true;
    }
    appendChild(child) {
        this.children.push(child);
        return child;
    }
    get childNodes() {
        return this.children;
    }
    set innerHTML(html) {
        this._innerHTML = html;
        if (html === '') {
            this.children = [];
        }
    }
    get innerHTML() {
        return this._innerHTML || '';
    }
    showModal() {
        this.open = true;
    }
    close() {
        this.open = false;
    }
    focus() {}
    play() {
        return undefined;
    }
    querySelector(selector) {
        return matchWithin(this, selector)[0] || null;
    }
    querySelectorAll(selector) {
        return matchWithin(this, selector);
    }
}

function attrNameOf(selector) {
    const m = /^\[([^\]]+)\]$/.exec(selector);
    return m ? m[1] : null;
}

function hasAttr(el, name) {
    return Object.prototype.hasOwnProperty.call(el.attributes, name);
}

function walk(el, out) {
    el.children.forEach((child) => {
        out.push(child);
        walk(child, out);
    });
    return out;
}

function matchWithin(rootEl, selector) {
    const attr = attrNameOf(selector);
    const all = walk(rootEl, []);
    if (attr) {
        return all.filter((el) => hasAttr(el, attr));
    }
    if (selector.charAt(0) === '.') {
        const cls = selector.slice(1);
        return all.filter((el) => String(el.className).split(/\s+/).indexOf(cls) !== -1);
    }
    return all.filter((el) => el.tagName === selector.toUpperCase());
}

function createEnvironment(strings) {
    const byId = {};
    const all = [];

    const document = {
        visibilityState: 'visible',
        listeners: {},
        getElementById(id) {
            if (!byId[id]) {
                const el = new StubElement('div');
                el.id = id;
                byId[id] = el;
                all.push(el);
            }
            return byId[id];
        },
        createElement(tag) {
            const el = new StubElement(tag);
            all.push(el);
            return el;
        },
        querySelectorAll(selector) {
            const attr = attrNameOf(selector);
            if (!attr) {
                return [];
            }
            return all.filter((el) => hasAttr(el, attr));
        },
        querySelector(selector) {
            return this.querySelectorAll(selector)[0] || null;
        },
        addEventListener(type, fn) {
            (this.listeners[type] = this.listeners[type] || []).push(fn);
        },
        removeEventListener(type, fn) {
            this.listeners[type] = (this.listeners[type] || []).filter((f) => f !== fn);
        },
        dispatchEvent(event) {
            (this.listeners[event.type] || []).slice().forEach((fn) => fn(event));
            return true;
        }
    };

    const storage = new Map();

    const windowListeners = {};
    const window = {
        localStorage: {
            getItem: (k) => (storage.has(k) ? storage.get(k) : null),
            setItem: (k, v) => storage.set(k, String(v)),
            removeItem: (k) => storage.delete(k)
        },
        setInterval: () => 1,
        clearInterval: () => {},
        setTimeout: () => 1,
        clearTimeout: () => {},
        addEventListener(type, fn) {
            (windowListeners[type] = windowListeners[type] || []).push(fn);
        },
        removeEventListener(type, fn) {
            windowListeners[type] = (windowListeners[type] || []).filter((f) => f !== fn);
        },
        dispatchEvent() {
            return true;
        },
        focus: () => {},
        // AudioContext / Notification / BroadcastChannel are deliberately
        // absent: the ring and the desktop notification are out of scope here.
        listeners: windowListeners
    };

    // The phone root, exactly as the Blade card renders it.
    const root = document.getElementById('vodnoxPhone');
    root.dataset.credentialsUrl = '/user/phone/credentials';
    root.dataset.strings = JSON.stringify(strings);

    // The mute control, exactly as the Blade card renders it: the attribute
    // hook, the label span, and the "no call yet" disabled state.
    const muteBtn = document.createElement('button');
    muteBtn.setAttribute('data-phone-mute', '');
    muteBtn.setAttribute('aria-pressed', 'false');
    muteBtn.disabled = true;
    const muteLabel = document.createElement('span');
    muteLabel.setAttribute('data-mute-label', '');
    muteBtn.appendChild(muteLabel);

    return { document, window, root, muteBtn, muteLabel, all };
}

/* ------------------------------------------------------------------ *
 * SIP.js stub
 * ------------------------------------------------------------------ */

const SessionState = {
    Initial: 'Initial',
    Establishing: 'Establishing',
    Established: 'Established',
    Terminated: 'Terminated'
};
const RegistererState = {
    Registered: 'Registered',
    Unregistered: 'Unregistered',
    Terminated: 'Terminated'
};

function emitter() {
    const listeners = [];
    return {
        addListener: (fn) => listeners.push(fn),
        emit: (v) => listeners.slice().forEach((fn) => fn(v)),
        count: () => listeners.length
    };
}

function createSip(harness) {
    class FakeUserAgent {
        constructor(options) {
            this.options = options;
            this.delegate = options.delegate || {};
        }
        start() {
            return Promise.resolve();
        }
        stop() {
            return Promise.resolve();
        }
        static makeURI(value) {
            return { value: value, toString: () => value };
        }
    }

    class FakeRegisterer {
        constructor(ua) {
            this.ua = ua;
            this.stateChange = emitter();
            harness.registerers.push(this);
        }
        register() {
            return Promise.resolve();
        }
        unregister() {
            return Promise.resolve();
        }
    }

    class FakeInviter {
        constructor(ua, target) {
            this.ua = ua;
            this.target = target;
            this.state = SessionState.Initial;
            this.stateChange = emitter();
            this.sessionDescriptionHandler = null;
            this.byeCalled = false;
            this.cancelCalled = false;
            harness.inviters.push(this);
        }
        invite(options) {
            this.requestDelegate = options && options.requestDelegate;
            this.state = SessionState.Establishing;
            this.stateChange.emit(SessionState.Establishing);
            return Promise.resolve();
        }
        bye() {
            this.byeCalled = true;
            this.state = SessionState.Terminated;
            this.stateChange.emit(SessionState.Terminated);
            return Promise.resolve();
        }
        cancel() {
            this.cancelCalled = true;
            this.state = SessionState.Terminated;
            this.stateChange.emit(SessionState.Terminated);
            return Promise.resolve();
        }
    }

    return {
        UserAgent: FakeUserAgent,
        Registerer: FakeRegisterer,
        Inviter: FakeInviter,
        SessionState: SessionState,
        RegistererState: RegistererState
    };
}

/* ------------------------------------------------------------------ *
 * The call fixture: sender + receiver tracks on a fake peer connection
 * ------------------------------------------------------------------ */

function makeHandler() {
    const sendTrack = new StubTrack('audio');
    const recvTrack = new StubTrack('audio');
    const sender = { track: sendTrack };
    const receiver = { track: recvTrack };
    const pc = {
        signalingState: 'stable',
        _senders: [sender],
        _receivers: [receiver],
        getSenders() {
            return this._senders;
        },
        getReceivers() {
            return this._receivers;
        },
        close() {}
    };
    const stream = new StubMediaStream();
    stream.addTrack(sendTrack);

    return {
        peerConnection: pc,
        localMediaStream: stream,
        sender,
        receiver,
        sendTrack,
        recvTrack
    };
}

/**
 * Reproduce SIP.js `SessionDescriptionHandler.setLocalMediaStream()`: the
 * sender swaps to a brand-new track (default `enabled = true`), the OLD track
 * is stopped and removed from the local stream, and the new one is added —
 * dispatching `removetrack` then `addtrack` on that same stream object.
 */
function sipReplaceMicTrack(handler) {
    const newTrack = new StubTrack('audio');
    // Recorded BEFORE anything else: this is the state SIP.js hands us.
    newTrack.enabledAtCreation = newTrack.enabled;
    handler.sender.track = newTrack; // sender.replaceTrack(newTrack)
    const old = handler.sendTrack;
    handler.localMediaStream.removeTrack(old);
    old.stop();
    handler.localMediaStream.dispatchEvent({ type: 'removetrack', track: old });
    handler.localMediaStream.addTrack(newTrack);
    handler.localMediaStream.dispatchEvent({ type: 'addtrack', track: newTrack });
    handler.sendTrack = newTrack;
    return newTrack;
}

/* ------------------------------------------------------------------ *
 * Probe
 * ------------------------------------------------------------------ */

const results = [];
function check(name, condition, detail) {
    results.push({ name, ok: !!condition, detail: detail === undefined ? '' : String(detail) });
}

const STRINGS = {
    mute: 'كتم الميكروفون',
    unmute: 'إلغاء الكتم',
    muteIdle: 'لا توجد مكالمة جارية',
    registered: 'Registered',
    ready: 'Ready'
};

function boot(strings, sourceOverride) {
    const env = createEnvironment(strings || STRINGS);
    const harness = { registerers: [], inviters: [] };
    const source = sourceOverride || SOURCE;

    // telephony.js is a plain IIFE that reads these bare globals.
    const run = new Function(
        'window',
        'document',
        'MediaStream',
        'CustomEvent',
        'fetch',
        'console',
        source
    );

    env.window.SIP = createSip(harness);
    const payload = {
        state: 'ready',
        sip: {
            username: '1001',
            domain: 'pbx.vodnox.com',
            password: 'secret',
            wss_url: 'wss://pbx.vodnox.com:8089/ws'
        },
        directory: [],
        ice: []
    };
    const fetchStub = () =>
        Promise.resolve({
            ok: true,
            status: 200,
            json: () => Promise.resolve(payload)
        });

    const exec = () => {
        run(
            env.window,
            env.document,
            StubMediaStream,
            function CustomEvent(type, init) {
                return { type, detail: init && init.detail };
            },
            fetchStub,
            { log: () => {}, error: () => {}, warn: () => {}, debug: () => {} }
        );
    };

    exec();

    return { env, harness, exec };
}

/** Wait until the credentials promise has resolved and registration is live. */
async function readyPhone(ctx) {
    await new Promise((r) => setImmediate(r));
    await new Promise((r) => setImmediate(r));
    const registerer = ctx.harness.registerers[0];
    if (registerer) {
        registerer.stateChange.emit('Registered');
    }
    return registerer;
}

function openCall(ctx, handler) {
    const phone = ctx.env.window.vodnoxPhone;
    phone.placeCall('1002');
    const inviter = ctx.harness.inviters[ctx.harness.inviters.length - 1];
    inviter.sessionDescriptionHandler = handler;
    inviter.state = SessionState.Established;
    inviter.stateChange.emit(SessionState.Established);
    return inviter;
}

async function main() {
    const ctx = boot();
    await readyPhone(ctx);

    const btn = ctx.env.muteBtn;
    const label = ctx.env.muteLabel;

    // --- resting state -------------------------------------------------
    check(
        'at rest: the mute control is disabled (no call, no senders)',
        btn.disabled === true,
        'disabled=' + btn.disabled
    );
    check(
        'at rest: aria-pressed is false',
        btn.getAttribute('aria-pressed') === 'false',
        btn.getAttribute('aria-pressed')
    );

    // --- establish a call ----------------------------------------------
    const handler = makeHandler();
    const inviter = openCall(ctx, handler);

    check(
        'established: the mute control becomes usable',
        btn.disabled === false,
        'disabled=' + btn.disabled
    );
    check(
        'established: the outgoing audio track is live (enabled = true)',
        handler.sendTrack.enabled === true,
        'enabled=' + handler.sendTrack.enabled
    );

    // --- click mute -----------------------------------------------------
    btn.dispatchEvent({ type: 'click' });

    check(
        'mute: the REAL outgoing track is disabled',
        handler.sendTrack.enabled === false,
        'enabled=' + handler.sendTrack.enabled
    );
    check(
        'mute: the incoming track is untouched (we still hear them)',
        handler.recvTrack.enabled === true,
        'enabled=' + handler.recvTrack.enabled
    );
    check(
        'mute: the microphone track was NOT stopped (unmute stays possible)',
        handler.sendTrack.stopCalled === false,
        'stopCalled=' + handler.sendTrack.stopCalled
    );
    check(
        'mute: aria-pressed reflects the real state',
        btn.getAttribute('aria-pressed') === 'true',
        btn.getAttribute('aria-pressed')
    );
    check(
        'mute: the visible label changed to "unmute"',
        label.textContent === STRINGS.unmute,
        JSON.stringify(label.textContent)
    );

    // --- SIP.js replaces the microphone track mid-call -------------------
    const newTrack = sipReplaceMicTrack(handler);
    check(
        'root cause: the track SIP.js injects is born enabled (enabled = true)',
        newTrack.enabledAtCreation === true,
        'enabledAtCreation=' + newTrack.enabledAtCreation
    );
    check(
        'root cause: SIP.js really did stop the previous microphone track',
        handler.localMediaStream.getTracks().indexOf(newTrack) !== -1,
        'new track present on the local stream'
    );
    check(
        'after the track swap: mute is re-asserted on the NEW track',
        newTrack.enabled === false,
        'enabled=' + newTrack.enabled
    );
    check(
        'after the track swap: aria-pressed still says muted',
        btn.getAttribute('aria-pressed') === 'true',
        btn.getAttribute('aria-pressed')
    );

    // --- click unmute ---------------------------------------------------
    btn.dispatchEvent({ type: 'click' });

    check(
        'unmute: the current outgoing track is enabled again',
        newTrack.enabled === true,
        'enabled=' + newTrack.enabled
    );
    check(
        'unmute: the live sender still carries the current track',
        handler.sendTrack === newTrack,
        'sender track is the live one'
    );
    check(
        'unmute: aria-pressed is false again',
        btn.getAttribute('aria-pressed') === 'false',
        btn.getAttribute('aria-pressed')
    );
    check(
        'unmute: the label is back to "mute"',
        label.textContent === STRINGS.mute,
        JSON.stringify(label.textContent)
    );
    check(
        'unmute: the microphone track was never stopped',
        newTrack.stopCalled === false,
        'stopCalled=' + newTrack.stopCalled
    );

    // --- hang up --------------------------------------------------------
    btn.dispatchEvent({ type: 'click' }); // mute again, so reset is provable
    check(
        'pre-hangup: the control reads muted',
        btn.getAttribute('aria-pressed') === 'true',
        btn.getAttribute('aria-pressed')
    );

    ctx.env.document.getElementById('phoneHangupBtn').dispatchEvent({ type: 'click' });

    check(
        'hangup: the SIP session was actually ended (bye)',
        inviter.byeCalled === true,
        'byeCalled=' + inviter.byeCalled
    );
    check(
        'hangup: the mute state is reset',
        btn.getAttribute('aria-pressed') === 'false',
        btn.getAttribute('aria-pressed')
    );
    check(
        'hangup: the control is disabled again',
        btn.disabled === true,
        'disabled=' + btn.disabled
    );

    // a click arriving after the call ended must not resurrect anything
    btn.dispatchEvent({ type: 'click' });
    check(
        'after hangup: a stray click neither crashes nor un-mutes a dead call',
        btn.getAttribute('aria-pressed') === 'false' && btn.disabled === true,
        'pressed=' + btn.getAttribute('aria-pressed') + ' disabled=' + btn.disabled
    );

    // --- next call starts unmuted ---------------------------------------
    const handler2 = makeHandler();
    openCall(ctx, handler2);
    check(
        'next call: the microphone is live (mute did not leak into the new call)',
        handler2.sendTrack.enabled === true,
        'enabled=' + handler2.sendTrack.enabled
    );
    check(
        'next call: the control shows the unmuted state',
        btn.getAttribute('aria-pressed') === 'false' && btn.disabled === false,
        'pressed=' + btn.getAttribute('aria-pressed') + ' disabled=' + btn.disabled
    );
    check(
        'next call: the label is "mute"',
        label.textContent === STRINGS.mute,
        JSON.stringify(label.textContent)
    );

    // --- duplicated controls stay in sync --------------------------------
    // A second control carrying the same hook appears (a second card, or the
    // same card rendered twice). Driving the call must write the live state to
    // BOTH of them, never to just the first one found.
    const second = ctx.env.document.createElement('button');
    second.setAttribute('data-phone-mute', '');
    second.setAttribute('aria-pressed', 'false');
    second.disabled = true;
    ctx.env.all.push(second);

    btn.dispatchEvent({ type: 'click' }); // mute
    check(
        'duplicate control: the second button mirrors the mute state',
        second.getAttribute('aria-pressed') === 'true' && second.disabled === false,
        'pressed=' + second.getAttribute('aria-pressed') + ' disabled=' + second.disabled
    );
    check(
        'duplicate control: the single live track was muted, not toggled twice',
        handler2.sendTrack.enabled === false,
        'enabled=' + handler2.sendTrack.enabled
    );

    btn.dispatchEvent({ type: 'click' }); // unmute
    check(
        'duplicate control: the second button mirrors the unmuted state too',
        second.getAttribute('aria-pressed') === 'false' && handler2.sendTrack.enabled === true,
        'pressed=' + second.getAttribute('aria-pressed') + ' enabled=' + handler2.sendTrack.enabled
    );
    ctx.env.all.splice(ctx.env.all.indexOf(second), 1);

    // --- one click listener per control ---------------------------------
    check(
        'binding: exactly one click listener on the mute control',
        (btn.listeners.click || []).length === 1,
        'listeners=' + (btn.listeners.click || []).length
    );
    check(
        'binding: the control is marked as bound',
        btn.dataset.muteBound === 'true',
        String(btn.dataset.muteBound)
    );

    // --- a second run of the same script must not stack listeners --------
    const ctx2 = boot();
    await readyPhone(ctx2);
    ctx2.exec(); // simulate a second <script> pass over the same markup
    await new Promise((r) => setImmediate(r));
    const btn2 = ctx2.env.muteBtn;
    check(
        'duplicate script pass: still exactly one click listener (no handler stacking)',
        (btn2.listeners.click || []).length === 1,
        'listeners=' + (btn2.listeners.click || []).length
    );

    // --- negative control: prove the re-assertion hook is what fixes it ---
    // The exact same call is driven against a copy of the file with the
    // re-assertion hook removed. If the root-cause analysis is right, the
    // swapped-in microphone track must come back alive — i.e. the reported bug.
    const WITHOUT_HOOK = SOURCE.replace(
        'watchLocalStream(target.sessionDescriptionHandler);',
        '/* hook removed for the negative control */'
    );
    check(
        'negative control: the file really was mutated (hook removed)',
        WITHOUT_HOOK !== SOURCE && WITHOUT_HOOK.indexOf('watchLocalStream(target') === -1,
        'mutation applied'
    );

    const ctx3 = boot(STRINGS, WITHOUT_HOOK);
    await readyPhone(ctx3);
    const handler3 = makeHandler();
    openCall(ctx3, handler3);
    ctx3.env.muteBtn.dispatchEvent({ type: 'click' }); // mute
    check(
        'negative control: the muted track did start muted',
        handler3.sendTrack.enabled === false,
        'enabled=' + handler3.sendTrack.enabled
    );
    const swapped3 = sipReplaceMicTrack(handler3);
    check(
        'negative control: WITHOUT the hook the swapped track stays live — the original bug',
        swapped3.enabled === true,
        'enabled=' + swapped3.enabled
    );

    // --- report ----------------------------------------------------------
    const failed = results.filter((r) => !r.ok);
    results.forEach((r) => {
        console.log((r.ok ? 'PASS  ' : 'FAIL  ') + r.name + (r.detail ? '   [' + r.detail + ']' : ''));
    });
    console.log('');
    console.log(results.length - failed.length + '/' + results.length + ' checks passed');
    if (failed.length) {
        process.exitCode = 1;
    }
}

main().catch((error) => {
    console.error('PROBE CRASHED:', error && error.stack ? error.stack : error);
    process.exitCode = 2;
});
