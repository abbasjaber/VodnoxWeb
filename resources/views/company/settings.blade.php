@extends('layouts.company')

@section('title', __('company.settings_title'))
@section('crumb', __('company.settings_title'))

@php
    $timezones = ['Asia/Baghdad', 'Asia/Riyadh', 'Asia/Dubai', 'Asia/Amman', 'Asia/Beirut', 'Asia/Kuwait', 'Africa/Cairo'];
    if ($org->timezone && ! in_array($org->timezone, $timezones, true)) {
        $timezones[] = $org->timezone;
    }
@endphp

@section('content')
@include('company._assets')
@include('company._alerts')

<div class="page-head">
    <div>
        <h1>{{ __('company.settings_title') }}</h1>
        <p>{{ __('company.settings_subtitle') }}</p>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <div><h2>{{ __('company.org_profile') }}</h2><p>{{ $org->code }}</p></div>
    </div>
    <form method="POST" action="{{ route('company.settings.update') }}">
        @csrf
        @method('PUT')
        <div class="form-grid">
            <div class="form-field"><label for="st-name-ar">{{ __('company.name_ar') }}</label><input id="st-name-ar" type="text" name="name_ar" value="{{ old('name_ar', $org->name_ar) }}" required></div>
            <div class="form-field"><label for="st-name-en">{{ __('company.name_en') }}</label><input id="st-name-en" type="text" name="name_en" value="{{ old('name_en', $org->name_en) }}" required></div>
            <div class="form-field"><label for="st-contact">{{ __('company.contact_name') }}</label><input id="st-contact" type="text" name="contact_name" value="{{ old('contact_name', $org->contact_name) }}"></div>
            <div class="form-field"><label for="st-email">{{ __('company.email') }}</label><input id="st-email" type="email" name="email" value="{{ old('email', $org->email) }}"></div>
            <div class="form-field"><label for="st-phone">{{ __('company.phone') }}</label><input id="st-phone" type="text" name="phone" value="{{ old('phone', $org->phone) }}"></div>
            <div class="form-field"><label for="st-city">{{ __('company.city') }}</label><input id="st-city" type="text" name="city" value="{{ old('city', $org->city) }}"></div>
            <div class="form-field"><label for="st-timezone">{{ __('company.timezone') }}</label>
                <select id="st-timezone" name="timezone" required>
                    @foreach ($timezones as $timezone)
                        <option value="{{ $timezone }}" @selected(old('timezone', $org->timezone) === $timezone)>{{ $timezone }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-field"><label for="st-language">{{ __('company.language') }}</label>
                <select id="st-language" name="language" required>
                    <option value="ar" @selected(old('language', $org->language) === 'ar')>{{ __('company.arabic') }}</option>
                    <option value="en" @selected(old('language', $org->language) === 'en')>{{ __('company.english') }}</option>
                </select>
            </div>
            <div class="form-field full">
                <button type="submit" class="btn-primary">{{ __('company.save') }}</button>
            </div>
        </div>
    </form>
</div>
@endsection
