@extends('layouts.admin')

@section('title', __('admin.sub_title'))
@section('crumb', __('admin.sub_title'))

@php
    $statuses = ['pending', 'active', 'trial', 'suspended', 'expired'];
    $cycles = ['monthly', 'yearly'];
@endphp

@section('content')
@include('admin.partials.flash')

<div class="page-head">
    <div>
        <h1>{{ __('admin.sub_title') }}</h1>
        <p>{{ __('admin.sub_subtitle') }}</p>
    </div>
    <div class="head-actions">
        <button type="button" class="btn-primary" data-admin-create
            data-admin-title="{{ __('admin.sub_new') }}"
            data-admin-action="{{ route('admin.subscriptions.store') }}"
            data-admin-method="POST"
            data-admin-template="#tplSubCreate">{{ __('admin.sub_new') }}</button>
    </div>
</div>

<div class="card">
    <div class="card-head"><div><h2>{{ __('admin.sub_title') }}</h2></div></div>

    <form class="filters" method="GET" action="{{ route('admin.subscriptions.index') }}">
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
        <a class="btn-quiet" href="{{ route('admin.subscriptions.index') }}">{{ __('admin.reset') }}</a>
    </form>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>{{ __('admin.sub_organization') }}</th>
                    <th>{{ __('admin.sub_plan') }}</th>
                    <th>{{ __('admin.sub_cycle') }}</th>
                    <th>{{ __('admin.status') }}</th>
                    <th>{{ __('admin.sub_starts') }}</th>
                    <th>{{ __('admin.sub_ends') }}</th>
                    <th>{{ __('admin.sub_price') }}</th>
                    <th>{{ __('admin.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($subscriptions as $subscription)
                    <tr>
                        <td>{{ $subscription->organization?->name_ar ?? $subscription->organization?->name_en ?? '—' }}</td>
                        <td>{{ $subscription->plan?->name_ar ?? $subscription->plan?->name_en ?? '—' }}</td>
                        <td>@lang('admin.cycle_'.$subscription->billing_cycle)</td>
                        <td><span class="status {{ $subscription->status }}">@lang('admin.st_'.$subscription->status)</span></td>
                        <td>{{ $subscription->starts_at?->format('Y-m-d') ?? '—' }}</td>
                        <td>{{ $subscription->ends_at?->format('Y-m-d') ?? '—' }}</td>
                        <td><span class="number">{{ number_format((float) $subscription->price, 2) }} {{ $subscription->currency }}</span></td>
                        <td>
                            <div class="detail-actions" style="padding:0">
                                <button type="button" class="row-link" data-admin-edit
                                    data-admin-title="{{ __('admin.sub_edit') }}"
                                    data-admin-action="{{ route('admin.subscriptions.update', $subscription) }}"
                                    data-admin-method="PUT"
                                    data-admin-template="#tplSubEdit"
                                    data-admin-values="{{ json_encode([
                                        'organization_id' => $subscription->organization_id,
                                        'plan_id' => $subscription->plan_id,
                                        'status' => $subscription->status,
                                        'billing_cycle' => $subscription->billing_cycle,
                                        'starts_at' => $subscription->starts_at?->format('Y-m-d'),
                                        'ends_at' => $subscription->ends_at?->format('Y-m-d'),
                                        'price' => $subscription->price,
                                        'currency' => $subscription->currency,
                                    ]) }}">{{ __('admin.edit') }}</button>
                                <form method="POST" action="{{ route('admin.subscriptions.destroy', $subscription) }}" data-admin-confirm="{{ __('admin.confirm_delete') }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="row-link" style="color:#c55b61">{{ __('admin.delete') }}</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="empty-row">{{ __('admin.no_data') }}</td></tr>
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

<template id="tplSubCreate">
    <div class="form-grid">
        <div class="form-field">
            <label>{{ __('admin.sub_organization') }} <span class="required">*</span></label>
            <select name="organization_id" required>
                @foreach ($organizations as $org)
                    <option value="{{ $org->id }}">{{ $org->name_ar ?? $org->name_en }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-field">
            <label>{{ __('admin.sub_plan') }}</label>
            <select name="plan_id">
                <option value="">—</option>
                @foreach ($plans as $plan)
                    <option value="{{ $plan->id }}">{{ $plan->name_ar ?? $plan->name_en }}</option>
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
        <div class="form-field">
            <label>{{ __('admin.sub_cycle') }}</label>
            <select name="billing_cycle">
                @foreach ($cycles as $c)
                    <option value="{{ $c }}">@lang('admin.cycle_'.$c)</option>
                @endforeach
            </select>
        </div>
        <div class="form-field"><label>{{ __('admin.sub_starts') }}</label><input type="date" name="starts_at"></div>
        <div class="form-field"><label>{{ __('admin.sub_ends') }}</label><input type="date" name="ends_at"></div>
        <div class="form-field"><label>{{ __('admin.sub_price') }}</label><input type="number" step="0.01" min="0" name="price"></div>
        <div class="form-field"><label>{{ __('admin.currency') }}</label><input type="text" name="currency" value="USD"></div>
    </div>
</template>

<template id="tplSubEdit">
    <div class="form-grid">
        <div class="form-field">
            <label>{{ __('admin.sub_organization') }} <span class="required">*</span></label>
            <select name="organization_id" required>
                @foreach ($organizations as $org)
                    <option value="{{ $org->id }}">{{ $org->name_ar ?? $org->name_en }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-field">
            <label>{{ __('admin.sub_plan') }}</label>
            <select name="plan_id">
                <option value="">—</option>
                @foreach ($plans as $plan)
                    <option value="{{ $plan->id }}">{{ $plan->name_ar ?? $plan->name_en }}</option>
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
        <div class="form-field">
            <label>{{ __('admin.sub_cycle') }}</label>
            <select name="billing_cycle">
                @foreach ($cycles as $c)
                    <option value="{{ $c }}">@lang('admin.cycle_'.$c)</option>
                @endforeach
            </select>
        </div>
        <div class="form-field"><label>{{ __('admin.sub_starts') }}</label><input type="date" name="starts_at"></div>
        <div class="form-field"><label>{{ __('admin.sub_ends') }}</label><input type="date" name="ends_at"></div>
        <div class="form-field"><label>{{ __('admin.sub_price') }}</label><input type="number" step="0.01" min="0" name="price"></div>
        <div class="form-field"><label>{{ __('admin.currency') }}</label><input type="text" name="currency"></div>
    </div>
</template>

@push('scripts')
@include('admin.partials.scripts')
@endpush
@endsection
