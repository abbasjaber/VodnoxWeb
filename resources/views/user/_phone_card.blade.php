{{--
    Shared browser-phone card (SIP.js + WebRTC).

    One instance per page. Included by the agent dialer (/user) and the call
    workspaces (/user/incoming, /user/reception) so the real softphone is the
    only phone on the screen — there is no separate prototype dialer any more.

    The credentials are fetched per page load from the authenticated
    user.telephony.credentials endpoint (Cache-Control: no-store) and are kept
    in memory only; nothing is written to localStorage/sessionStorage and no
    provisioning/signing secret ever reaches the browser.

    Ringing and notifications (scope + limits):
    * The incoming ring and the outgoing ringback are synthesised with the Web
      Audio API in telephony.js — no audio file, and no external URL, is loaded.
      The incoming ring is a short two-note VODNOX motif repeated on a slow
      cadence; every oscillator and timer it creates is released the moment the
      call is answered, rejected, cancelled or ended.
    * Browsers block audio until the page is interacted with, so the audio
      context is primed on the "activate" button and on the first gesture of the
      page. If a browser still refuses, the incoming window says so and offers an
      "enable sound" action: the ring is cosmetic, answering the call and the
      conversation audio are never blocked by a blocked ring.
    * The incoming call is an in-page <dialog> centred over the workspace
      (aria-modal, native focus trap, Escape is refused on purpose). It is not a
      window.open popup and it cannot be dismissed to leave a call ringing
      headless.
    * The desktop notification is opt-in (permission is requested at most once
      per page load, from an explicit click). It is only raised while the tab is
      in the background; the in-page window remains the interface even when the
      notification is denied or unsupported.
    * Notifications cannot reach a closed page: there is no service worker or
      push subscription here, so a call is only received while this page is open
      in a tab. There is no background-call delivery, and none is claimed.
    * No audio is recorded and no microphone stream is uploaded anywhere; the
      microphone is opened by SIP.js only for a placed or answered call.
--}}
@php
    // Copy handed to the browser phone. Kept out of the inline script so the JS
    // stays a static asset and the wording stays translatable.
    $phoneStrings = [
        'displayName' => auth()->user()->name,
        'ready' => __('portal.agent.telephony_ready'),
        'disabled' => __('portal.agent.telephony_disabled'),
        'failed' => __('portal.agent.telephony_failed'),
        'provisioning' => __('portal.agent.telephony_provisioning'),
        'unprovisioned' => __('portal.agent.telephony_unprovisioned'),
        'noColleagues' => __('portal.agent.telephony_no_colleagues'),
        'disconnected' => __('portal.agent.telephony_disconnected'),
        'otherTab' => __('portal.agent.telephony_other_tab'),
        'notReady' => __('portal.agent.telephony_not_ready'),
        'connecting' => __('portal.agent.telephony_connecting'),
        'micHint' => __('portal.agent.telephony_mic_hint'),
        'micDenied' => __('portal.agent.telephony_mic_denied'),
        'noMic' => __('portal.agent.telephony_no_mic'),
        'registered' => __('portal.agent.telephony_registered'),
        'notRegistered' => __('portal.agent.telephony_not_registered'),
        'busySelf' => __('portal.agent.telephony_busy_self'),
        'badNumber' => __('portal.agent.telephony_bad_number'),
        'calling' => __('portal.agent.telephony_calling'),
        'ringing' => __('portal.agent.telephony_ringing'),
        'inCall' => __('portal.agent.telephony_in_call'),
        'ended' => __('portal.agent.telephony_ended'),
        'autoplay' => __('portal.agent.telephony_autoplay'),
        'unavailable' => __('portal.agent.telephony_unavailable'),
        'leaveWarning' => __('portal.agent.telephony_leave_warning'),
        'unknownCaller' => __('portal.agent.telephony_unknown_caller'),
        'incomingTitle' => __('portal.agent.telephony_incoming_title'),
        'peerBusy' => __('portal.agent.telephony_peer_busy'),
        'peerRejected' => __('portal.agent.telephony_peer_rejected'),
        'noAnswer' => __('portal.agent.telephony_no_answer'),
        'networkLost' => __('portal.agent.telephony_network_lost'),
        'sipMissing' => __('portal.agent.telephony_sip_missing'),
        'incomingAnswer' => __('portal.agent.telephony_incoming_answer'),
        'incomingReject' => __('portal.agent.telephony_incoming_reject'),
        'incomingRinging' => __('portal.agent.telephony_incoming_ringing'),
        'incomingMissed' => __('portal.agent.telephony_incoming_missed'),
        'soundBlocked' => __('portal.agent.telephony_incoming_sound_blocked'),
        'soundEnable' => __('portal.agent.telephony_incoming_sound_enable'),
        'incomingScope' => __('portal.agent.telephony_incoming_scope'),
    ];
@endphp

<section class="rx-card phone-card" id="vodnoxPhone"
         data-credentials-url="{{ route('user.telephony.credentials') }}"
         data-registration-url="{{ route('user.telephony.registration') }}"
         data-provision-url="{{ route('user.telephony.provision') }}"
         data-csrf="{{ csrf_token() }}"
         data-strings='@json($phoneStrings)'>
    <div class="rx-card-head">
        <div>
            <h2>{{ __('portal.agent.telephony_title') }}</h2>
            <small>{{ __('portal.agent.telephony_sub') }}</small>
        </div>
        <span class="rx-pill phone-status" id="phoneStatusPill" data-state="unprovisioned">{{ __('portal.agent.telephony_unprovisioned') }}</span>
    </div>
    <div class="rx-card-body">
        <p class="phone-message" id="phoneMessage" role="status" hidden></p>
        <p class="phone-notice" id="phoneNotice" hidden></p>

        <div class="phone-row">
            <button type="button" class="rx-btn primary" id="phoneActivate">{{ __('portal.agent.telephony_activate') }}</button>
            <input type="tel" id="phoneDialInput" class="phone-dial" dir="ltr" inputmode="tel" autocomplete="off"
                   placeholder="{{ __('portal.agent.telephony_dial_placeholder') }}"
                   aria-label="{{ __('portal.agent.telephony_dial_placeholder') }}">
            <button type="button" class="rx-btn gold" id="phoneCallBtn">{{ __('portal.agent.telephony_call') }}</button>
        </div>

        <div class="phone-controls" id="phoneCallControls" hidden>
            <span class="phone-timer" id="phoneTimer" dir="ltr">00:00</span>
            <button type="button" class="rx-btn" id="phoneMuteBtn" aria-pressed="false">{{ __('portal.agent.telephony_mute') }}</button>
            <button type="button" class="rx-btn" id="phoneHoldBtn" disabled>{{ __('portal.agent.telephony_hold') }}</button>
            <button type="button" class="rx-btn" id="phoneTransferBtn" disabled>{{ __('portal.agent.telephony_transfer') }}</button>
            <button type="button" class="rx-btn danger" id="phoneHangupBtn">{{ __('portal.agent.telephony_hangup') }}</button>
        </div>

        <div class="phone-directory">
            <h3>{{ __('portal.agent.telephony_directory') }}</h3>
            <ul id="phoneDirectoryList"></ul>
        </div>

        <audio id="phoneRemoteAudio" hidden></audio>
    </div>

    {{--
        The incoming call window.

        It is an in-page <dialog> opened with showModal(): the browser centres it,
        puts it in the top layer (so no ancestor overflow or transform can clip
        it) and keeps the keyboard focus inside it. There is deliberately no
        window.open and no backdrop-click or Escape dismissal — closing it while
        the call still rings would leave a call with no interface.
    --}}
    <dialog id="phoneIncoming" class="phone-incoming" role="alertdialog" aria-modal="true"
            aria-labelledby="phoneIncomingTitle" aria-describedby="phoneIncomingLabel">
        <div class="incoming-inner">
            <p class="incoming-brand">VODNOX</p>
            <h2 id="phoneIncomingTitle">{{ __('portal.agent.telephony_incoming_title') }}</h2>

            <div class="incoming-orbit" aria-hidden="true">
                <span class="incoming-avatar" id="phoneIncomingAvatar"></span>
            </div>

            <strong class="incoming-name" id="phoneIncomingLabel"></strong>
            <span class="incoming-extension" id="phoneIncomingExtension" dir="ltr"></span>
            <p class="incoming-state" id="phoneIncomingState">{{ __('portal.agent.telephony_incoming_ringing') }}</p>

            <p class="incoming-sound" id="phoneIncomingSound" hidden>
                <span>{{ __('portal.agent.telephony_incoming_sound_blocked') }}</span>
                <button type="button" class="rx-btn incoming-sound-btn" id="phoneIncomingSoundBtn">{{ __('portal.agent.telephony_incoming_sound_enable') }}</button>
            </p>

            <div class="incoming-actions">
                <button type="button" class="rx-btn incoming-answer" id="phoneAnswerBtn" autofocus>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m7 3 3 5-3 3a15 15 0 0 0 6 6l3-3 5 3-1 4C10 22 2 14 3 4l4-1Z"/></svg>
                    <span>{{ __('portal.agent.telephony_incoming_answer') }}</span>
                </button>
                <button type="button" class="rx-btn incoming-reject" id="phoneRejectBtn">
                    <svg class="incoming-reject-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m7 3 3 5-3 3a15 15 0 0 0 6 6l3-3 5 3-1 4C10 22 2 14 3 4l4-1Z"/></svg>
                    <span>{{ __('portal.agent.telephony_incoming_reject') }}</span>
                </button>
            </div>

            <p class="incoming-scope">{{ __('portal.agent.telephony_incoming_scope') }}</p>
        </div>
    </dialog>
</section>

@push('styles')
<link rel="stylesheet" href="{{ asset('assets/vodnox/telephony.css') }}">
@endpush

@push('scripts')
<script src="{{ asset('assets/vodnox/vendor/sip.min.js') }}"></script>
<script src="{{ asset('assets/vodnox/telephony.js') }}"></script>
@endpush
