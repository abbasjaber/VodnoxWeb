@extends('layouts.user')

@section('title', __('portal.agent.new_call'))
@section('crumb', __('portal.agent.my_line'))

@php
    $statusMap = [
        'answered' => __('portal.agent.answered'),
        'missed' => __('portal.agent.missed'),
        'cancelled' => __('portal.agent.cancelled'),
        'ringing' => __('portal.agent.incoming'),
    ];
    $statusTone = ['answered' => 'active', 'missed' => 'expired', 'cancelled' => 'draft', 'ringing' => 'pending'];
    $clock = fn (?int $seconds) => sprintf('%02d:%02d', intdiv((int) $seconds, 60), (int) $seconds % 60);
    $baghdad = fn ($moment, string $format) => $moment ? $moment->timezone(config('app.timezone'))->format($format) : '—';
@endphp

@section('content')
@include('user._workspace')

<div class="desk-head">
    <div>
        <h1>{{ __('portal.agent.dialer_greeting') }}</h1>
        <p>{{ __('portal.agent.dialer_text') }}</p>
    </div>
    <div class="availability-wrap">
        <span>{{ __('portal.agent.availability') }}</span>
        <select id="agentAvailability" aria-label="{{ __('portal.agent.availability') }}">
            <option value="ready" selected>{{ __('portal.agent.availability_ready') }}</option>
            <option value="busy">{{ __('portal.agent.availability_busy') }}</option>
            <option value="away">{{ __('portal.agent.availability_away') }}</option>
        </select>
    </div>
</div>

<div class="call-desk">
    <section class="dialer-card">
        <div class="dialer-top">
            <h2>{{ __('portal.agent.new_call') }}</h2>
            <span class="status draft">{{ __('portal.agent.not_connected') }}</span>
        </div>
        <div class="dialer-body">
            <div class="number-label">{{ __('portal.agent.enter_number') }}</div>
            <div class="number-entry">
                <input id="dialNumber" type="tel" inputmode="tel" maxlength="20" autocomplete="off"
                       placeholder="{{ __('portal.agent.phone_number') }}" aria-label="{{ __('portal.agent.phone_number') }}">
                <button id="dialBackspace" type="button" aria-label="{{ __('portal.agent.delete_last') }}">&#9003;</button>
            </div>
            <div class="keypad">
                @foreach ([['1', ''], ['2', 'ABC'], ['3', 'DEF'], ['4', 'GHI'], ['5', 'JKL'], ['6', 'MNO'], ['7', 'PQRS'], ['8', 'TUV'], ['9', 'WXYZ'], ['*', ''], ['0', '+'], ['#', '']] as [$digit, $letters])
                    <button class="dial-key" type="button" data-digit="{{ $digit }}" aria-label="{{ $digit }}">
                        <b>{{ $digit }}</b><span>{{ $letters }}</span>
                    </button>
                @endforeach
            </div>
            <button class="call-button" type="button" id="dialCall"
                    data-extension="{{ auth()->user()->extension }}"
                    data-invalid="{{ __('portal.agent.invalid_number') }}"
                    data-own="{{ __('portal.agent.own_extension') }}"
                    data-blocked="{{ __('portal.agent.call_blocked_text') }}">
                {{ __('portal.agent.call') }}
            </button>
            <p id="dialNotice" class="help-note" hidden></p>
            <p class="dialer-note">
                {{ __('portal.agent.your_extension') }}: <span class="number">{{ auth()->user()->extension ?? '-' }}</span>
                &middot; {{ __('portal.agent.registration_unverified') }}
            </p>
        </div>
        <div class="my-line">
            <span>VODNOX / {{ __('portal.agent.my_line') }}</span>
            <strong>{{ $organization->name_ar ?? $organization->name_en ?? '-' }}</strong>
        </div>
    </section>

    <div class="workspace-side">
        <section class="card">
            <div class="card-head">
                <div>
                    <h2>{{ __('portal.agent.favorites') }}</h2>
                    <p>{{ __('portal.agent.favorites_sub') }}</p>
                </div>
                <a class="text-btn" href="{{ route('user.contacts.index') }}">{{ __('portal.agent.all_contacts') }}</a>
            </div>
            <div class="favorites-row">
                @forelse ($favorites as $favorite)
                    <div class="favorite">
                        <span class="contact-avatar">{{ mb_substr($favorite->name, 0, 2) }}</span>
                        <strong>{{ $favorite->name }}</strong>
                        <small>{{ $favorite->phone_number ?: '-' }}</small>
                    </div>
                @empty
                    <p class="help-note">{{ __('portal.agent.add_to_favorites') }}</p>
                @endforelse
            </div>
        </section>

        <div class="mini-stats">
            <div class="mini-stat">
                <span>{{ __('portal.agent.my_calls_today') }}</span>
                <strong>{{ $stats['calls_today'] }}</strong>
                <small>{{ __('portal.agent.simulation') }}</small>
            </div>
            <div class="mini-stat">
                <span>{{ __('portal.agent.missed_incoming') }}</span>
                <strong>{{ $stats['missed_incoming'] }}</strong>
                <small>{{ __('portal.agent.simulation') }}</small>
            </div>
            <div class="mini-stat">
                <span>{{ __('portal.agent.talk_time') }}</span>
                <strong>{{ $clock($stats['talk_time']) }}</strong>
                <small>{{ __('portal.agent.simulation') }}</small>
            </div>
        </div>

        <section class="card">
            <div class="card-head">
                <div><h2>{{ __('portal.agent.my_recent_calls') }}</h2></div>
                <a class="text-btn" href="{{ route('user.history.index') }}">{{ __('portal.agent.view_all') }}</a>
            </div>
            <div class="table-wrap">
                <table>
                    <tbody>
                        @forelse ($recentCalls as $call)
                            @php
                                $peer = $call->direction === 'inbound' ? $call->caller_number : $call->callee_number;
                            @endphp
                            <tr>
                                <td>
                                    <div class="call-type {{ $call->status === 'missed' ? 'missed' : '' }}">
                                        <span class="call-direction">{{ $call->direction === 'inbound' ? __('portal.agent.incoming') : __('portal.agent.outgoing') }}</span>
                                        <span>
                                            <strong>{{ $peer ?: __('portal.agent.unknown_number') }}</strong>
                                            <small>{{ $call->caller_number }} &rarr; {{ $call->callee_number }}</small>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <div class="recent-time">
                                        <span class="number">{{ $baghdad($call->started_at, 'H:i') }}</span>
                                        <small class="number">{{ $baghdad($call->started_at, 'Y-m-d') }}</small>
                                    </div>
                                </td>
                                <td><span class="number">{{ $clock($call->duration_seconds) }}</span></td>
                                <td>
                                    <span class="status {{ $statusTone[$call->status] ?? 'draft' }}">
                                        {{ $statusMap[$call->status] ?? $call->status }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr><td class="empty-row">{{ __('portal.agent.no_calls') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="personal-note">
            <span aria-hidden="true">&#127911;</span>
            <div>
                <h3>{{ __('portal.agent.audio_setup_title') }}</h3>
                <p>{{ __('portal.agent.audio_setup_text') }}</p>
            </div>
            <a class="text-btn" href="{{ route('user.settings.index') }}">{{ __('portal.agent.settings') }}</a>
        </section>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    'use strict';

    var input = document.getElementById('dialNumber');
    var callButton = document.getElementById('dialCall');
    var notice = document.getElementById('dialNotice');
    if (!input || !callButton) { return; }

    function tell(message) {
        if (!notice) { return; }
        notice.textContent = message || '';
        notice.hidden = !message;
    }

    document.querySelectorAll('.dial-key[data-digit]').forEach(function (key) {
        key.addEventListener('click', function () {
            input.value = (input.value + key.getAttribute('data-digit')).slice(0, 20);
            input.focus();
        });
    });

    var backspace = document.getElementById('dialBackspace');
    if (backspace) {
        backspace.addEventListener('click', function () {
            input.value = input.value.slice(0, -1);
            input.focus();
        });
    }

    /* There is no telephony integration: the dialer never fakes a connection. */
    callButton.addEventListener('click', function () {
        var number = (input.value || '').replace(/\s+/g, '');
        if (!/^\+?[0-9*#]{3,15}$/.test(number)) {
            tell(callButton.getAttribute('data-invalid'));
            return;
        }
        if (number === callButton.getAttribute('data-extension')) {
            tell(callButton.getAttribute('data-own'));
            return;
        }
        tell(callButton.getAttribute('data-blocked'));
    });
})();
</script>
@endpush
