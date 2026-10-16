@extends('layouts.admin')

@section('title', __('admin.feat_title_'.$code))
@section('crumb', __('admin.feat_title_'.$code))

@php
    $statuses = ['inactive', 'active', 'suspended'];
@endphp

@section('content')
@include('admin.partials.flash')

<div class="page-head">
    <div>
        <h1>{{ __('admin.feat_title_'.$code) }}</h1>
        <p>{{ __('admin.feat_subtitle_'.$code) }}</p>
    </div>
    <div class="head-actions">
        <button type="button" class="btn-primary" data-admin-create
            data-admin-title="{{ __('admin.feat_new') }}"
            data-admin-action="{{ route('admin.features.store', ['featureCode' => $code]) }}"
            data-admin-method="POST"
            data-admin-template="#tplFeatureCreate">{{ __('admin.feat_new') }}</button>
    </div>
</div>

<div class="card" style="margin-bottom:22px">
    <div class="card-head"><div><h2>{{ __('admin.feat_requests') }}</h2></div></div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>{{ __('admin.feat_request_org') }}</th>
                    <th>{{ __('admin.feat_request_notes') }}</th>
                    <th>{{ __('admin.date') }}</th>
                    <th>{{ __('admin.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($activationRequests as $request)
                    <tr>
                        <td>{{ $request->organization?->name_ar ?? $request->organization?->name_en ?? '—' }}</td>
                        <td>{{ $request->notes ?: '—' }}</td>
                        <td>{{ $request->created_at?->format('Y-m-d H:i') }}</td>
                        <td>
                            <form method="POST" action="{{ route('admin.activation-requests.decide', $request) }}">
                                @csrf
                                @method('PATCH')
                                <div class="detail-actions" style="padding:0">
                                    <select name="decision" style="width:auto">
                                        <option value="approve">{{ __('admin.feat_approve') }}</option>
                                        <option value="reject">{{ __('admin.feat_reject') }}</option>
                                    </select>
                                    <input type="text" name="notes" placeholder="{{ __('admin.feat_decide_notes') }}" style="width:auto">
                                    <button type="submit" class="row-link">{{ __('admin.confirm') }}</button>
                                </div>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="empty-row">{{ __('admin.feat_no_requests') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-head"><div><h2>{{ __('admin.feat_subscriptions') }}</h2></div></div>

    <form class="filters" method="GET" action="{{ url()->current() }}">
        <label class="search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input type="search" name="q" value="{{ $q }}" placeholder="{{ __('admin.search_placeholder') }}">
        </label>
        <select name="status">
            <option value="">{{ __('admin.all') }}</option>
            @foreach ($statuses as $s)
                <option value="{{ $s }}" @selected($status === $s)>@lang('admin.st_'.$s)</option>
            @endforeach
        </select>
        <button type="submit" class="btn-secondary">{{ __('admin.filter') }}</button>
        <a class="btn-quiet" href="{{ url()->current() }}">{{ __('admin.reset') }}</a>
    </form>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>{{ __('admin.feat_organization') }}</th>
                    <th>{{ __('admin.status') }}</th>
                    <th>{{ __('admin.feat_starts') }}</th>
                    <th>{{ __('admin.feat_ends') }}</th>
                    <th>{{ __('admin.feat_price') }}</th>
                    <th>{{ __('admin.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($subscriptions as $subscription)
                    <tr>
                        <td>{{ $subscription->organization?->name_ar ?? $subscription->organization?->name_en ?? '—' }}</td>
                        <td><span class="status {{ $subscription->status }}">@lang('admin.st_'.$subscription->status)</span></td>
                        <td>{{ $subscription->starts_at?->format('Y-m-d') ?? '—' }}</td>
                        <td>{{ $subscription->ends_at?->format('Y-m-d') ?? '—' }}</td>
                        <td class="number">{{ number_format((float) $subscription->price, 2) }} {{ $subscription->currency }}</td>
                        <td>
                            <div class="detail-actions" style="padding:0">
                                <button type="button" class="row-link" data-admin-edit
                                    data-admin-title="{{ __('admin.feat_edit') }}"
                                    data-admin-action="{{ route('admin.features.update', $subscription) }}"
                                    data-admin-method="PUT"
                                    data-admin-template="#tplFeatureEdit"
                                    data-admin-values="{{ json_encode([
                                        'organization_id' => $subscription->organization_id,
                                        'status' => $subscription->status,
                                        'starts_at' => $subscription->starts_at?->format('Y-m-d'),
                                        'ends_at' => $subscription->ends_at?->format('Y-m-d'),
                                        'max_users' => $subscription->max_users,
                                        'max_customers' => $subscription->max_customers,
                                        'max_forms' => $subscription->max_forms,
                                        'max_submissions' => $subscription->max_submissions,
                                        'price' => $subscription->price,
                                        'currency' => $subscription->currency,
                                    ]) }}">{{ __('admin.edit') }}</button>
                                <form method="POST" action="{{ route('admin.features.destroy', $subscription) }}" data-admin-confirm="{{ __('admin.confirm_delete') }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="row-link" style="color:#c55b61">{{ __('admin.delete') }}</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="empty-row">{{ __('admin.no_data') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="table-foot">
        <span>{{ $subscriptions->total() }}</span>
        <span>{{ $subscriptions->links('pagination::bootstrap-5') }}</span>
    </div>
</div>

@include('admin.partials.dialog')

<template id="tplFeatureCreate">
    <div class="form-grid">
        <div class="form-field">
            <label>{{ __('admin.feat_organization') }} <span class="required">*</span></label>
            <select name="organization_id" required>
                @foreach ($organizations as $org)
                    <option value="{{ $org->id }}">{{ $org->name_ar ?? $org->name_en }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-field">
            <label>{{ __('admin.status') }}</label>
            <select name="status">
                @foreach ($statuses as $s)
                    <option value="{{ $s }}">@lang('admin.st_'.$s)</option>
                @endforeach
            </select>
        </div>
        <div class="form-field"><label>{{ __('admin.feat_starts') }}</label><input type="date" name="starts_at"></div>
        <div class="form-field"><label>{{ __('admin.feat_ends') }}</label><input type="date" name="ends_at"></div>
        <div class="form-field"><label>{{ __('admin.feat_max_users') }}</label><input type="number" min="0" name="max_users"></div>
        <div class="form-field"><label>{{ __('admin.feat_max_customers') }}</label><input type="number" min="0" name="max_customers"></div>
        <div class="form-field"><label>{{ __('admin.feat_max_forms') }}</label><input type="number" min="0" name="max_forms"></div>
        <div class="form-field"><label>{{ __('admin.feat_max_submissions') }}</label><input type="number" min="0" name="max_submissions"></div>
        <div class="form-field"><label>{{ __('admin.feat_price') }}</label><input type="number" step="0.01" min="0" name="price"></div>
        <div class="form-field"><label>{{ __('admin.currency') }}</label><input type="text" name="currency" value="USD"></div>
    </div>
</template>

<template id="tplFeatureEdit">
    <div class="form-grid">
        <div class="form-field">
            <label>{{ __('admin.feat_organization') }} <span class="required">*</span></label>
            <select name="organization_id" required>
                @foreach ($organizations as $org)
                    <option value="{{ $org->id }}">{{ $org->name_ar ?? $org->name_en }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-field">
            <label>{{ __('admin.status') }}</label>
            <select name="status">
                @foreach ($statuses as $s)
                    <option value="{{ $s }}">@lang('admin.st_'.$s)</option>
                @endforeach
            </select>
        </div>
        <div class="form-field"><label>{{ __('admin.feat_starts') }}</label><input type="date" name="starts_at"></div>
        <div class="form-field"><label>{{ __('admin.feat_ends') }}</label><input type="date" name="ends_at"></div>
        <div class="form-field"><label>{{ __('admin.feat_max_users') }}</label><input type="number" min="0" name="max_users"></div>
        <div class="form-field"><label>{{ __('admin.feat_max_customers') }}</label><input type="number" min="0" name="max_customers"></div>
        <div class="form-field"><label>{{ __('admin.feat_max_forms') }}</label><input type="number" min="0" name="max_forms"></div>
        <div class="form-field"><label>{{ __('admin.feat_max_submissions') }}</label><input type="number" min="0" name="max_submissions"></div>
        <div class="form-field"><label>{{ __('admin.feat_price') }}</label><input type="number" step="0.01" min="0" name="price"></div>
        <div class="form-field"><label>{{ __('admin.currency') }}</label><input type="text" name="currency"></div>
    </div>
</template>

@push('scripts')
@include('admin.partials.scripts')
@endpush
@endsection
