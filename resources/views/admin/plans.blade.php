@extends('layouts.admin')

@section('title', __('admin.plan_title'))
@section('crumb', __('admin.plan_title'))

@php
    $limitKeys = $limitKeys ?? ['users', 'extensions', 'concurrent_calls', 'recording_storage', 'phone_numbers'];
@endphp

@section('content')
@include('admin.partials.flash')

<div class="page-head">
    <div>
        <h1>{{ __('admin.plan_title') }}</h1>
        <p>{{ __('admin.plan_subtitle') }}</p>
    </div>
    <div class="head-actions">
        <button type="button" class="btn-primary" data-admin-create
            data-admin-title="{{ __('admin.plan_new') }}"
            data-admin-action="{{ route('admin.plans.store') }}"
            data-admin-method="POST"
            data-admin-template="#tplPlanCreate">{{ __('admin.plan_new') }}</button>
    </div>
</div>

<div class="plan-grid">
    @forelse ($plans as $plan)
        @php
            $values = [
                'name_ar' => $plan->name_ar,
                'name_en' => $plan->name_en,
                'description_ar' => $plan->description_ar,
                'description_en' => $plan->description_en,
                'monthly_price' => $plan->monthly_price,
                'yearly_price' => $plan->yearly_price,
                'currency' => $plan->currency,
                'is_active' => (bool) $plan->is_active,
                'sort_order' => $plan->sort_order,
            ];
            foreach ($limitKeys as $key) {
                $values['limits['.$key.']'] = $plan->limit($key);
            }
        @endphp
        <div class="plan-card {{ $plan->is_active ? 'featured' : '' }}">
            <span class="status {{ $plan->is_active ? 'active' : 'inactive' }}">{{ $plan->is_active ? __('admin.active') : __('admin.inactive') }}</span>
            <h2>{{ $plan->name_ar ?? $plan->name_en }}</h2>
            <p>{{ $plan->description_ar ?? $plan->description_en ?? '—' }}</p>
            <div class="price">{{ number_format((float) $plan->monthly_price, 2) }} <small>{{ $plan->currency }} / @lang('admin.cycle_monthly')</small></div>
            <ul>
                @foreach ($limitKeys as $key)
                    <li><span>@lang('admin.limit_'.$key)</span><b class="number">{{ $plan->limit($key) ?? '∞' }}</b></li>
                @endforeach
            </ul>
            <div class="detail-actions" style="padding:0;justify-content:space-between">
                <button type="button" class="btn-secondary" data-admin-edit
                    data-admin-title="{{ __('admin.plan_edit') }}"
                    data-admin-action="{{ route('admin.plans.update', $plan) }}"
                    data-admin-method="PUT"
                    data-admin-template="#tplPlanEdit"
                    data-admin-values="{{ json_encode($values) }}">{{ __('admin.edit') }}</button>
                <form method="POST" action="{{ route('admin.plans.destroy', $plan) }}" data-admin-confirm="{{ __('admin.confirm_delete') }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="row-link" style="color:#c55b61">{{ __('admin.delete') }}</button>
                </form>
            </div>
        </div>
    @empty
        <div class="card"><div class="empty"><p>{{ __('admin.no_data') }}</p></div></div>
    @endforelse
</div>

@include('admin.partials.dialog')

<template id="tplPlanCreate">
    <div class="form-grid">
        <div class="form-field"><label>{{ __('admin.plan_name_ar') }} <span class="required">*</span></label><input type="text" name="name_ar" required></div>
        <div class="form-field"><label>{{ __('admin.plan_name_en') }} <span class="required">*</span></label><input type="text" name="name_en" required></div>
        <div class="form-field full"><label>{{ __('admin.plan_desc_ar') }}</label><textarea name="description_ar"></textarea></div>
        <div class="form-field full"><label>{{ __('admin.plan_desc_en') }}</label><textarea name="description_en"></textarea></div>
        <div class="form-field"><label>{{ __('admin.plan_monthly') }}</label><input type="number" step="0.01" min="0" name="monthly_price"></div>
        <div class="form-field"><label>{{ __('admin.plan_yearly') }}</label><input type="number" step="0.01" min="0" name="yearly_price"></div>
        <div class="form-field"><label>{{ __('admin.plan_currency') }}</label><input type="text" name="currency" value="USD"></div>
        <div class="form-field"><label>{{ __('admin.plan_sort') }}</label><input type="number" min="0" name="sort_order" value="0"></div>
        <div class="form-field full"><label>{{ __('admin.plan_limits') }}</label></div>
        @foreach ($limitKeys as $key)
            <div class="form-field"><label>@lang('admin.limit_'.$key)</label><input type="number" min="0" name="limits[{{ $key }}]"></div>
        @endforeach
        <div class="form-field full">
            <label>{{ __('admin.active') }}</label>
            <input type="checkbox" name="is_active" value="1" checked style="width:auto;min-height:auto">
        </div>
    </div>
</template>

<template id="tplPlanEdit">
    <div class="form-grid">
        <div class="form-field"><label>{{ __('admin.plan_name_ar') }} <span class="required">*</span></label><input type="text" name="name_ar" required></div>
        <div class="form-field"><label>{{ __('admin.plan_name_en') }} <span class="required">*</span></label><input type="text" name="name_en" required></div>
        <div class="form-field full"><label>{{ __('admin.plan_desc_ar') }}</label><textarea name="description_ar"></textarea></div>
        <div class="form-field full"><label>{{ __('admin.plan_desc_en') }}</label><textarea name="description_en"></textarea></div>
        <div class="form-field"><label>{{ __('admin.plan_monthly') }}</label><input type="number" step="0.01" min="0" name="monthly_price"></div>
        <div class="form-field"><label>{{ __('admin.plan_yearly') }}</label><input type="number" step="0.01" min="0" name="yearly_price"></div>
        <div class="form-field"><label>{{ __('admin.plan_currency') }}</label><input type="text" name="currency"></div>
        <div class="form-field"><label>{{ __('admin.plan_sort') }}</label><input type="number" min="0" name="sort_order"></div>
        <div class="form-field full"><label>{{ __('admin.plan_limits') }}</label></div>
        @foreach ($limitKeys as $key)
            <div class="form-field"><label>@lang('admin.limit_'.$key)</label><input type="number" min="0" name="limits[{{ $key }}]"></div>
        @endforeach
        <div class="form-field full">
            <label>{{ __('admin.active') }}</label>
            <input type="checkbox" name="is_active" value="1" style="width:auto;min-height:auto">
        </div>
    </div>
</template>

@push('scripts')
@include('admin.partials.scripts')
@endpush
@endsection
