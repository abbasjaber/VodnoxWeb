@extends('layouts.company')

@section('title', __('company.dashboard'))
@section('crumb', __('company.dashboard'))

@section('content')
@include('company._assets')
@include('company._alerts')

<div class="page-head">
    <div>
        <h1>{{ __('company.dashboard') }}</h1>
        <p>{{ __('company.dashboard_subtitle') }}</p>
    </div>
    <div class="head-actions">
        <a class="btn-primary" href="{{ route('company.team.index') }}">{{ __('company.add_member') }}</a>
    </div>
</div>

<div class="stats">
    <div class="stat">
        <div class="stat-top"><span>{{ __('company.team') }}</span><span class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="9" cy="8" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3M16 5a3 3 0 0 1 0 6m2 3a5 5 0 0 1 3 4v3"/></svg></span></div>
        <strong>{{ number_format($stats['team']) }}</strong>
        <small>{{ __('company.team_note') }}</small>
    </div>
    <div class="stat">
        <div class="stat-top"><span>{{ __('company.extensions_count') }}</span><span class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="m7 3 3 5-3 3a15 15 0 0 0 6 6l3-3 5 3-1 4C10 22 2 14 3 4l4-1Z"/></svg></span></div>
        <strong>{{ number_format($stats['extensions']) }}</strong>
        <small>{{ __('company.extensions_note') }}</small>
    </div>
    <div class="stat">
        <div class="stat-top"><span>{{ __('company.departments_count') }}</span><span class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 21V5l8-3 8 3v16M2 21h20M9 21v-5h6v5"/></svg></span></div>
        <strong>{{ number_format($stats['departments']) }}</strong>
        <small>{{ __('company.queues_count') }}: {{ number_format($stats['queues']) }}</small>
    </div>
    <div class="stat">
        <div class="stat-top"><span>{{ __('company.calls_today') }}</span><span class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="9"/><path d="M12 6v6l4 2"/></svg></span></div>
        <strong>{{ number_format($stats['calls_today']) }}</strong>
        <small class="green">{{ __('company.answer_rate') }}: {{ $stats['answer_rate'] }}%</small>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <div><h2>{{ __('company.active_subscription') }}</h2></div>
        @if ($subscription)
            <a href="{{ route('company.subscription.index') }}">{{ __('company.subscription_details') }}</a>
        @endif
    </div>
    @if ($subscription)
        <dl class="detail-list">
            <div class="detail-line"><dt>{{ __('company.plan') }}</dt><dd>{{ $subscription->plan?->name_ar ?? $subscription->plan?->name_en ?? '—' }}</dd></div>
            <div class="detail-line"><dt>{{ __('company.subscription_status') }}</dt><dd><span class="status {{ $subscription->status }}">{{ __('company.status_labels.'.$subscription->status) }}</span></dd></div>
            <div class="detail-line"><dt>{{ __('company.billing_cycle') }}</dt><dd>{{ __('company.'.$subscription->billing_cycle) }}</dd></div>
            <div class="detail-line"><dt>{{ __('company.ends_at') }}</dt><dd>{{ $subscription->ends_at?->format('Y-m-d') ?? '—' }}</dd></div>
        </dl>
    @else
        <p class="section-note">{{ __('company.no_subscription') }}</p>
    @endif

    @if ($featureSubscriptions->isNotEmpty())
        <div class="mini-metrics">
            @foreach ($featureSubscriptions as $featureSubscription)
                <span class="status {{ $featureSubscription->isActive() ? 'active' : 'inactive' }}">
                    {{ $featureSubscription->feature?->name_ar ?? $featureSubscription->feature?->code }}:
                    {{ $featureSubscription->isActive() ? __('company.feature_active') : __('company.feature_inactive') }}
                </span>
            @endforeach
        </div>
    @endif
</div>

<div class="card">
    <div class="card-head">
        <div><h2>{{ __('company.recent_calls') }}</h2></div>
        <a href="{{ route('company.calls.index') }}">{{ __('company.view_all') }}</a>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>{{ __('company.caller') }}</th>
                    <th>{{ __('company.callee') }}</th>
                    <th>{{ __('company.status') }}</th>
                    <th>{{ __('company.duration') }}</th>
                    <th>{{ __('company.date') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($recentCalls as $call)
                    <tr>
                        <td><span class="number">{{ $call->caller_number }}</span></td>
                        <td><span class="number">{{ $call->callee_number }}</span></td>
                        <td><span class="status {{ $call->status }}">{{ __('company.status_labels.'.$call->status) }}</span></td>
                        <td>{{ $call->duration_seconds ? $call->duration_seconds.'s' : '—' }}</td>
                        <td>{{ $call->created_at?->format('Y-m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="empty-row">{{ __('company.no_data') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <div><h2>{{ __('company.recent_team') }}</h2></div>
        <a href="{{ route('company.team.index') }}">{{ __('company.view_all') }}</a>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>{{ __('company.name') }}</th>
                    <th>{{ __('company.username') }}</th>
                    <th>{{ __('company.role') }}</th>
                    <th>{{ __('company.status') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($teamMembers as $member)
                    <tr>
                        <td>
                            <div class="company-cell">
                                <span class="company-avatar">{{ mb_substr($member->name, 0, 1) }}</span>
                                <div><strong>{{ $member->name }}</strong><small>{{ $member->email }}</small></div>
                            </div>
                        </td>
                        <td><span class="number">{{ $member->username }}</span></td>
                        <td>{{ $member->role?->name ? __('company.role_'.$member->role->name) : '—' }}</td>
                        <td><span class="status {{ $member->active ? 'active' : 'inactive' }}">{{ $member->active ? __('company.active') : __('company.inactive') }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="empty-row">{{ __('company.no_data') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
