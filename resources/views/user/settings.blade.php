@extends('layouts.user')

@section('title', __('portal.agent.settings_title'))
@section('crumb', __('portal.agent.settings_title'))

@php
    $audioDevice = old('audio_device', $preference->audio_device ?: 'default');
    $ringtone = old('ringtone', ! empty($settings['ringtone']));
    $notifications = old('notifications', ! empty($settings['notifications']));
@endphp

@section('content')
@include('user._workspace')

<div class="page-head">
    <div>
        <h1>{{ __('portal.agent.settings_title') }}</h1>
        <p>{{ __('portal.agent.settings_sub') }}</p>
    </div>
    <span class="status draft">{{ __('portal.agent.device_unverified') }}</span>
</div>

<div class="settings-layout">
    <form method="POST" action="{{ route('user.settings.update') }}">
        @csrf
        @method('PUT')

        <section class="card audio-settings">
            <div class="card-head">
                <div>
                    <h2>{{ __('portal.agent.audio_title') }}</h2>
                    <p>{{ __('portal.agent.mic_note') }}</p>
                </div>
            </div>
            <div class="settings-body">
                <div class="form-field">
                    <label for="audio_device">{{ __('portal.agent.preferred_output') }}</label>
                    <select id="audio_device" name="audio_device">
                        <option value="default" @selected($audioDevice === 'default')>{{ __('portal.agent.device_default') }}</option>
                        <option value="headset" @selected($audioDevice === 'headset')>{{ __('portal.agent.headset') }}</option>
                    </select>
                </div>

                <label class="toggle-line">
                    <div>
                        <b>{{ __('portal.agent.ringtone') }}</b>
                        <p>{{ __('portal.agent.ringtone_note') }}</p>
                    </div>
                    <input type="checkbox" class="switch" name="ringtone" value="1" role="switch"
                           aria-checked="{{ $ringtone ? 'true' : 'false' }}" @checked($ringtone)>
                </label>

                <label class="toggle-line">
                    <div>
                        <b>{{ __('portal.agent.call_notifications') }}</b>
                        <p>{{ __('portal.agent.call_notifications_note') }}</p>
                    </div>
                    <input type="checkbox" class="switch" name="notifications" value="1" role="switch"
                           aria-checked="{{ $notifications ? 'true' : 'false' }}" @checked($notifications)>
                </label>

                <label class="toggle-line">
                    <div>
                        <b>{{ __('portal.agent.auto_answer') }}</b>
                        <p>{{ __('portal.agent.auto_answer_note') }}</p>
                    </div>
                    <input type="checkbox" class="switch" name="auto_answer" value="1" role="switch"
                           aria-checked="{{ $preference->auto_answer ? 'true' : 'false' }}" @checked(old('auto_answer', $preference->auto_answer))>
                </label>

                <label class="toggle-line">
                    <div>
                        <b>{{ __('portal.agent.do_not_disturb') }}</b>
                        <p>{{ __('portal.agent.do_not_disturb_note') }}</p>
                    </div>
                    <input type="checkbox" class="switch" name="do_not_disturb" value="1" role="switch"
                           aria-checked="{{ $preference->do_not_disturb ? 'true' : 'false' }}" @checked(old('do_not_disturb', $preference->do_not_disturb))>
                </label>

                <div class="form-field">
                    <label for="phone">{{ __('portal.agent.phone') }}</label>
                    <input id="phone" type="text" name="phone" maxlength="32" value="{{ old('phone', $user->phone) }}">
                </div>

                <div class="detail-actions">
                    <button class="btn-primary" type="submit">{{ __('portal.agent.save_preferences') }}</button>
                </div>
            </div>
        </section>
    </form>

    <div>
        <section class="card">
            <div class="card-head">
                <div>
                    <h2>{{ __('portal.agent.review_account') }}</h2>
                    <p>{{ __('portal.agent.review_account_text') }}</p>
                </div>
            </div>
            <div class="profile-card">
                <div class="profile-top">
                    <span class="company-avatar">{{ mb_substr($user->name, 0, 1) }}</span>
                    <div>
                        <strong>{{ $user->name }}</strong>
                        <small>{{ $user->position ?: $user->username }}</small>
                    </div>
                </div>
            </div>
            <dl class="detail-list">
                <div class="detail-line"><dt>{{ __('portal.agent.organization') }}</dt><dd>{{ $organization->name_ar ?: $organization->name_en }}</dd></div>
                <div class="detail-line"><dt>{{ __('portal.agent.username') }}</dt><dd>{{ $user->username ?: '—' }}</dd></div>
                <div class="detail-line"><dt>{{ __('portal.agent.email') }}</dt><dd>{{ $user->email ?: '—' }}</dd></div>
                <div class="detail-line"><dt>{{ __('portal.agent.extension') }}</dt><dd>{{ $user->extension ?: '—' }}</dd></div>
                <div class="detail-line"><dt>{{ __('portal.agent.role') }}</dt><dd>{{ $user->role?->name ?? '—' }}</dd></div>
            </dl>
        </section>

        <section class="card">
            <div class="card-head">
                <div>
                    <h2>{{ __('portal.agent.mic_not_configured') }}</h2>
                </div>
            </div>
            <div class="detail-actions">
                <button type="button" class="btn-secondary" data-request-mic
                        data-granted="{{ __('portal.agent.mic_granted') }}"
                        data-denied="{{ __('portal.agent.mic_denied') }}"
                        data-unsupported="{{ __('portal.agent.mic_unsupported') }}">
                    {{ __('portal.agent.request_mic') }}
                </button>
                <button type="button" class="btn-secondary" data-request-notifications
                        data-granted="{{ __('portal.agent.notifications_granted') }}"
                        data-denied="{{ __('portal.agent.notifications_denied') }}">
                    {{ __('portal.agent.enable_notifications') }}
                </button>
            </div>
        </section>
    </div>
</div>
@endsection

@push('styles')
<style>
    input.switch { appearance: none; -webkit-appearance: none; border: 0; cursor: pointer; margin: 0; }
</style>
@endpush

@push('scripts')
<script>
(function () {
    'use strict';
    document.querySelectorAll('input.switch[role="switch"]').forEach(function (input) {
        var sync = function () { input.setAttribute('aria-checked', input.checked ? 'true' : 'false'); };
        sync();
        input.addEventListener('change', sync);
    });
})();
</script>
@endpush
