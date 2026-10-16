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
    var session = null;
    var sessionKind = null; // 'outgoing' | 'incoming'
    var incomingInvitation = null;
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
        if (!ui.message) {
            return;
        }
        ui.message.textContent = text || '';
        ui.message.hidden = !text;
        ui.message.className = 'phone-message' + (kind ? ' ' + kind : '');
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
                    if (registerer) {
                        registerer.register().catch(onRegistrationError);
                    }
                },
                onDisconnect: function (error) {
                    if (error) {
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
        setMessage(strings.micHint || 'Allow microphone access when prompted.', null);

        return ensureMicrophone()
            .then(function () {
                ua = buildUserAgent();
                registerer = new SIP.Registerer(ua);
                registerer.stateChange.addListener(function (state) {
                    if (state === SIP.RegistererState.Registered) {
                        setStatus(STATE.registered, strings.registered || 'Registered');
                        setMessage('', null);
                        retryDelay = 1000;
                        reportRegistration(true);
                    } else if (state === SIP.RegistererState.Unregistered) {
                        setStatus(STATE.disconnected, strings.disconnected || 'Disconnected');
                    } else if (state === SIP.RegistererState.Terminated) {
                        setStatus(STATE.failed, strings.failed || 'Registration failed');
                        scheduleReconnect();
                    }
                });
                return ua.start();
            })
            .then(function () {
                return registerer.register();
            })
            .catch(function (error) {
                setStatus(STATE.failed, strings.failed || 'Registration failed');
                setMessage(String(error && error.message ? error.message : error), 'error');
                scheduleReconnect();
            });
    }

    function ensureMicrophone() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            return Promise.resolve();
        }
        return navigator.mediaDevices.getUserMedia({ audio: true });
    }

    function onRegistrationError(error) {
        setStatus(STATE.failed, strings.failed || 'Registration failed');
        setMessage(String(error && error.message ? error.message : error), 'error');
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

    function placeCall(extension) {
        extension = String(extension || '').trim();
        if (!extension) {
            return;
        }
        if (registrationState !== STATE.registered) {
            setMessage(strings.notRegistered || 'Activate the phone before calling.', 'warn');
            return;
        }
        if (session) {
            setMessage(strings.busySelf || 'A call is already in progress.', 'warn');
            return;
        }
        var target = makeTargetUri(extension);
        if (!target) {
            setMessage(strings.badNumber || 'Invalid number.', 'error');
            return;
        }

        var inviter = new SIP.Inviter(ua, target);
        session = inviter;
        sessionKind = 'outgoing';
        showControls(true);
        setMessage(strings.calling || 'Calling…', null);
        bindSession(inviter);
        inviter.invite().catch(function (error) {
            setMessage(String(error && error.message ? error.message : error), 'error');
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
            ui.incomingLabel.textContent = from;
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
        invitation.accept().catch(function (error) {
            setMessage(String(error && error.message ? error.message : error), 'error');
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
                    setMessage(strings.ringing || 'Ringing…', null);
                    break;
                case SIP.SessionState.Established:
                    attachRemoteAudio(target);
                    startTimer();
                    setMessage(strings.inCall || 'In call', 'ok');
                    break;
                case SIP.SessionState.Terminated:
                    var reason = strings.ended || 'Call ended';
                    if (target === session || target === incomingInvitation) {
                        setMessage(reason, null);
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

    if (ui.activate) {
        ui.activate.addEventListener('click', function () {
            claimLeadership();
            activate();
        });
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

    // Exposed so the page can re-take leadership after a partial navigation.
    window.vodnoxPhone = {
        activate: activate,
        placeCall: placeCall,
        refresh: function () {
            return loadCredentials().then(applyCredentials);
        },
        takeover: function () {
            if (channel) {
                channel.postMessage({ type: 'takeover', id: tabId });
            }
            claimLeadership();
            setNotice('');
            activate();
        },
        isLeader: function () {
            return isLeader;
        }
    };

    claimLeadership();

    loadCredentials().then(applyCredentials).catch(function (error) {
        setStatus(STATE.failed, strings.failed || 'Unavailable');
        setMessage(String(error && error.message ? error.message : error), 'error');
    });
})();
