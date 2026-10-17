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
    if (!root || !window.SIP) {
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
        timer: document.getElementById('phoneTimer'),
        mute: document.getElementById('phoneMuteBtn'),
        hangup: document.getElementById('phoneHangupBtn'),
        answer: document.getElementById('phoneAnswerBtn'),
        reject: document.getElementById('phoneRejectBtn'),
        hold: document.getElementById('phoneHoldBtn'),
        transfer: document.getElementById('phoneTransferBtn'),
        notice: document.getElementById('phoneNotice'),
        message: document.getElementById('phoneMessage'),
        audio: document.getElementById('phoneRemoteAudio')
    };

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
        sessionKind = 'incoming';
        var from = '';
        try {
            from = invitation.remoteIdentity.uri.user || '';
        } catch (e) {
            from = '';
        }
        if (ui.incomingLabel) {
            ui.incomingLabel.textContent = from || (strings.unknownCaller || 'Unknown number');
        }
        if (ui.incoming) {
            ui.incoming.hidden = false;
        }
        bindSession(invitation);
    }

    function answer() {
        if (!incomingInvitation) {
            return;
        }
        var invitation = incomingInvitation;
        incomingInvitation = null;
        session = invitation;
        if (ui.incoming) {
            ui.incoming.hidden = true;
        }
        showControls(true);
        // SIP.js requests the microphone here; a refusal or a missing device
        // rejects the promise with a name we can translate.
        invitation.accept().catch(function (error) {
            setMessage(describeError(error), 'error');
            endSession();
        });
    }

    function reject() {
        if (!incomingInvitation) {
            return;
        }
        var invitation = incomingInvitation;
        incomingInvitation = null;
        if (ui.incoming) {
            ui.incoming.hidden = true;
        }
        try {
            invitation.reject();
        } catch (e) {
            /* ignore */
        }
        endSession();
    }

    function hangup() {
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
                    attachRemoteAudio(target);
                    startTimer();
                    setMessage(strings.inCall || 'In call', 'ok');
                    break;
                case SIP.SessionState.Terminated:
                    if (target === session || target === incomingInvitation) {
                        setMessage(terminalReason || strings.ended || 'Call ended', terminalReason ? 'warn' : null);
                    }
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
        stopTimer();
        showControls(false);
        if (ui.incoming) {
            ui.incoming.hidden = true;
        }
        if (ui.mute) {
            ui.mute.setAttribute('aria-pressed', 'false');
        }
        if (ui.audio) {
            ui.audio.srcObject = null;
            ui.audio.hidden = true;
        }
        if (ui.timer) {
            ui.timer.textContent = '00:00';
        }
    }

    function toggleMute() {
        if (!session || !session.sessionDescriptionHandler) {
            return;
        }
        var pc = session.sessionDescriptionHandler.peerConnection;
        if (!pc) {
            return;
        }
        var muted = ui.mute.getAttribute('aria-pressed') === 'true';
        pc.getSenders().forEach(function (sender) {
            if (sender.track && sender.track.kind === 'audio') {
                sender.track.enabled = muted;
            }
        });
        ui.mute.setAttribute('aria-pressed', muted ? 'false' : 'true');
    }

    // ---------------------------------------------------------------------
    // Wiring
    // ---------------------------------------------------------------------

    // "Activate" also steals the phone from another tab: the other tab tears its
    // registration down instead of competing with this one.
    function activateHere() {
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
    if (ui.hangup) {
        ui.hangup.addEventListener('click', hangup);
    }
    if (ui.mute) {
        ui.mute.addEventListener('click', toggleMute);
    }
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

    window.addEventListener('pagehide', releaseEverything);
    window.addEventListener('beforeunload', function (event) {
        // A full page load would drop the WebSocket and the peer connection,
        // so an active call is confirmed before the browser navigates away.
        if (!session && !incomingInvitation) {
            return;
        }
        event.preventDefault();
        event.returnValue = strings.leaveWarning || 'A call is in progress.';
        return event.returnValue;
    });
    window.addEventListener('unload', releaseEverything);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden' && !session) {
            // Registration is kept while the tab is alive; only the session is
            // torn down on unload. Nothing is done to microphones here.
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
