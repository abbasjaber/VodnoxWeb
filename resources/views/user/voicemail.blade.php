@extends('layouts.user')

@section('title', __('portal.agent.voicemail_title'))
@section('crumb', __('portal.agent.voicemail_title'))

@php
    $clock = fn (?int $seconds) => sprintf('%02d:%02d', intdiv((int) $seconds, 60), (int) $seconds % 60);
    $baghdad = fn ($moment, string $format) => $moment ? $moment->timezone(config('app.timezone'))->format($format) : '—';
@endphp

@section('content')
@include('user._workspace')

<div class="page-head">
    <div>
        <h1>{{ __('portal.agent.voicemail_title') }}</h1>
        <p>{{ __('portal.agent.voicemail_sub') }}</p>
    </div>
    <span class="status draft">{{ __('portal.agent.service_not_connected') }}</span>
</div>

<section class="card">
    <div class="card-head">
        <div><h2>{{ __('portal.agent.voicemail_title') }}</h2></div>
        <span class="history-count">{{ $voicemails->total() }}</span>
    </div>

    @if ($voicemails->isEmpty())
        <div class="voicemail-empty">
            <div>
                <h3>{{ __('portal.agent.voicemail_none') }}</h3>
                <p>{{ __('portal.agent.voicemail_none_text') }}</p>
            </div>
        </div>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>{{ __('portal.agent.caller') }}</th>
                        <th>{{ __('portal.agent.received') }}</th>
                        <th>{{ __('portal.agent.duration') }}</th>
                        <th>{{ __('portal.agent.status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($voicemails as $voicemail)
                        <tr>
                            <td><strong>{{ $voicemail->caller_number ?: __('portal.agent.unknown_number') }}</strong></td>
                            <td><span class="number">{{ $baghdad($voicemail->created_at, 'Y-m-d H:i') }}</span></td>
                            <td><span class="number">{{ $clock($voicemail->duration_seconds) }}</span></td>
                            <td>
                                <span class="status {{ $voicemail->is_read ? 'draft' : 'active' }}">
                                    {{ $voicemail->is_read ? __('portal.agent.read') : __('portal.agent.unread') }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="help-note">{{ __('portal.agent.playback_unavailable') }}</p>

        <div class="table-foot">
            {{ $voicemails->links('pagination::bootstrap-5') }}
        </div>
    @endif
</section>
@endsection
