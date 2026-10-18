/*
 * VODNOX browser phone (SIP.js / WebRTC).
 *
 * Real registration and real calls between employees of the same organization.
 * Everything the UI shows comes from actual transport/session events — there is
 * deliberately no timer-based faking of answers or call results.
 *
 * Multi-device policy
 * -------------------
 * One employee = one active phone. Tabs elect a leader through localStorage +
 * BroadcastChannel; the most recently activated tab wins and holds the single
 * SIP registration. Other tabs show a notice and a "take over" action instead
 * of creating a competing registration or a duplicated call.
 *
 * No credentials are persisted: they are fetched per page load from an
 * authenticated, no-store endpoint and kept in memory only.
 */
(function () {
    'use strict';

    var root = document.getElementById('vodnoxPhone');
    if (!root) {
        return;
    }

    var SIP = window.SIP;
    var STATE = {
        unprovisioned: 'unprovisioned',
        provisioning: 'provisioning',
        connecting: 'connecting',
        registered: 'registered',
        disconnected: 'disconnected',
        failed: 'failed',
        disabled: 'disabled'
    };

    var config = {
        credentialsUrl: root.dataset.credentialsUrl,
        registrationUrl: root.dataset.registrationUrl,
        provisionUrl: root.dataset.provisionUrl,
        csrf: root.dataset.csrf || ''
    };
    var strings = JSON.parse(root.dataset.strings || '{}');
    var tabId = Math.random().toString(36).slice(2);

    var ua = null;
    var registerer = null;
    var registered = false;
    var session = null;
    var sessionKind = null; // 'outgoing' | 'incoming'
    var incomingInvitation = null;
    var pendingCall = false;
    var terminalReason = null;
    var registrationState = STATE.unprovisioned;
    var accountState = 'ready';
    var directory = [];
    var credentials = null;
    var retryDelay = 1000;
    var retryTimer = null;
    var answeredAt = null;
    var durationTimer = null;
    var channel = 'BroadcastChannel' in window ? new BroadcastChannel('vodnox-phone') : null;
    var leaderKey = 'vodnox.phone.leader';
    var isLeader = false;
    var audioContext = null;
    var tone = { timer: null, nodes: [] };
    var incomingNotification = null;
    var notificationAsked = false;
    var answering = false;
    var callerLabel = '';
    // Microphone mute is real state, never read back from the DOM: the button
    // can be duplicated and the DOM can be re-rendered, the call state cannot.
    var muted = false;
    // The MediaStream whose add/remove-track events we already watch, so the
    // mute state is re-asserted when SIP.js swaps the microphone track.
    var watchedStream = null;

    var ui = {
        dock: document.getElementById('vodnoxPhoneDock'),
        pill: document.getElementById('phoneStatusPill'),
        activate: document.getElementById('phoneActivate'),
        dial: document.getElementById('phoneDialInput'),
        call: document.getElementById('phoneCallBtn'),
        directory: document.getElementById('phoneDirectoryList'),
        controls: document.getElementById('phoneCallControls'),
        incoming: document.getElementById('phoneIncoming'),
        incomingLabel: document.getElementById('phoneIncomingLabel'),
        incomingAvatar: document.getElementById('phoneIncomingAvatar'),
        incomingExtension: document.getElementById('phoneIncomingExtension'),
        incomingSound: document.getElementById('phoneIncomingSound'),
        incomingSoundBtn: document.getElementById('phoneIncomingSoundBtn'),
        timer: document.getElementById('phoneTimer'),
        hangup: document.getElementById('phoneHangupBtn'),
        answer: document.getElementById('phoneAnswerBtn'),
        reject: document.getElementById('phoneRejectBtn'),
        hold: document.getElementById('phoneHoldBtn'),
        transfer: document.getElementById('phoneTransferBtn'),
        notice: document.getElementById('phoneNotice'),
        message: document.getElementById('phoneMessage'),
        audio: document.getElementById('phoneRemoteAudio')
    };

    // A missing SIP.js is a deploy problem, not a registration problem. Say so
    // instead of leaving the employee with a call button that does nothing.
    if (!SIP) {
        console.error('VODNOX telephony: SIP.js is not loaded (expected assets/vodnox/vendor/sip.min.js).');
        if (ui.pill) { ui.pill.dataset.state = 'failed'; }
        if (ui.message) { ui.message.textContent = strings.sipMissing || ''; }
        if (ui.activate) { ui.activate.disabled = true; }
        if (ui.call) { ui.call.disabled = true; }
        return;
    }

    // Sidebar / dialer mirrors of the phone state. They are driven by the same
    // events as the card, so "registered" is only ever shown after the
    // registrar actually accepted the registration.
    var deviceUi = {
        box: document.getElementById('deviceState'),
        icon: document.getElementById('deviceIcon'),
        text: document.getElementById('deviceStateText'),
        hint: document.getElementById('deviceStateHint')
    };
    var statusEls = document.querySelectorAll('[data-phone-state]');
    var TONES = ['draft', 'active', 'expired', 'pending'];
    var DEVICE_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m7 3 3 5-3 3a15 15 0 0 0 6 6l3-3 5 3-1 4C10 22 2 14 3 4l4-1Z"/></svg>';

    function deviceName(state) {
        if (state === STATE.registered) {
            return 'registered';
        }
        if (state === STATE.connecting || state === STATE.provisioning) {
            return 'connecting';
        }
        if (state === STATE.failed) {
            return 'failed';
        }

        return 'unconnected';
    }

    function reflectState(state) {
        var device = deviceName(state);

        if (deviceUi.box) {
            deviceUi.box.dataset.state = device;
            var label = deviceUi.box.getAttribute('data-' + device);
            if (deviceUi.text && label) {
                deviceUi.text.textContent = label;
            }
            if (deviceUi.hint) {
                deviceUi.hint.textContent = device === 'unconnected'
                    ? (deviceUi.box.getAttribute('data-unverified') || '')
                    : '';
            }
            if (deviceUi.icon && !deviceUi.icon.childNodes.length) {
                deviceUi.icon.innerHTML = DEVICE_ICON;
            }
        }

        Array.prototype.forEach.call(statusEls, function (el) {
            el.dataset.state = device;
            var text = el.getAttribute('data-label-' + device);
            if (text) {
                el.textContent = text;
            }
            var tone = el.getAttribute('data-tone-' + device);
            if (tone) {
                var kept = (el.className || '').split(/\s+/).filter(function (name) {
                    return name && TONES.indexOf(name) === -1;
                });
                kept.push(tone);
                el.className = kept.join(' ');
            }
        });
    }

    // ---------------------------------------------------------------------
    // Leader election (one registration per employee, across tabs)
    // ---------------------------------------------------------------------

    function readLeader() {
        try {
            return JSON.parse(window.localStorage.getItem(leaderKey) || 'null');
        } catch (e) {
            return null;
        }
    }

    function claimLeadership() {
        try {
            window.localStorage.setItem(leaderKey, JSON.stringify({ id: tabId, at: Date.now() }));
        } catch (e) {
            /* storage unavailable: single-tab fallback */
        }
        isLeader = true;
        if (channel) {
            channel.postMessage({ type: 'leader', id: tabId });
        }
    }

    function leaderIsStale() {
        var leader = readLeader();
        if (!leader) {
            return true;
        }
        return leader.id !== tabId && Date.now() - leader.at > 15000;
    }

    function releaseLeadership() {
        var leader = readLeader();
        if (leader && leader.id === tabId) {
            try {
                window.localStorage.removeItem(leaderKey);
            } catch (e) {
                /* ignore */
            }
        }
        isLeader = false;
    }

    // ---------------------------------------------------------------------
    // UI helpers
    // ---------------------------------------------------------------------

    function setMessage(text, kind) {
        if (ui.message) {
            ui.message.textContent = text || '';
            ui.message.hidden = !text;
            ui.message.className = 'phone-message' + (kind ? ' ' + kind : '');
        }
        window.dispatchEvent(new CustomEvent('vodnox:phone-message', {
            detail: { text: text || '', kind: kind || null }
        }));
    }

    function setStatus(state, label) {
        registrationState = state;
        if (ui.pill) {
            ui.pill.textContent = label || state;
            ui.pill.dataset.state = state;
        }
        if (ui.activate) {
            ui.activate.disabled = state === STATE.connecting;
        }
        reflectState(state);
    }

    function setNotice(text) {
        if (!ui.notice) {
            return;
        }
        ui.notice.textContent = text || '';
        ui.notice.hidden = !text;
    }

    function showControls(show) {
        if (ui.controls) {
            ui.controls.hidden = !show;
        }
    }

    function startTimer() {
        answeredAt = Date.now();
        stopTimer();
        durationTimer = window.setInterval(function () {
            if (ui.timer) {
                ui.timer.textContent = formatClock(Math.floor((Date.now() - answeredAt) / 1000));
            }
        }, 1000);
        if (ui.timer) {
            ui.timer.textContent = '00:00';
        }
    }

    function stopTimer() {
        if (durationTimer) {
            window.clearInterval(durationTimer);
            durationTimer = null;
        }
    }

    function formatClock(seconds) {
        var m = Math.floor(seconds / 60);
        var s = seconds % 60;
        return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
    }

    // ---------------------------------------------------------------------
    // Ring tones (synthesised in the browser) and desktop notifications
    //
    // Stated limits:
    //   * The tone is generated with the Web Audio API — nothing is downloaded
    //     and no external audio service is contacted. No audio file ships with
    //     the application, so there is nothing for the deploy to exclude.
    //   * The incoming ring is a short two-note motif repeated on a slow
    //     cadence: recognisably VODNOX, short enough not to be abrasive and
    //     quiet enough to sit under a conversation.
    //   * Browsers block audio until the page has been interacted with, so the
    //     audio context is primed on the "activate" button and on the first
    //     interaction anywhere. If the browser still refuses, the incoming
    //     window says so and offers an explicit "enable sound" action: the ring
    //     stays cosmetic, answering and the conversation are never blocked.
    //   * Every oscillator and timer the ring creates is released on answer,
    //     reject, caller cancel, hang-up and transport loss, plus the moment the
    //     call is established — no cadence outlives the ringing.
    //   * A desktop Notification is opt-in (asked at most once per page load,
    //     from a click) and is only raised while the tab is in the background.
    //     The in-page incoming window is the interface, and remains so when the
    //     notification is denied or unsupported.
    //   * Notifications cannot reach a closed page: there is no service worker
    //     or push subscription here, so calls are only received while the phone
    //     page is open in a tab. Nothing here delivers calls in the background.
    // ---------------------------------------------------------------------

    // A two-note "ding-dong" (E5 then C5) — a chime, not a beep. Each note is a
    // sine plus its octave at a third of the level, which is what gives it a
    // bell-like edge without any sample.
    var RING_MOTIF = [
        { freq: 659.25, at: 0, dur: 0.34 },
        { freq: 523.25, at: 0.36, dur: 0.48 }
    ];
    var RING_CADENCE = 2600;
    var RING_LEVEL = 0.11;
    // The outgoing ringback stays deliberately plain: it plays to the person
    // who is already holding the phone to their ear.
    var RINGBACK_MOTIF = [{ freq: 425, at: 0, dur: 1 }];
    var RINGBACK_CADENCE = 4000;
    var RINGBACK_LEVEL = 0.05;

    var AVATAR_PHONE_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m7 3 3 5-3 3a15 15 0 0 0 6 6l3-3 5 3-1 4C10 22 2 14 3 4l4-1Z"/></svg>';

    function ensureAudioContext() {
        if (audioContext) {
            return audioContext;
        }
        var Ctor = window.AudioContext || window.webkitAudioContext;
        if (!Ctor) {
            return null;
        }
        try {
            audioContext = new Ctor();
        } catch (e) {
            audioContext = null;
        }
        return audioContext;
    }

    /**
     * Resume the audio context from a user gesture and, once the browser has
     * actually allowed it, retry a ring that is already waiting. `resume()` flips
     * the context to "running" asynchronously, so a ring started in the same tick
     * would still be refused — hence the promise chain.
     */
    function unlockAudio() {
        var ctx = ensureAudioContext();
        if (!ctx) {
            return;
        }
        if (ctx.state === 'suspended') {
            try {
                var resumed = ctx.resume();
                if (resumed && typeof resumed.then === 'function') {
                    resumed.then(retryRing).catch(function () { /* still blocked: the ring is skipped */ });
                    return;
                }
            } catch (e) {
                /* ignore */
            }
        }
        retryRing();
    }

    /**
     * Start the ring again for a call that is still waiting, once the browser
     * will let us play it. A no-op when nothing is ringing or the ring is
     * already sounding.
     */
    function retryRing() {
        if (!incomingInvitation || tone.timer) {
            return;
        }
        if (startTone('ringtone')) {
            hideSoundNotice();
        }
    }

    /**
     * Release every oscillator the ring created. Each note stops itself at the
     * end of its envelope, so this only matters for a note that is still
     * sounding — and for not leaking nodes from one ring into the next.
     */
    function releaseToneNodes() {
        tone.nodes.forEach(function (node) {
            try {
                if (typeof node.stop === 'function') {
                    node.stop();
                }
            } catch (e) {
                /* already stopped */
            }
            try {
                node.disconnect();
            } catch (e) {
                /* ignore */
            }
        });
        tone.nodes = [];
    }

    function stopTone() {
        if (tone.timer) {
            window.clearTimeout(tone.timer);
            tone.timer = null;
        }
        releaseToneNodes();
    }

    /**
     * Schedule one motif against the audio clock. The envelope is what turns a
     * bare oscillator into a chime: a 20ms attack and a decay back to silence.
     */
    function playMotif(ctx, motif, level) {
        var start = ctx.currentTime + 0.02;

        motif.forEach(function (note) {
            var from = start + note.at;
            var until = from + note.dur;
            var osc = ctx.createOscillator();
            var harmonic = ctx.createOscillator();
            var harmonicGain = ctx.createGain();
            var gain = ctx.createGain();

            osc.type = 'sine';
            osc.frequency.setValueAtTime(note.freq, from);
            harmonic.type = 'sine';
            harmonic.frequency.setValueAtTime(note.freq * 2, from);
            harmonicGain.gain.value = 0.28;

            gain.gain.setValueAtTime(0.0001, from);
            gain.gain.linearRampToValueAtTime(level, from + 0.02);
            gain.gain.linearRampToValueAtTime(level * 0.55, from + note.dur * 0.45);
            gain.gain.linearRampToValueAtTime(0.0001, until);

            osc.connect(gain);
            harmonic.connect(harmonicGain);
            harmonicGain.connect(gain);
            gain.connect(ctx.destination);

            try {
                osc.start(from);
                osc.stop(until + 0.05);
            } catch (e) {
                /* ignore */
            }
            try {
                harmonic.start(from);
                harmonic.stop(until + 0.05);
            } catch (e) {
                /* ignore */
            }

            tone.nodes.push(osc, harmonic, harmonicGain, gain);
        });
    }

    /**
     * Play a ring cadence until stopTone() is called.
     * kind: 'ringtone' for an incoming call, 'ringback' while an outgoing call
     * is still ringing.
     * Returns true only when the browser actually let the sound start.
     */
    function startTone(kind) {
        stopTone();

        var ctx = ensureAudioContext();
        if (!ctx) {
            return false;
        }
        if (ctx.state === 'suspended') {
            try {
                var resumed = ctx.resume();
                if (resumed && typeof resumed.catch === 'function') {
                    resumed.catch(function () { /* ignore */ });
                }
            } catch (e) {
                /* ignore */
            }
        }
        if (ctx.state !== 'running') {
            // Autoplay is blocked. Stay silent instead of throwing: the ring is
            // cosmetic, the call itself must still work.
            return false;
        }

        var incoming = kind === 'ringtone';
        var motif = incoming ? RING_MOTIF : RINGBACK_MOTIF;
        var cadence = incoming ? RING_CADENCE : RINGBACK_CADENCE;
        var level = incoming ? RING_LEVEL : RINGBACK_LEVEL;

        var cycle = function () {
            if (!audioContext) {
                tone.timer = null;
                return;
            }
            // The previous cycle's notes have already finished; releasing them
            // keeps exactly one cycle of nodes alive at a time.
            releaseToneNodes();
            playMotif(audioContext, motif, level);
            tone.timer = window.setTimeout(cycle, cadence);
        };
        cycle();

        return true;
    }

    function showSoundNotice() {
        if (ui.incomingSound) {
            ui.incomingSound.hidden = false;
        }
    }

    function hideSoundNotice() {
        if (ui.incomingSound) {
            ui.incomingSound.hidden = true;
        }
    }

    function requestNotificationPermission() {
        // At most one request per page load, and only while the browser is still
        // undecided. A denied or ignored prompt is never repeated on its own.
        if (notificationAsked) {
            return;
        }
        notificationAsked = true;
        if (!('Notification' in window) || window.Notification.permission !== 'default') {
            return;
        }
        try {
            var asked = window.Notification.requestPermission();
            if (asked && typeof asked.catch === 'function') {
                asked.catch(function () { /* denied: the in-page window is used */ });
            }
        } catch (e) {
            /* older callback-only implementations: ignore */
        }
    }

    /**
     * Complement the in-page window with a desktop notification, and only while
     * the tab is in the background — the window itself is the interface. It
     * cannot appear over other phone applications and cannot reach a closed page
     * (there is no service worker here). Returns true only when one was shown.
     */
    function notifyIncoming(caller) {
        if (!('Notification' in window) || window.Notification.permission !== 'granted') {
            return false;
        }
        if (document.visibilityState === 'visible') {
            return false;
        }
        try {
            incomingNotification = new window.Notification(strings.incomingTitle || 'Incoming call', {
                body: caller,
                tag: 'vodnox-incoming-call',
                requireInteraction: true
            });
            incomingNotification.onclick = function () {
                window.focus();
            };
            return true;
        } catch (e) {
            incomingNotification = null;
            return false;
        }
    }

    function closeIncomingNotification() {
        if (incomingNotification) {
            try { incomingNotification.close(); } catch (e) { /* ignore */ }
            incomingNotification = null;
        }
    }

    // ---------------------------------------------------------------------
    // Incoming window (<dialog>)
    //
    // A real modal in the top layer: centred, never clipped by an ancestor and
    // with the keyboard focus trapped inside. There is no window.open, no
    // backdrop dismissal and no Escape dismissal — a window that could be
    // closed while the call still rings would leave a call with no interface.
    // ---------------------------------------------------------------------

    function initialsFor(text) {
        var parts = String(text || '').trim().split(/[\s._-]+/).filter(Boolean);
        if (!parts.length) {
            return '';
        }
        if (parts.length === 1) {
            return parts[0].slice(0, 2).toUpperCase();
        }

        return (parts[0].charAt(0) + parts[1].charAt(0)).toUpperCase();
    }

    function directoryNameFor(extension) {
        var match = '';
        directory.forEach(function (entry) {
            if (String(entry.extension) === String(extension)) {
                match = entry.name;
            }
        });

        return match;
    }

    function openIncomingDialog() {
        if (!ui.incoming) {
            return;
        }
        // Re-opening a dialog that is already up would stack windows and steal
        // focus again on every retransmitted INVITE.
        if (typeof ui.incoming.showModal === 'function') {
            if (!ui.incoming.open) {
                ui.incoming.showModal();
            }
            return;
        }
        ui.incoming.setAttribute('open', '');
    }

    function closeIncomingDialog() {
        if (!ui.incoming) {
            return;
        }
        hideSoundNotice();
        if (typeof ui.incoming.close === 'function') {
            if (ui.incoming.open) {
                ui.incoming.close();
            }
            return;
        }
        ui.incoming.removeAttribute('open');
    }

    // ---------------------------------------------------------------------
    // Credentials bootstrap
    // ---------------------------------------------------------------------

    function loadCredentials() {
        return fetch(config.credentialsUrl, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            cache: 'no-store'
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.json();
        });
    }

    function applyCredentials(payload) {
        accountState = payload.state;
        directory = payload.directory || [];
        credentials = payload.sip || null;
        renderDirectory();

        if (payload.state === 'ready' && credentials) {
            setStatus(STATE.unprovisioned, strings.ready || 'Ready');
            setMessage('', null);
            // Connect straight away so the employee can receive calls without
            // hunting for a button. Registration needs no media permission; the
            // microphone is only requested when a call is placed or answered.
            if (isLeader && !ua) {
                activate();
            }
            return;
        }

        if (payload.state === 'disabled') {
            setStatus(STATE.disabled, strings.disabled || 'Disabled');
            setMessage(payload.reason || '', 'warn');
            return;
        }

        if (payload.state === 'failed') {
            setStatus(STATE.failed, strings.failed || 'Provisioning failed');
            setMessage(payload.reason || '', 'error');
            return;
        }

        if (payload.state === 'pending') {
            setStatus(STATE.provisioning, strings.provisioning || 'Provisioning…');
            setMessage(payload.reason || '', null);
            return;
        }

        setStatus(STATE.unprovisioned, strings.unprovisioned || 'No phone account');
        setMessage(payload.reason || '', 'warn');
    }

    function renderDirectory() {
        if (!ui.directory) {
            return;
        }
        ui.directory.innerHTML = '';
        if (!directory.length) {
            var empty = document.createElement('li');
            empty.className = 'phone-directory-empty';
            empty.textContent = strings.noColleagues || 'No colleagues available';
            ui.directory.appendChild(empty);
            return;
        }
        directory.forEach(function (entry) {
            var li = document.createElement('li');
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'phone-directory-item';
            button.dataset.extension = entry.extension;
            button.innerHTML = '<span class="ext"></span><span class="name"></span>';
            button.querySelector('.ext').textContent = entry.extension;
            button.querySelector('.name').textContent = entry.name;
            button.addEventListener('click', function () {
                if (ui.dial) {
                    ui.dial.value = entry.extension;
                }
                placeCall(entry.extension);
            });
            li.appendChild(button);
            ui.directory.appendChild(li);
        });
    }

    // ---------------------------------------------------------------------
    // Registration
    // ---------------------------------------------------------------------

    function buildUserAgent() {
        var uri = SIP.UserAgent.makeURI('sip:' + credentials.username + '@' + credentials.domain);
        if (!uri) {
            throw new Error('Invalid SIP identity');
        }

        var options = {
            uri: uri,
            transportOptions: { server: credentials.wss_url },
            authorizationUsername: credentials.username,
            authorizationPassword: credentials.password,
            displayName: strings.displayName || credentials.username,
            logBuiltinEnabled: false,
            delegate: {
                onInvite: handleInvite,
                onConnect: function () {
                    // The transport just came up (first connect or a reconnect):
                    // keep exactly one registration alive.
                    if (registerer && !registered) {
                        registerer.register().catch(onRegistrationError);
                    }
                },
                onDisconnect: function (error) {
                    registered = false;
                    if (error) {
                        setStatus(STATE.failed, strings.failed || 'Registration failed');
                        setMessage(strings.networkLost || 'Connection lost. Reconnecting…', 'warn');
                        scheduleReconnect();
                    } else {
                        setStatus(STATE.disconnected, strings.disconnected || 'Disconnected');
                    }
                }
            }
        };

        if (credentials.ice && credentials.ice.length) {
            options.sessionDescriptionHandlerFactoryOptions = {
                peerConnectionConfiguration: { iceServers: credentials.ice }
            };
        }

        return new SIP.UserAgent(options);
    }

    function activate() {
        if (!isLeader) {
            setNotice(strings.otherTab || 'The phone is active in another tab.');
            return;
        }
        if (!credentials) {
            setMessage(strings.notReady || 'The phone account is not ready yet.', 'warn');
            return;
        }
        if (ua) {
            return;
        }

        setStatus(STATE.connecting, strings.connecting || 'Connecting…');
        setMessage('', null);

        try {
            ua = buildUserAgent();
        } catch (error) {
            ua = null;
            setStatus(STATE.failed, strings.failed || 'Registration failed');
            setMessage(String(error && error.message ? error.message : error), 'error');
            return;
        }

        registerer = new SIP.Registerer(ua);
        registerer.stateChange.addListener(function (state) {
            if (state === SIP.RegistererState.Registered) {
                registered = true;
                setStatus(STATE.registered, strings.registered || 'Registered');
                setMessage('', null);
                retryDelay = 1000;
                reportRegistration(true);
            } else if (state === SIP.RegistererState.Unregistered) {
                registered = false;
                setStatus(STATE.disconnected, strings.disconnected || 'Disconnected');
            } else if (state === SIP.RegistererState.Terminated) {
                registered = false;
                setStatus(STATE.failed, strings.failed || 'Registration failed');
                scheduleReconnect();
            }
        });

        // Registration itself happens in the delegate's onConnect, so the
        // reconnect path uses the exact same single code path.
        return ua.start().catch(function (error) {
            setStatus(STATE.failed, strings.failed || 'Registration failed');
            setMessage(String(error && error.message ? error.message : error), 'error');
            scheduleReconnect();
        });
    }

    /**
     * Map a WebRTC/media error to a clear, translated message. SIP.js asks for
     * the microphone itself when a call is placed or answered, so a refusal or
     * a missing device surfaces here.
     */
    function mediaMessage(error) {
        var name = (error && (error.name || (error.cause && error.cause.name))) || '';
        if (name === 'NotFoundError' || name === 'DevicesNotFoundError' || name === 'OverconstrainedError') {
            return strings.noMic || 'No microphone was found on this device.';
        }
        if (name === 'NotAllowedError' || name === 'PermissionDeniedError' || name === 'SecurityError') {
            return strings.micDenied || 'Microphone access is required.';
        }

        return null;
    }

    function describeError(error) {
        return mediaMessage(error) || String(error && error.message ? error.message : error);
    }

    function onRegistrationError(error) {
        setStatus(STATE.failed, strings.failed || 'Registration failed');
        setMessage(describeError(error), 'error');
        scheduleReconnect();
    }

    function scheduleReconnect() {
        if (retryTimer) {
            return;
        }
        var delay = Math.min(retryDelay, 30000);
        retryDelay = Math.min(retryDelay * 2, 30000);
        retryTimer = window.setTimeout(function () {
            retryTimer = null;
            teardownUserAgent();
            if (isLeader && credentials) {
                activate();
            }
        }, delay);
    }

    function teardownUserAgent() {
        try {
            if (registerer) {
                registerer.unregister();
            }
        } catch (e) {
            /* ignore */
        }
        try {
            if (ua) {
                ua.stop();
            }
        } catch (e) {
            /* ignore */
        }
        registerer = null;
        ua = null;
        registered = false;
    }

    function reportRegistration(registered) {
        if (!config.registrationUrl) {
            return;
        }
        fetch(config.registrationUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': config.csrf
            },
            credentials: 'same-origin',
            body: JSON.stringify({ registered: !!registered })
        }).catch(function () {
            /* a UI hint only; never block the phone */
        });
    }

    // ---------------------------------------------------------------------
    // Calls
    // ---------------------------------------------------------------------

    function makeTargetUri(extension) {
        return SIP.UserAgent.makeURI('sip:' + extension + '@' + credentials.domain);
    }

    /**
     * Turn the final SIP status of an outgoing call into a clear message.
     * 486/600 busy · 603 declined · 408/480 no answer · 404 unknown extension.
     */
    function outgoingFailure(statusCode) {
        if (statusCode === 486 || statusCode === 600) {
            return strings.peerBusy || 'The other side is busy.';
        }
        if (statusCode === 603) {
            return strings.peerRejected || 'The call was rejected.';
        }
        if (statusCode === 408 || statusCode === 480) {
            return strings.noAnswer || 'No answer.';
        }
        if (statusCode === 404) {
            return strings.badNumber || 'Invalid number.';
        }

        return strings.ended || 'Call ended';
    }

    function placeCall(extension) {
        extension = String(extension || '').trim();
        if (!extension) {
            return;
        }
        if (registrationState !== STATE.registered) {
            setMessage(strings.notRegistered || 'Activate the phone before calling.', 'warn');
            return;
        }
        if (session || pendingCall) {
            setMessage(strings.busySelf || 'A call is already in progress.', 'warn');
            return;
        }
        var target = makeTargetUri(extension);
        if (!target) {
            setMessage(strings.badNumber || 'Invalid number.', 'error');
            return;
        }

        // Guard the window between the click and the SIP session so a double
        // click can never open two calls.
        pendingCall = true;
        setMessage(strings.micHint || 'Allow microphone access when prompted.', null);
        showControls(true);
        setMessage(strings.calling || 'Calling…', null);
        startTone('ringback');

        var inviter = new SIP.Inviter(ua, target);
        session = inviter;
        sessionKind = 'outgoing';
        pendingCall = false;
        bindSession(inviter);

        inviter.invite({
            requestDelegate: {
                onProgress: function (response) {
                    var code = response && response.message ? response.message.statusCode : 0;
                    if (inviter === session && (code === 180 || code === 183)) {
                        terminalReason = null;
                        setMessage(strings.ringing || 'Ringing…', null);
                    }
                },
                onReject: function (response) {
                    if (inviter !== session) {
                        return;
                    }
                    var code = response && response.message ? response.message.statusCode : 0;
                    // 487 is our own cancel (or the callee cancelling); the
                    // session's Terminated handler reports that case.
                    terminalReason = code === 487 ? null : outgoingFailure(code);
                    if (terminalReason) {
                        setMessage(terminalReason, 'warn');
                    }
                }
            }
        }).catch(function (error) {
            if (inviter !== session) {
                return;
            }
            setMessage(describeError(error), 'error');
            endSession();
        });
    }

    function handleInvite(invitation) {
        if (session || incomingInvitation) {
            // Already busy: reject cleanly instead of creating a second call.
            invitation.reject({ statusCode: 486 });
            return;
        }
        incomingInvitation = invitation;
        answering = false;
        sessionKind = 'incoming';
        var from = '';
        try {
            from = invitation.remoteIdentity.uri.user || '';
        } catch (e) {
            from = '';
        }
        // Name and number are only shown from what the directory already gives
        // the phone; nothing is looked up per call and nothing is guessed.
        var knownName = from ? directoryNameFor(from) : '';
        callerLabel = knownName || from || (strings.unknownCaller || 'Unknown number');

        if (ui.incomingLabel) {
            ui.incomingLabel.textContent = callerLabel;
        }
        if (ui.incomingExtension) {
            ui.incomingExtension.textContent = from;
        }
        if (ui.incomingAvatar) {
            var initials = initialsFor(knownName || from);
            if (initials) {
                ui.incomingAvatar.textContent = initials;
            } else {
                ui.incomingAvatar.innerHTML = AVATAR_PHONE_ICON;
            }
        }
        if (ui.incomingSoundBtn) {
            ui.incomingSoundBtn.disabled = false;
        }

        openIncomingDialog();

        // Ring, and raise a desktop notification. The in-page window above is
        // always shown, so it remains the interface when the notification is
        // denied or unsupported, or when the browser blocks the ring. Neither
        // can block answering.
        if (!startTone('ringtone')) {
            showSoundNotice();
        } else {
            hideSoundNotice();
        }
        notifyIncoming(callerLabel);
        bindSession(invitation);
    }

    function answer() {
        // The guard both stops a double click and stops a second accept on the
        // same invitation; SIP.js would otherwise see two answers.
        if (!incomingInvitation || answering) {
            return;
        }
        answering = true;
        var invitation = incomingInvitation;
        incomingInvitation = null;
        stopTone();
        closeIncomingNotification();
        closeIncomingDialog();
        session = invitation;
        showControls(true);
        // SIP.js requests the microphone here; a refusal or a missing device
        // rejects the promise with a name we can translate.
        invitation.accept().catch(function (error) {
            setMessage(describeError(error), 'error');
            endSession();
        });
    }

    function reject() {
        if (!incomingInvitation || answering) {
            return;
        }
        answering = true;
        var invitation = incomingInvitation;
        incomingInvitation = null;
        stopTone();
        closeIncomingNotification();
        closeIncomingDialog();
        try {
            invitation.reject();
        } catch (e) {
            /* ignore */
        }
        endSession();
    }

    function hangup() {
        stopTone();
        closeIncomingNotification();
        if (incomingInvitation) {
            reject();
            return;
        }
        if (!session) {
            return;
        }
        var state = session.state;
        try {
            if (sessionKind === 'outgoing' && state === SIP.SessionState.Initial) {
                session.cancel();
            } else if (state === SIP.SessionState.Established) {
                session.bye();
            } else if (state === SIP.SessionState.Establishing) {
                session.cancel();
            }
        } catch (e) {
            /* ignore */
        }
        endSession();
    }

    function bindSession(target) {
        target.stateChange.addListener(function (state) {
            switch (state) {
                case SIP.SessionState.Establishing:
                    // The callee only rings; the caller hears "Ringing…" from
                    // the 180 progress event instead.
                    if (sessionKind === 'outgoing') {
                        setMessage(strings.calling || 'Calling…', null);
                    }
                    break;
                case SIP.SessionState.Established:
                    // The call is up: the negotiated audio replaces any ring.
                    stopTone();
                    closeIncomingNotification();
                    attachRemoteAudio(target);
                    // The audio senders only exist once the media has been
                    // negotiated, so this is the first moment the mute control
                    // can do anything. A new call always starts unmuted.
                    muted = false;
                    watchLocalStream(target.sessionDescriptionHandler);
                    applyMute();
                    startTimer();
                    setMessage(strings.inCall || 'In call', 'ok');
                    break;
                case SIP.SessionState.Terminated:
                    if (target === session || target === incomingInvitation) {
                        setMessage(terminalReason || strings.ended || 'Call ended', terminalReason ? 'warn' : null);
                    }
                    // A caller who hangs up before the answer takes the window,
                    // the ring and any notification with them.
                    endSession();
                    break;
            }
        });
    }

    function attachRemoteAudio(target) {
        var handler = target.sessionDescriptionHandler;
        if (!handler || !handler.peerConnection || !ui.audio) {
            return;
        }
        var stream = new MediaStream();
        handler.peerConnection.getReceivers().forEach(function (receiver) {
            if (receiver.track) {
                stream.addTrack(receiver.track);
            }
        });
        ui.audio.srcObject = stream;
        ui.audio.hidden = false;
        var attempt = ui.audio.play();
        if (attempt && typeof attempt.catch === 'function') {
            attempt.catch(function () {
                setMessage(strings.autoplay || 'Tap the audio control to enable sound.', 'warn');
            });
        }
    }

    function endSession() {
        // Covers every terminal path (ended, cancelled, rejected, failed): no
        // ring or ringback may outlive the session.
        stopTone();
        closeIncomingNotification();
        closeIncomingDialog();
        if (session && session.sessionDescriptionHandler && session.sessionDescriptionHandler.peerConnection) {
            try {
                session.sessionDescriptionHandler.peerConnection.getSenders().forEach(function (sender) {
                    if (sender.track) {
                        sender.track.stop();
                    }
                });
            } catch (e) {
                /* ignore */
            }
        }
        session = null;
        sessionKind = null;
        incomingInvitation = null;
        pendingCall = false;
        terminalReason = null;
        answering = false;
        callerLabel = '';
        // The mute state belongs to the call that just ended: the next call
        // starts unmuted and the button returns to a disabled, neutral state.
        muted = false;
        watchedStream = null;
        reflectMute(false);
        stopTimer();
        showControls(false);
        if (ui.audio) {
            ui.audio.srcObject = null;
            ui.audio.hidden = true;
        }
        if (ui.timer) {
            ui.timer.textContent = '00:00';
        }
    }

    // ---------------------------------------------------------------------
    // Microphone mute
    //
    // The microphone is silenced by disabling the live outgoing audio tracks
    // themselves (`MediaStreamTrack.enabled = false`). That is the only place
    // that matters: the SDP direction is untouched, so the *incoming* audio and
    // the loudspeaker keep working — the agent still hears the other side while
    // the other side hears nothing. `track.stop()` is deliberately NOT used:
    // it is irreversible, so unmuting would ask for the microphone again (and
    // would be refused outside a user gesture).
    //
    // Every mute control is looked up by attribute rather than by a single
    // element id, and the pressed state is written to all of them, so a
    // duplicated control can never disagree with the live call.
    // ---------------------------------------------------------------------

    function muteButtons() {
        return Array.prototype.slice.call(document.querySelectorAll('[data-phone-mute]'));
    }

    /**
     * The audio tracks actually being sent on the current session, or an empty
     * list when there is no call, no session description handler, a closed peer
     * connection — or when the handler belongs to a session that has already
     * been replaced. The last check is what stops the button from ever acting
     * on a stale call.
     */
    function audioSendTracks(target) {
        if (!target || target !== session || !target.sessionDescriptionHandler) {
            return [];
        }
        var pc = target.sessionDescriptionHandler.peerConnection;
        if (!pc || typeof pc.getSenders !== 'function') {
            return [];
        }
        var tracks = [];
        pc.getSenders().forEach(function (sender) {
            if (sender && sender.track && sender.track.kind === 'audio') {
                tracks.push(sender.track);
            }
        });

        return tracks;
    }

    /**
     * Push the current mute state onto the live senders and onto every visible
     * control. Safe to call at any time: with no usable senders it only
     * disables the controls.
     */
    function applyMute() {
        var tracks = audioSendTracks(session);
        tracks.forEach(function (track) {
            track.enabled = !muted;
        });
        reflectMute(tracks.length > 0);

        return tracks.length;
    }

    function reflectMute(available) {
        muteButtons().forEach(function (button) {
            button.setAttribute('aria-pressed', muted ? 'true' : 'false');
            button.disabled = !available;
            button.title = !available
                ? (strings.muteIdle || 'No active call')
                : (muted ? (strings.unmute || 'Unmute microphone') : (strings.mute || 'Mute microphone'));
            var label = button.querySelector('[data-mute-label]');
            if (label) {
                label.textContent = muted
                    ? (strings.unmute || 'Unmute microphone')
                    : (strings.mute || 'Mute microphone');
            }
        });
    }

    /**
     * SIP.js rebuilds the sender when the microphone track is replaced
     * (`setLocalMediaStream()` stops the old track and adds a fresh one with
     * `enabled = true`). Without this hook a mute applied before that swap
     * would silently disappear mid-call.
     */
    function watchLocalStream(handler) {
        var stream = handler && handler.localMediaStream;
        if (!stream || typeof stream.addEventListener !== 'function' || stream === watchedStream) {
            return;
        }
        watchedStream = stream;
        ['addtrack', 'removetrack'].forEach(function (name) {
            stream.addEventListener(name, function () {
                applyMute();
            });
        });
    }

    function toggleMute() {
        // Read the live session, not a captured one: a click that arrives after
        // a call ended (or after a new call started) must act on that call.
        if (!session) {
            reflectMute(false);
            return;
        }
        var tracks = audioSendTracks(session);
        if (!tracks.length) {
            // Nothing to silence yet — keep the button disabled and honest
            // instead of flipping a state that has no effect.
            reflectMute(false);
            return;
        }
        muted = !muted;
        tracks.forEach(function (track) {
            track.enabled = !muted;
        });
        reflectMute(true);
    }

    // ---------------------------------------------------------------------
    // Wiring
    // ---------------------------------------------------------------------

    // "Activate" also steals the phone from another tab: the other tab tears its
    // registration down instead of competing with this one.
    function activateHere() {
        // A real user gesture: the only moment browsers let us unlock audio and
        // ask for notification permission.
        unlockAudio();
        requestNotificationPermission();
        if (!isLeader) {
            if (channel) {
                channel.postMessage({ type: 'takeover', id: tabId });
            }
            claimLeadership();
            setNotice('');
        }
        activate();
    }

    if (ui.activate) {
        ui.activate.addEventListener('click', activateHere);
    }
    // A call can arrive before the employee has touched anything, and browsers
    // only unlock audio from a gesture — so the first interaction anywhere in
    // the page primes the audio context too.
    var audioPrimed = false;
    function primeAudioOnce() {
        if (audioPrimed) {
            return;
        }
        audioPrimed = true;
        document.removeEventListener('pointerdown', primeAudioOnce, true);
        document.removeEventListener('keydown', primeAudioOnce, true);
        unlockAudio();
    }
    document.addEventListener('pointerdown', primeAudioOnce, true);
    document.addEventListener('keydown', primeAudioOnce, true);
    if (ui.call) {
        ui.call.addEventListener('click', function () {
            placeCall(ui.dial ? ui.dial.value : '');
        });
    }
    if (ui.answer) {
        ui.answer.addEventListener('click', answer);
    }
    if (ui.reject) {
        ui.reject.addEventListener('click', reject);
    }
    // Escape must not dismiss the incoming window: an answerable call would be
    // left ringing with no interface. The dialog's backdrop click is inert too
    // (there is no click handler), so only the two buttons change the call.
    if (ui.incoming) {
        ui.incoming.addEventListener('cancel', function (event) {
            event.preventDefault();
        });
    }
    // The sound notice appears when the browser blocked the ring; this gesture
    // is what lets the browser start it again.
    if (ui.incomingSoundBtn) {
        ui.incomingSoundBtn.addEventListener('click', function () {
            ui.incomingSoundBtn.disabled = true;
            unlockAudio();
        });
    }
    if (ui.hangup) {
        ui.hangup.addEventListener('click', hangup);
    }
    // One listener per control, marked on the element so a second pass over the
    // same markup (or a second script tag) can never stack handlers on it.
    muteButtons().forEach(function (button) {
        if (button.dataset.muteBound === 'true') {
            return;
        }
        button.dataset.muteBound = 'true';
        button.addEventListener('click', toggleMute);
    });
    // No call and no senders yet: the control starts disabled and neutral.
    reflectMute(false);
    // Hold and transfer are not part of this phase: they are shown disabled
    // rather than pretending to work.
    [ui.hold, ui.transfer].forEach(function (button) {
        if (button) {
            button.disabled = true;
            button.title = strings.unavailable || 'Not available in this phase';
        }
    });

    if (channel) {
        channel.addEventListener('message', function (event) {
            var data = event.data || {};
            if (data.type === 'leader' && data.id !== tabId) {
                isLeader = false;
                setNotice(strings.otherTab || 'The phone is active in another tab.');
            }
            if (data.type === 'takeover' && data.id !== tabId) {
                teardownUserAgent();
                isLeader = false;
                setStatus(STATE.disconnected, strings.disconnected || 'Disconnected');
                setNotice(strings.otherTab || 'The phone is active in another tab.');
            }
        });
    }

    // Heartbeat so a closed tab's leadership expires.
    window.setInterval(function () {
        if (isLeader) {
            claimLeadership();
        }
    }, 10000);

    window.addEventListener('pageshow', function () {
        // Another tab may have taken over while this page was in the bfcache.
        var leader = readLeader();
        if (leader && leader.id !== tabId) {
            isLeader = false;
            setNotice(strings.otherTab || 'The phone is active in another tab.');
        } else if (leaderIsStale()) {
            claimLeadership();
        }
    });

    function releaseEverything() {
        endSession();
        teardownUserAgent();
        releaseLeadership();
    }

    // Teardown runs on 'pagehide', the reliable, non-deprecated lifecycle
    // event. The deprecated 'unload' event is deliberately NOT used: Chrome
    // warns about it and it is unreliable, and 'pagehide' already covers real
    // navigation and tab close.
    window.addEventListener('pagehide', releaseEverything);

    // A real navigation would drop the WebSocket and the peer connection, so we
    // confirm before leaving — but only while a call is actually in progress.
    window.addEventListener('beforeunload', function (event) {
        if (!session && !incomingInvitation) {
            return;
        }
        event.preventDefault();
        event.returnValue = strings.leaveWarning || 'A call is in progress.';
        return event.returnValue;
    });

    // Moving the tab to the background (another tab, a minimised window, or the
    // screen turning off) must never end the call or drop the registration.
    // Only the leadership claim is refreshed when the tab becomes visible
    // again; nothing is torn down here on purpose.
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible' && isLeader) {
            claimLeadership();
        }
    });

    // Exposed so the page (dialer) and other tabs can drive the same instance.
    window.vodnoxPhone = {
        activate: activateHere,
        placeCall: placeCall,
        refresh: function () {
            return loadCredentials().then(applyCredentials);
        },
        takeover: activateHere,
        isLeader: function () {
            return isLeader;
        },
        isRegistered: function () {
            return registrationState === STATE.registered;
        },
        state: function () {
            return registrationState;
        }
    };

    // Only the first live tab holds the registration; a later tab waits for an
    // explicit "activate" instead of opening a competing one.
    var existingLeader = readLeader();
    if (!existingLeader || existingLeader.id === tabId || leaderIsStale()) {
        claimLeadership();
    } else {
        isLeader = false;
        setNotice(strings.otherTab || 'The phone is active in another tab.');
    }

    loadCredentials().then(applyCredentials).catch(function (error) {
        setStatus(STATE.failed, strings.failed || 'Unavailable');
        setMessage(describeError(error), 'error');
    });
})();
