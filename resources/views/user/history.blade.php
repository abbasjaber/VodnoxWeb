@extends('layouts.user')

@section('title', __('portal.agent.history_title'))
@section('crumb', __('portal.agent.history_title'))

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

<div class="page-head">
    <div>
        <h1>{{ __('portal.agent.history_title') }}</h1>
        <p>{{ __('portal.agent.history_sub') }}</p>
    </div>
</div>

<section class="card">
    <div class="card-head">
        <div><h2>{{ __('portal.agent.my_calls') }}</h2></div>
        <span class="history-count">{{ __('portal.agent.calls_count', ['count' => $calls->total()]) }}</span>
    </div>

    <form class="history-filters" method="GET" action="{{ route('user.history.index') }}">
        <select name="direction" aria-label="{{ __('portal.agent.direction') }}">
            <option value="">{{ __('portal.agent.filter_all') }}</option>
            <option value="inbound" @selected($filters['direction'] === 'inbound')>{{ __('portal.agent.incoming') }}</option>
            <option value="outbound" @selected($filters['direction'] === 'outbound')>{{ __('portal.agent.outgoing') }}</option>
        </select>
        <select name="status" aria-label="{{ __('portal.agent.status') }}">
            <option value="">{{ __('portal.agent.filter_all') }}</option>
            <option value="answered" @selected($filters['status'] === 'answered')>{{ __('portal.agent.answered') }}</option>
            <option value="missed" @selected($filters['status'] === 'missed')>{{ __('portal.agent.missed') }}</option>
            <option value="ringing" @selected($filters['status'] === 'ringing')>{{ __('portal.agent.unanswered') }}</option>
            <option value="cancelled" @selected($filters['status'] === 'cancelled')>{{ __('portal.agent.cancelled') }}</option>
        </select>
        <input type="date" name="from" value="{{ $filters['from'] }}" aria-label="{{ __('portal.agent.from_date') }}">
        <input type="date" name="to" value="{{ $filters['to'] }}" aria-label="{{ __('portal.agent.to_date') }}">
        <button class="btn-secondary" type="submit">{{ __('portal.agent.search') }}</button>
        <a class="text-btn" href="{{ route('user.history.index') }}">{{ __('portal.agent.reset') }}</a>
    </form>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>{{ __('portal.agent.contact') }}</th>
                    <th>{{ __('portal.agent.direction') }}</th>
                    <th>{{ __('portal.agent.date') }}</th>
                    <th>{{ __('portal.agent.duration') }}</th>
                    <th>{{ __('portal.agent.status') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($calls as $call)
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
                        <td>{{ $call->direction === 'inbound' ? __('portal.agent.incoming') : __('portal.agent.outgoing') }}</td>
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
                    <tr><td colspan="5" class="empty-row">{{ __('portal.agent.no_calls') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="table-foot">
        {{ $calls->links('pagination::bootstrap-5') }}
    </div>
</section>
@endsection
