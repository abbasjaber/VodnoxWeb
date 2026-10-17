{{--
    Shared browser-phone card (SIP.js + WebRTC).

    One instance per page. Included by the agent dialer (/user) and the call
    workspaces (/user/incoming, /user/reception) so the real softphone is the
    only phone on the screen — there is no separate prototype dialer any more.

    The credentials are fetched per page load from the authenticated
    user.telephony.credentials endpoint (Cache-Control: no-store) and are kept
    in memory only; nothing is written to localStorage/sessionStorage and no
    provisioning/signing secret ever reaches the browser.
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
        'peerBusy' => __('portal.agent.telephony_peer_busy'),
        'peerRejected' => __('portal.agent.telephony_peer_rejected'),
        'noAnswer' => __('portal.agent.telephony_no_answer'),
        'networkLost' => __('portal.agent.telephony_network_lost'),
        'sipMissing' => __('portal.agent.telephony_sip_missing'),
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

        <div class="phone-incoming" id="phoneIncoming" hidden>
            <strong id="phoneIncomingLabel" dir="ltr"></strong>
            <div class="phone-row">
                <button type="button" class="rx-btn primary" id="phoneAnswerBtn">{{ __('portal.agent.telephony_answer') }}</button>
                <button type="button" class="rx-btn danger" id="phoneRejectBtn">{{ __('portal.agent.telephony_reject') }}</button>
            </div>
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
</section>

@push('styles')
<link rel="stylesheet" href="{{ asset('assets/vodnox/telephony.css') }}">
@endpush

@push('scripts')
<script src="{{ asset('assets/vodnox/vendor/sip.min.js') }}"></script>
<script src="{{ asset('assets/vodnox/telephony.js') }}"></script>
@endpush
