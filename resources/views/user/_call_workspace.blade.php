{{--
    Shared call workspace for the incoming-call and reception screens.

    Both controllers persist the same documentation (notes, complaints,
    follow-ups and the wrap-up) through the same request payload, so the markup
    lives here once and each view only supplies its own route and copy.

    Expected variables: $organization, $session, $draftWrapup, $notes,
    $complaints, $followups, $recentSessions, $customers, $departments,
    $agents and $storeRoute.
--}}
@php
    $clock = fn (?int $seconds) => sprintf('%02d:%02d', intdiv((int) $seconds, 60), (int) $seconds % 60);
    $baghdad = fn ($moment, string $format) => $moment ? $moment->timezone(config('app.timezone'))->format($format) : '—';

    $outcomeMap = [
        'resolved' => __('portal.agent.outcome_resolved'),
        'ticket' => __('portal.agent.outcome_ticket'),
        'callback' => __('portal.agent.outcome_callback'),
        'information' => __('portal.agent.outcome_information'),
        'unanswered' => __('portal.agent.outcome_unanswered'),
    ];
    $priorityMap = [
        'low' => __('portal.agent.priority_low'),
        'medium' => __('portal.agent.priority_medium'),
        'high' => __('portal.agent.priority_high'),
    ];
    $followupStatusMap = [
        'pending' => __('portal.agent.followup_status_pending'),
        'completed' => __('portal.agent.followup_status_completed'),
        'cancelled' => __('portal.agent.followup_status_cancelled'),
    ];

    // A wrap-up left open without an active call blocks the next session.
    $blockedWrapup = ! $session && $draftWrapup ? $draftWrapup : null;

    // Copy handed to the browser phone (SIP.js). Kept out of the inline script
    // so the JS stays a static asset and the wording stays translatable.
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

<div class="simulation-note">
    <span class="simulation-chip">{{ __('portal.agent.simulation') }}</span>
    <span>{{ __('portal.agent.simulation_notice') }}</span>
</div>

@if ($blockedWrapup)
    <section class="card">
        <div class="card-head">
            <div>
                <h2>{{ __('portal.agent.wrapup_pending') }}</h2>
                <p>{{ __('portal.agent.session_blocked') }}</p>
            </div>
            <span class="status pending">{{ __('portal.agent.simulation') }}</span>
        </div>
    </section>
@endif

@if (! $session)
    <section class="rx-card">
        <div class="rx-card-head">
            <div>
                <h2>{{ __('portal.agent.start_session') }}</h2>
                <small>{{ __('portal.agent.start_session_hint') }}</small>
            </div>
            <span class="rx-pill">{{ __('portal.agent.not_connected') }}</span>
        </div>
        <div class="rx-card-body">
            <form method="POST" action="{{ $storeRoute }}">
                @csrf
                <input type="hidden" name="action" value="start">
                <div class="rx-fields">
                    <div class="rx-field">
                        <label for="start-caller">{{ __('portal.agent.customer_phone') }}</label>
                        <input id="start-caller" type="text" name="caller_number" maxlength="32" dir="ltr"
                               value="{{ old('caller_number') }}">
                    </div>
                    <div class="rx-field">
                        <label for="start-customer">{{ __('portal.agent.link_customer') }}</label>
                        <select id="start-customer" name="customer_id">
                            <option value="">{{ __('portal.agent.none') }}</option>
                            @foreach ($customers as $customer)
                                <option value="{{ $customer->id }}">
                                    {{ $customer->name_ar ?: $customer->name_en }}@if ($customer->phone) &middot; {{ $customer->phone }}@endif
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="detail-actions">
                    <button class="rx-btn primary" type="submit">{{ __('portal.agent.start_session') }}</button>
                </div>
            </form>
        </div>
    </section>
@else
    <section class="rx-card">
        <div class="rx-card-head">
            <div>
                <h2>{{ $session->caller_number ?: __('portal.agent.unknown_number') }}</h2>
                <small>{{ __('portal.agent.started_at') }}: {{ $baghdad($session->started_at, 'Y-m-d H:i') }}</small>
            </div>
            <span class="rx-pill green">{{ __('portal.agent.session_active') }}</span>
        </div>
        <div class="rx-card-body">
            <dl class="rx-meta">
                <div><dt>{{ __('portal.agent.customer_phone') }}</dt><dd dir="ltr">{{ $session->caller_number ?: '—' }}</dd></div>
                <div><dt>{{ __('portal.agent.extension') }}</dt><dd>{{ $session->callee_number ?: '—' }}</dd></div>
                <div><dt>{{ __('portal.agent.customer') }}</dt><dd>{{ optional($customers->firstWhere('id', $session->customer_id))->name_ar ?: __('portal.agent.none') }}</dd></div>
            </dl>
        </div>
    </section>

    <div class="rx-grid">
        <div class="rx-column">
            <section class="rx-card">
                <div class="rx-card-head">
                    <div><h2>{{ __('portal.agent.documented') }}</h2></div>
                </div>
                <div class="rx-card-body">
                    <div class="rx-edit-tabs" role="tablist">
                        <button type="button" role="tab" aria-selected="true" data-panel="note">{{ __('portal.agent.tab_note') }}</button>
                        <button type="button" role="tab" aria-selected="false" data-panel="complaint">{{ __('portal.agent.tab_complaint') }}</button>
                        <button type="button" role="tab" aria-selected="false" data-panel="followup">{{ __('portal.agent.tab_followup') }}</button>
                        <button type="button" role="tab" aria-selected="false" data-panel="wrapup">{{ __('portal.agent.tab_wrapup') }}</button>
                    </div>

                    <div class="rx-editor" data-panel-body="note" role="tabpanel">
                        <form method="POST" action="{{ $storeRoute }}">
                            @csrf
                            <input type="hidden" name="action" value="note">
                            <div class="rx-fields">
                                <div class="rx-field full">
                                    <label for="note-content">{{ __('portal.agent.content') }}</label>
                                    <textarea id="note-content" name="content" maxlength="5000" required>{{ old('content') }}</textarea>
                                </div>
                            </div>
                            <div class="detail-actions">
                                <button class="rx-btn primary" type="submit">{{ __('portal.agent.save_note') }}</button>
                            </div>
                        </form>
                    </div>

                    <div class="rx-editor" data-panel-body="complaint" role="tabpanel" hidden>
                        <form method="POST" action="{{ $storeRoute }}">
                            @csrf
                            <input type="hidden" name="action" value="complaint">
                            <div class="rx-fields">
                                <div class="rx-field full">
                                    <label for="complaint-subject">{{ __('portal.agent.subject') }}</label>
                                    <input id="complaint-subject" type="text" name="subject" maxlength="255" required>
                                </div>
                                <div class="rx-field">
                                    <label for="complaint-priority">{{ __('portal.agent.priority') }}</label>
                                    <select id="complaint-priority" name="priority">
                                        <option value="low">{{ __('portal.agent.priority_low') }}</option>
                                        <option value="medium" selected>{{ __('portal.agent.priority_medium') }}</option>
                                        <option value="high">{{ __('portal.agent.priority_high') }}</option>
                                    </select>
                                </div>
                                <div class="rx-field">
                                    <label for="complaint-department">{{ __('portal.agent.department') }}</label>
                                    <select id="complaint-department" name="department_id">
                                        <option value="">{{ __('portal.agent.none') }}</option>
                                        @foreach ($departments as $department)
                                            <option value="{{ $department->id }}">{{ $department->name_ar ?: $department->name_en }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="rx-field">
                                    <label for="complaint-agent">{{ __('portal.agent.assigned_to') }}</label>
                                    <select id="complaint-agent" name="assigned_to">
                                        <option value="">{{ __('portal.agent.none') }}</option>
                                        @foreach ($agents as $agent)
                                            <option value="{{ $agent->id }}">{{ $agent->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="rx-field">
                                    <label for="complaint-customer">{{ __('portal.agent.link_customer') }}</label>
                                    <select id="complaint-customer" name="customer_id">
                                        <option value="">{{ __('portal.agent.none') }}</option>
                                        @foreach ($customers as $customer)
                                            <option value="{{ $customer->id }}" @selected((int) $session->customer_id === (int) $customer->id)>{{ $customer->name_ar ?: $customer->name_en }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="rx-field full">
                                    <label for="complaint-description">{{ __('portal.agent.description') }}</label>
                                    <textarea id="complaint-description" name="description" maxlength="5000" required></textarea>
                                </div>
                            </div>
                            <div class="detail-actions">
                                <button class="rx-btn primary" type="submit">{{ __('portal.agent.save_complaint') }}</button>
                            </div>
                        </form>
                    </div>

                    <div class="rx-editor" data-panel-body="followup" role="tabpanel" hidden>
                        <form method="POST" action="{{ $storeRoute }}">
                            @csrf
                            <input type="hidden" name="action" value="followup">
                            <div class="rx-fields">
                                <div class="rx-field full">
                                    <label for="followup-title">{{ __('portal.agent.title_field') }}</label>
                                    <input id="followup-title" type="text" name="title" maxlength="255" required>
                                </div>
                                <div class="rx-field">
                                    <label for="followup-due">{{ __('portal.agent.due_at') }}</label>
                                    <input id="followup-due" type="datetime-local" name="due_at">
                                </div>
                                <div class="rx-field">
                                    <label for="followup-customer">{{ __('portal.agent.link_customer') }}</label>
                                    <select id="followup-customer" name="customer_id">
                                        <option value="">{{ __('portal.agent.none') }}</option>
                                        @foreach ($customers as $customer)
                                            <option value="{{ $customer->id }}" @selected((int) $session->customer_id === (int) $customer->id)>{{ $customer->name_ar ?: $customer->name_en }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="rx-field full">
                                    <label for="followup-description">{{ __('portal.agent.description') }}</label>
                                    <textarea id="followup-description" name="description" maxlength="5000"></textarea>
                                </div>
                            </div>
                            <p class="help-note">{{ __('portal.agent.due_future') }}</p>
                            <div class="detail-actions">
                                <button class="rx-btn primary" type="submit">{{ __('portal.agent.save_followup') }}</button>
                            </div>
                        </form>
                    </div>

                    <div class="rx-editor" data-panel-body="wrapup" role="tabpanel" hidden>
                        <form method="POST" action="{{ $storeRoute }}">
                            @csrf
                            <div class="rx-fields">
                                <div class="rx-field">
                                    <label for="wrapup-outcome">{{ __('portal.agent.outcome') }}</label>
                                    <select id="wrapup-outcome" name="outcome">
                                        <option value="">{{ __('portal.agent.none') }}</option>
                                        @foreach ($outcomeMap as $key => $label)
                                            <option value="{{ $key }}" @selected(optional($draftWrapup)->outcome === $key)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="rx-field full">
                                    <label for="wrapup-summary">{{ __('portal.agent.summary') }}</label>
                                    <textarea id="wrapup-summary" name="summary" maxlength="5000">{{ optional($draftWrapup)->summary }}</textarea>
                                </div>
                            </div>
                            <div class="detail-actions">
                                <button class="rx-btn" type="submit" name="action" value="wrapup">{{ __('portal.agent.save_draft') }}</button>
                                <button class="rx-btn primary" type="submit" name="action" value="finish">{{ __('portal.agent.finish_session') }}</button>
                            </div>
                        </form>
                    </div>
                </div>
            </section>
        </div>

        <aside class="rx-column">
            <section class="rx-card">
                <div class="rx-card-head">
                    <div><h2>{{ __('portal.agent.documented') }}</h2></div>
                </div>
                <div class="rx-card-body">
                    <div class="rx-timeline">
                        @forelse ($notes as $note)
                            <article class="rx-event">
                                <time>{{ $baghdad($note->created_at, 'Y-m-d H:i') }}</time>
                                <h3>{{ __('portal.agent.kind_note') }}</h3>
                                <p>{{ $note->content }}</p>
                            </article>
                        @empty
                        @endforelse

                        @foreach ($complaints as $complaint)
                            <article class="rx-event">
                                <time>{{ $baghdad($complaint->created_at, 'Y-m-d H:i') }}</time>
                                <h3>{{ __('portal.agent.kind_complaint') }} &middot; {{ $complaint->subject }}</h3>
                                <p>{{ $complaint->description }}</p>
                                <span class="rx-pill">{{ $priorityMap[$complaint->priority] ?? $complaint->priority }}</span>
                            </article>
                        @endforeach

                        @foreach ($followups as $followup)
                            <article class="rx-event">
                                <time>{{ $baghdad($followup->created_at, 'Y-m-d H:i') }}</time>
                                <h3>{{ __('portal.agent.kind_followup') }} &middot; {{ $followup->title }}</h3>
                                @if ($followup->description)
                                    <p>{{ $followup->description }}</p>
                                @endif
                                <span class="rx-pill">{{ $followupStatusMap[$followup->status] ?? $followup->status }}
                                    @if ($followup->due_at) &middot; {{ $baghdad($followup->due_at, 'Y-m-d H:i') }} @endif
                                </span>
                            </article>
                        @endforeach

                        @if ($notes->isEmpty() && $complaints->isEmpty() && $followups->isEmpty())
                            <div class="rx-empty">{{ __('portal.agent.no_records') }}</div>
                        @endif
                    </div>
                </div>
            </section>

            <section class="rx-card">
                <div class="rx-card-head">
                    <div><h2>{{ __('portal.agent.recent_sessions') }}</h2></div>
                </div>
                <div class="rx-card-body">
                    <div class="rx-timeline">
                        @forelse ($recentSessions as $recent)
                            <article class="rx-event">
                                <time>{{ $baghdad($recent->updated_at, 'Y-m-d H:i') }}</time>
                                <h3>{{ $outcomeMap[$recent->outcome] ?? __('portal.agent.kind_wrapup') }}</h3>
                                @if ($recent->summary)
                                    <p>{{ $recent->summary }}</p>
                                @endif
                            </article>
                        @empty
                            <div class="rx-empty">{{ __('portal.agent.no_sessions') }}</div>
                        @endforelse
                    </div>
                </div>
            </section>
        </aside>
    </div>
@endif

@push('styles')
<link rel="stylesheet" href="{{ asset('assets/vodnox/telephony.css') }}">
@endpush

@push('scripts')
<script src="{{ asset('assets/vodnox/vendor/sip.min.js') }}"></script>
<script src="{{ asset('assets/vodnox/telephony.js') }}"></script>
<script>
(function () {
    'use strict';

    var tabs = document.querySelectorAll('[data-panel]');
    var panels = document.querySelectorAll('[data-panel-body]');
    if (!tabs.length) { return; }

    function show(name) {
        tabs.forEach(function (tab) {
            tab.setAttribute('aria-selected', tab.getAttribute('data-panel') === name ? 'true' : 'false');
        });
        panels.forEach(function (panel) {
            panel.hidden = panel.getAttribute('data-panel-body') !== name;
        });
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () { show(tab.getAttribute('data-panel')); });
    });
})();
</script>
@endpush
