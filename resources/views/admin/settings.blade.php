@extends('layouts.admin')

@section('title', __('admin.set_title'))
@section('crumb', __('admin.set_title'))

@php
    $v = fn ($key, $default = '') => old('settings.'.$key, $values[$key] ?? $default);
@endphp

@section('content')
@include('admin.partials.flash')

<div class="page-head">
    <div>
        <h1>{{ __('admin.set_title') }}</h1>
        <p>{{ __('admin.set_subtitle') }}</p>
    </div>
</div>

<form method="POST" action="{{ route('admin.settings.update') }}">
    @csrf
    @method('PUT')

    <div class="settings-layout">
        <div class="card">
            <div class="card-head"><div><h2>{{ __('admin.set_group_platform') }}</h2></div></div>
            <div class="settings-body">
                <div class="form-grid">
                    <div class="form-field"><label>{{ __('admin.set_platform_name') }}</label><input type="text" name="settings[platform_name]" value="{{ $v('platform_name', 'VODNOX') }}"></div>
                    <div class="form-field"><label>{{ __('admin.set_support_email') }}</label><input type="email" name="settings[support_email]" value="{{ $v('support_email') }}"></div>
                    <div class="form-field"><label>{{ __('admin.set_default_currency') }}</label><input type="text" name="settings[default_currency]" value="{{ $v('default_currency', 'USD') }}"></div>
                    <div class="form-field">
                        <label>{{ __('admin.set_default_language') }}</label>
                        <select name="settings[default_language]">
                            <option value="ar" @selected($v('default_language', 'ar') === 'ar')>العربية</option>
                            <option value="en" @selected($v('default_language', 'ar') === 'en')>English</option>
                        </select>
                    </div>
                    <div class="form-field full"><label>{{ __('admin.set_timezone') }}</label><input type="text" name="settings[timezone]" value="{{ $v('timezone', 'Asia/Baghdad') }}"></div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-head"><div><h2>{{ __('admin.set_group_pricing') }}</h2></div></div>
            <div class="settings-body">
                <div class="form-grid">
                    <div class="form-field"><label>{{ __('admin.set_pricing_monthly') }}</label><input type="number" step="0.01" min="0" name="settings[pricing_monthly]" value="{{ $v('pricing_monthly', '0') }}"></div>
                    <div class="form-field"><label>{{ __('admin.set_pricing_yearly') }}</label><input type="number" step="0.01" min="0" name="settings[pricing_yearly]" value="{{ $v('pricing_yearly', '0') }}"></div>
                    <div class="form-field"><label>{{ __('admin.set_expiry_reminder_days') }}</label><input type="number" min="0" name="settings[expiry_reminder_days]" value="{{ $v('expiry_reminder_days', '7') }}"></div>
                    <div class="form-field"><label>{{ __('admin.set_grace_period_days') }}</label><input type="number" min="0" name="settings[grace_period_days]" value="{{ $v('grace_period_days', '3') }}"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="head-actions" style="margin-top:20px">
        <button type="submit" class="btn-primary">{{ __('admin.save') }}</button>
    </div>
</form>

@push('scripts')
@include('admin.partials.scripts')
@endpush
@endsection
