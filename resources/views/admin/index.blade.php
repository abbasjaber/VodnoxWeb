@extends('layouts.admin')

@section('title', __('admin.dash_title'))
@section('crumb', __('admin.dash_title'))

@section('content')
@include('admin.partials.flash')

<div class="page-head">
    <div>
        <h1>{{ __('admin.dash_title') }}</h1>
        <p>{{ __('admin.dash_welcome_sub') }}</p>
    </div>
    <div class="head-actions">
        <a class="btn-primary" href="{{ route('admin.organizations.index') }}">{{ __('admin.org_title') }}</a>
    </div>
</div>

<div class="welcome">
    <div>
        <small>VODNOX</small>
        <h2>{{ __('admin.dash_welcome') }}</h2>
        <p>{{ __('admin.dash_welcome_sub') }}</p>
    </div>
    <div class="welcome-end">
        <b>{{ number_format($stats['active_subscriptions']) }}</b>
        <span>{{ __('admin.dash_active_subs') }}</span>
    </div>
</div>

<div class="stats">
    <div class="stat">
        <div class="stat-top">
            <span>{{ __('admin.dash_orgs') }}</span>
            <span class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 7h1m6 0h1M8 11h1m6 0h1M8 15h1m6 0h1"/></svg></span>
        </div>
        <strong>{{ number_format($stats['organizations']) }}</strong>
        <small class="green">{{ number_format($stats['active_organizations']) }} {{ __('admin.dash_active_orgs') }}</small>
    </div>
    <div class="stat">
        <div class="stat-top">
            <span>{{ __('admin.dash_users') }}</span>
            <span class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="9" cy="8" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3M16 5a3 3 0 0 1 0 6m2 3a5 5 0 0 1 3 4v3"/></svg></span>
        </div>
        <strong>{{ number_format($stats['users']) }}</strong>
        <small>{{ __('admin.dash_active_subs') }}: {{ number_format($stats['active_subscriptions']) }}</small>
    </div>
    <div class="stat">
        <div class="stat-top">
            <span>{{ __('admin.dash_revenue') }}</span>
            <span class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3Z"/></svg></span>
        </div>
        <strong>{{ number_format($stats['revenue']) }}</strong>
        <small>{{ __('admin.billing') }}</small>
    </div>
    <div class="stat">
        <div class="stat-top">
            <span>{{ __('admin.dash_pending_requests') }}</span>
            <span class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span>
        </div>
        <strong>{{ number_format($stats['pending_requests']) }}</strong>
        <small class="gold">{{ __('admin.dash_unpaid_invoices') }}: {{ number_format($stats['unpaid_invoices']) }}</small>
    </div>
</div>

<div class="two-col">
    <div class="card">
        <div class="card-head">
            <div><h2>{{ __('admin.dash_recent_orgs') }}</h2></div>
            <a href="{{ route('admin.organizations.index') }}">{{ __('admin.dash_view_all') }}</a>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>{{ __('admin.name') }}</th>
                        <th>{{ __('admin.email') }}</th>
                        <th>{{ __('admin.status') }}</th>
                        <th>{{ __('admin.created_at') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($recentOrganizations as $org)
                        <tr>
                            <td>
                                <div class="company-cell">
                                    <span class="company-avatar">{{ mb_substr($org->name_ar ?? $org->name_en, 0, 1) }}</span>
                                    <div>
                                        <strong>{{ $org->name_ar ?? $org->name_en }}</strong>
                                        <small>{{ $org->code }}</small>
                                    </div>
                                </div>
                            </td>
                            <td>{{ $org->email ?: '—' }}</td>
                            <td><span class="status {{ $org->status }}">@lang('admin.st_'.$org->status)</span></td>
                            <td>{{ $org->created_at?->format('Y-m-d') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="empty-row">{{ __('admin.no_data') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-head">
            <div><h2>{{ __('admin.dash_recent_activity') }}</h2></div>
            <a href="{{ route('admin.audit.index') }}">{{ __('admin.dash_view_all') }}</a>
        </div>
        <div class="alert-list">
            @forelse ($recentActivity as $log)
                <div class="alert-row">
                    <div>
                        <h3>{{ $log->action }}</h3>
                        <p>{{ $log->user?->name ?? $log->user?->username ?? '—' }} · {{ $log->created_at?->format('Y-m-d H:i') }}</p>
                    </div>
                </div>
            @empty
                <div class="empty"><p>{{ __('admin.no_data') }}</p></div>
            @endforelse
        </div>
    </div>
</div>

@push('scripts')
@include('admin.partials.scripts')
@endpush
@endsection
