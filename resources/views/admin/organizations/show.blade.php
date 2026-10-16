@extends('layouts.admin')

@section('title', $organization->name_ar ?? $organization->name_en)
@section('crumb', $organization->name_ar ?? $organization->name_en)

@php
    $tabs = [
        'info' => 'admin.tab_info',
        'subscription' => 'admin.tab_subscription',
        'users' => 'admin.tab_users',
        'numbers' => 'admin.tab_numbers',
        'ivr' => 'admin.tab_ivr',
        'usage' => 'admin.tab_usage',
        'recordings' => 'admin.tab_recordings',
        'invoices' => 'admin.tab_invoices',
        'activity' => 'admin.tab_activity',
    ];
    $statuses = ['pending', 'active', 'trial', 'suspended', 'expired'];
    $mb = fn ($bytes) => number_format(((int) $bytes) / 1048576, 2);
@endphp

@section('content')
@include('admin.partials.flash')

<div class="account-header">
    <span class="company-avatar">{{ mb_substr($organization->name_ar ?? $organization->name_en, 0, 1) }}</span>
    <div>
        <h1>{{ $organization->name_ar ?? $organization->name_en }}</h1>
        <div class="account-meta">
            <span class="code-note">{{ $organization->code }}</span>
            <span class="status {{ $organization->status }}">@lang('admin.st_'.$organization->status)</span>
            <span>{{ $organization->contact_name ?: '—' }}</span>
            <span>{{ $organization->city ?: '—' }}</span>
        </div>
    </div>
    <div class="head-actions">
        <a class="btn-secondary" href="{{ route('admin.organizations.index') }}">{{ __('admin.back') }}</a>
        <button type="button" class="btn-primary" data-admin-edit
            data-admin-title="{{ __('admin.org_edit') }}"
            data-admin-action="{{ route('admin.organizations.update', $organization) }}"
            data-admin-method="PUT"
            data-admin-template="#tplOrgEdit"
            data-admin-values="{{ json_encode([
                'code' => $organization->code,
                'name_ar' => $organization->name_ar,
                'name_en' => $organization->name_en,
                'contact_name' => $organization->contact_name,
                'email' => $organization->email,
                'phone' => $organization->phone,
                'city' => $organization->city,
                'status' => $organization->status,
                'timezone' => $organization->timezone,
                'language' => $organization->language,
                'internal_notes' => $organization->internal_notes,
            ]) }}">{{ __('admin.org_edit') }}</button>
    </div>
</div>

<nav class="tabs">
    @foreach ($tabs as $key => $label)
        <a class="tab {{ $tab === $key ? 'active' : '' }}"
            href="{{ route('admin.organizations.show', ['organization' => $organization, 'tab' => $key]) }}">@lang($label)</a>
    @endforeach
</nav>

@if ($tab === 'info')
    <div class="details-grid">
        <div class="card">
            <div class="card-head"><div><h2>{{ __('admin.tab_info') }}</h2></div></div>
            <dl class="detail-list">
                <div class="detail-line"><dt>{{ __('admin.dl_code') }}</dt><dd class="code-note">{{ $organization->code }}</dd></div>
                <div class="detail-line"><dt>{{ __('admin.dl_contact') }}</dt><dd>{{ $organization->contact_name ?: '—' }}</dd></div>
                <div class="detail-line"><dt>{{ __('admin.dl_status') }}</dt><dd><span class="status {{ $organization->status }}">@lang('admin.st_'.$organization->status)</span></dd></div>
                <div class="detail-line"><dt>{{ __('admin.email') }}</dt><dd>{{ $organization->email ?: '—' }}</dd></div>
                <div class="detail-line"><dt>{{ __('admin.phone') }}</dt><dd>{{ $organization->phone ?: '—' }}</dd></div>
                <div class="detail-line"><dt>{{ __('admin.dl_city') }}</dt><dd>{{ $organization->city ?: '—' }}</dd></div>
                <div class="detail-line"><dt>{{ __('admin.dl_language') }}</dt><dd>{{ strtoupper($organization->language) }}</dd></div>
                <div class="detail-line"><dt>{{ __('admin.dl_timezone') }}</dt><dd>{{ $organization->timezone ?: '—' }}</dd></div>
                <div class="detail-line"><dt>{{ __('admin.dl_created') }}</dt><dd>{{ $organization->created_at?->format('Y-m-d H:i') }}</dd></div>
            </dl>
        </div>
        <div class="card">
            <div class="card-head"><div><h2>{{ __('admin.dl_notes') }}</h2></div></div>
            <div class="detail-list">
                <p style="color:#4c5d74;line-height:2">{{ $organization->internal_notes ?: __('admin.no_data') }}</p>
            </div>
        </div>
    </div>

@elseif ($tab === 'subscription')
    <div class="card" style="margin-bottom:22px">
        <div class="card-head"><div><h2>{{ __('admin.sub_title') }}</h2></div></div>
        <div class="table-wrap">
            <table>
                <thead><tr>
                    <th>{{ __('admin.sub_plan') }}</th>
                    <th>{{ __('admin.sub_cycle') }}</th>
                    <th>{{ __('admin.status') }}</th>
                    <th>{{ __('admin.sub_starts') }}</th>
                    <th>{{ __('admin.sub_ends') }}</th>
                    <th>{{ __('admin.sub_price') }}</th>
                </tr></thead>
                <tbody>
                    @forelse ($organization->subscriptions as $sub)
                        <tr>
                            <td>{{ $sub->plan?->name_ar ?? $sub->plan?->name_en ?? '—' }}</td>
                            <td>@lang('admin.cycle_'.$sub->billing_cycle)</td>
                            <td><span class="status {{ $sub->status }}">@lang('admin.st_'.$sub->status)</span></td>
                            <td>{{ $sub->starts_at?->format('Y-m-d') ?? '—' }}</td>
                            <td>{{ $sub->ends_at?->format('Y-m-d') ?? '—' }}</td>
                            <td><span class="number">{{ number_format((float) $sub->price, 2) }} {{ $sub->currency }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="empty-row">{{ __('admin.no_data') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card" style="margin-bottom:22px">
        <div class="card-head"><div><h2>{{ __('admin.feat_subscriptions') }}</h2></div></div>
        <div class="table-wrap">
            <table>
                <thead><tr>
                    <th>{{ __('admin.int_name') }}</th>
                    <th>{{ __('admin.status') }}</th>
                    <th>{{ __('admin.feat_starts') }}</th>
                    <th>{{ __('admin.feat_ends') }}</th>
                </tr></thead>
                <tbody>
                    @forelse ($organization->featureSubscriptions as $fs)
                        <tr>
                            <td>{{ $fs->feature?->name_ar ?? $fs->feature?->name_en ?? '—' }}</td>
                            <td><span class="status {{ $fs->status }}">@lang('admin.st_'.$fs->status)</span></td>
                            <td>{{ $fs->starts_at?->format('Y-m-d') ?? '—' }}</td>
                            <td>{{ $fs->ends_at?->format('Y-m-d') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="empty-row">{{ __('admin.no_data') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><div><h2>{{ __('admin.feat_requests') }}</h2></div></div>
        <div class="alert-list">
            @forelse ($activationRequests as $req)
                <div class="alert-row">
                    <div>
                        <h3>{{ $req->feature?->name_ar ?? $req->feature?->name_en ?? '—' }}</h3>
                        <p>{{ $req->notes ?: '—' }} · {{ $req->created_at?->format('Y-m-d H:i') }}</p>
                    </div>
                    <span class="status {{ $req->status }}">@lang('admin.st_'.$req->status)</span>
                </div>
            @empty
                <div class="empty"><p>{{ __('admin.feat_no_requests') }}</p></div>
            @endforelse
        </div>
    </div>

@elseif ($tab === 'users')
    <div class="card">
        <div class="card-head"><div><h2>{{ __('admin.tab_users') }}</h2></div></div>
        <div class="table-wrap">
            <table>
                <thead><tr>
                    <th>{{ __('admin.name') }}</th>
                    <th>{{ __('admin.username') }}</th>
                    <th>{{ __('admin.role') }}</th>
                    <th>{{ __('admin.email') }}</th>
                    <th>{{ __('admin.status') }}</th>
                </tr></thead>
                <tbody>
                    @forelse ($organization->users as $user)
                        <tr>
                            <td>{{ $user->name }}</td>
                            <td class="code-note">{{ $user->username }}</td>
                            <td>{{ $user->role?->label_ar ?? $user->role?->name ?? '—' }}</td>
                            <td>{{ $user->email ?: '—' }}</td>
                            <td><span class="status {{ $user->active ? 'active' : 'inactive' }}">{{ $user->active ? __('admin.active') : __('admin.inactive') }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="empty-row">{{ __('admin.no_data') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

@elseif ($tab === 'numbers')
    <div class="card">
        <div class="card-head"><div><h2>{{ __('admin.numbers_title') }}</h2></div></div>
        <div class="table-wrap">
            <table>
                <thead><tr>
                    <th>{{ __('admin.number') }}</th>
                    <th>{{ __('admin.name') }}</th>
                    <th>{{ __('admin.routing_type') }}</th>
                    <th>{{ __('admin.status') }}</th>
                </tr></thead>
                <tbody>
                    @forelse ($phoneNumbers as $number)
                        <tr>
                            <td class="number">{{ $number->number }}</td>
                            <td>{{ $number->label ?: '—' }}</td>
                            <td>{{ $number->routing_type ?: '—' }}</td>
                            <td><span class="status {{ $number->status }}">@lang('admin.st_'.$number->status)</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="empty-row">{{ __('admin.no_data') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

@elseif ($tab === 'ivr')
    <div class="card" style="margin-bottom:22px">
        <div class="card-head"><div><h2>{{ __('admin.ivr_menus') }}</h2></div></div>
        <div class="table-wrap">
            <table>
                <thead><tr>
                    <th>{{ __('admin.name') }}</th>
                    <th>{{ __('admin.status') }}</th>
                </tr></thead>
                <tbody>
                    @forelse ($ivrMenus as $menu)
                        <tr>
                            <td>{{ $menu->name_ar ?? $menu->name_en }}</td>
                            <td><span class="status {{ $menu->is_active ? 'active' : 'inactive' }}">{{ $menu->is_active ? __('admin.active') : __('admin.inactive') }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="2" class="empty-row">{{ __('admin.no_data') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><div><h2>{{ __('admin.queues') }}</h2></div></div>
        <div class="table-wrap">
            <table>
                <thead><tr>
                    <th>{{ __('admin.name') }}</th>
                    <th>{{ __('admin.status') }}</th>
                </tr></thead>
                <tbody>
                    @forelse ($callQueues as $queue)
                        <tr>
                            <td>{{ $queue->name_ar ?? $queue->name_en }}</td>
                            <td><span class="status {{ $queue->is_active ? 'active' : 'inactive' }}">{{ $queue->is_active ? __('admin.active') : __('admin.inactive') }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="2" class="empty-row">{{ __('admin.no_data') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

@elseif ($tab === 'usage')
    <div class="card">
        <div class="card-head"><div><h2>{{ __('admin.tab_usage') }}</h2></div></div>
        <div class="mini-metrics">
            <div class="mini-metric"><span>{{ __('admin.usage_users') }}</span><strong class="number">{{ number_format($usage['users']) }}</strong></div>
            <div class="mini-metric"><span>{{ __('admin.usage_extensions') }}</span><strong class="number">{{ number_format($usage['extensions']) }}</strong></div>
            <div class="mini-metric"><span>{{ __('admin.usage_calls_month') }}</span><strong class="number">{{ number_format($usage['calls_month']) }}</strong></div>
            <div class="mini-metric"><span>{{ __('admin.usage_numbers') }}</span><strong class="number">{{ number_format($usage['numbers']) }}</strong></div>
            <div class="mini-metric"><span>{{ __('admin.usage_storage') }}</span><strong class="number">{{ $mb($usage['storage']) }} {{ __('admin.usage_mb') }}</strong></div>
            <div class="mini-metric"><span>{{ __('admin.customers') }}</span><strong class="number">{{ number_format($usage['customers']) }}</strong></div>
            <div class="mini-metric"><span>{{ __('admin.forms') }}</span><strong class="number">{{ number_format($usage['forms']) }}</strong></div>
        </div>
    </div>

@elseif ($tab === 'recordings')
    <div class="card">
        <div class="card-head"><div><h2>{{ __('admin.recordings_title') }}</h2></div></div>
        <div class="table-wrap">
            <table>
                <thead><tr>
                    <th>{{ __('admin.recording_file') }}</th>
                    <th>{{ __('admin.recording_size') }}</th>
                    <th>{{ __('admin.recording_duration') }}</th>
                    <th>{{ __('admin.date') }}</th>
                </tr></thead>
                <tbody>
                    @forelse ($recordings as $recording)
                        <tr>
                            <td>{{ $recording->file_name }}</td>
                            <td class="number">{{ $mb($recording->size) }} {{ __('admin.usage_mb') }}</td>
                            <td class="number">{{ $recording->duration_seconds ?? 0 }}s</td>
                            <td>{{ $recording->created_at?->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="empty-row">{{ __('admin.no_data') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

@elseif ($tab === 'invoices')
    <div class="card">
        <div class="card-head"><div><h2>{{ __('admin.tab_invoices') }}</h2></div>
            <a href="{{ route('admin.billing.index') }}">{{ __('admin.billing_title') }}</a>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr>
                    <th>{{ __('admin.inv_number') }}</th>
                    <th>{{ __('admin.inv_amount') }}</th>
                    <th>{{ __('admin.status') }}</th>
                    <th>{{ __('admin.inv_due') }}</th>
                    <th>{{ __('admin.inv_remaining') }}</th>
                </tr></thead>
                <tbody>
                    @forelse ($organization->invoices as $invoice)
                        <tr>
                            <td class="code-note">{{ $invoice->invoice_number }}</td>
                            <td class="number">{{ number_format((float) $invoice->amount, 2) }} {{ $invoice->currency }}</td>
                            <td><span class="status {{ $invoice->status }}">@lang('admin.st_'.$invoice->status)</span></td>
                            <td>{{ $invoice->due_date?->format('Y-m-d') ?? '—' }}</td>
                            <td class="number">{{ number_format($invoice->remainingBalance(), 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="empty-row">{{ __('admin.no_data') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

@elseif ($tab === 'activity')
    <div class="card">
        <div class="card-head"><div><h2>{{ __('admin.activity_title') }}</h2></div>
            <a href="{{ route('admin.audit.index', ['organization' => $organization->id]) }}">{{ __('admin.audit_title') }}</a>
        </div>
        <div class="timeline">
            @forelse ($auditLogs as $log)
                <div class="timeline-item">
                    <h3>{{ $log->action }}</h3>
                    <p>{{ $log->user?->name ?? $log->user?->username ?? '—' }}</p>
                    <time>{{ $log->created_at?->format('Y-m-d H:i') }}</time>
                </div>
            @empty
                <div class="empty"><p>{{ __('admin.no_data') }}</p></div>
            @endforelse
        </div>
    </div>
@endif

@include('admin.partials.dialog')

<template id="tplOrgEdit">
    <div class="form-grid">
        <div class="form-field"><label>{{ __('admin.org_code') }} <span class="required">*</span></label><input type="text" name="code" required></div>
        <div class="form-field"><label>{{ __('admin.name_ar') }} <span class="required">*</span></label><input type="text" name="name_ar" required></div>
        <div class="form-field"><label>{{ __('admin.name_en') }} <span class="required">*</span></label><input type="text" name="name_en" required></div>
        <div class="form-field"><label>{{ __('admin.contact_name') }}</label><input type="text" name="contact_name"></div>
        <div class="form-field"><label>{{ __('admin.email') }}</label><input type="email" name="email"></div>
        <div class="form-field"><label>{{ __('admin.phone') }}</label><input type="text" name="phone"></div>
        <div class="form-field"><label>{{ __('admin.city') }}</label><input type="text" name="city"></div>
        <div class="form-field">
            <label>{{ __('admin.status') }}</label>
            <select name="status">
                @foreach ($statuses as $s)
                    <option value="{{ $s }}">@lang('admin.st_'.$s)</option>
                @endforeach
            </select>
        </div>
        <div class="form-field"><label>{{ __('admin.timezone') }}</label><input type="text" name="timezone"></div>
        <div class="form-field">
            <label>{{ __('admin.language') }}</label>
            <select name="language">
                <option value="ar">العربية</option>
                <option value="en">English</option>
            </select>
        </div>
        <div class="form-field full"><label>{{ __('admin.internal_notes') }}</label><textarea name="internal_notes"></textarea></div>
    </div>
</template>

@push('scripts')
@include('admin.partials.scripts')
@endpush
@endsection
