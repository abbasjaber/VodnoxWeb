@extends('layouts.public')

@section('title', 'VODNOX | ' . __('vodnox.contact'))
@section('meta_description', __('portal.meta.contact'))
@section('site_page', 'contact')

@section('content')
@php
    $topicIcons = [
        'queue' => '<circle cx="5" cy="5" r="2"/><circle cx="5" cy="12" r="2"/><circle cx="5" cy="19" r="2"/><path d="M10 5h11m-11 7h11m-11 7h11"/>',
        'flow' => '<rect x="8" y="2" width="8" height="6" rx="1"/><rect x="2" y="16" width="7" height="6" rx="1"/><rect x="15" y="16" width="7" height="6" rx="1"/><path d="M12 8v4H5v4m7-4h7v4"/>',
        'plug' => '<path d="M8 3v5m8-5v5M6 8h12v3a6 6 0 0 1-12 0V8Zm6 9v4"/>',
    ];
@endphp

<section class="inner-hero">
    <div class="site-shell">
        <div class="section-label">{{ __('portal.public.contact_label') }}</div>
        <h1>{!! __('portal.public.contact_hero_title') !!}</h1>
        <p>{{ __('portal.public.contact_hero_text') }}</p>
    </div>
</section>

<section class="site-section site-shell">
    <div class="contact-layout">
        <div class="contact-copy">
            <div class="section-label">{{ __('portal.public.label_find_connection') }}</div>
            <h2>{!! __('portal.public.contact_heading') !!}</h2>
            <p>{{ __('portal.public.contact_text') }}</p>
            <div class="contact-topics">
                @foreach (['queue', 'flow', 'plug'] as $index => $topicIcon)
                    <div class="contact-topic">
                        <span>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $topicIcons[$topicIcon] !!}</svg>
                        </span>
                        <div>
                            <b>{{ __('portal.public.topic_'.($index + 1).'_title') }}</b>
                            <small>{{ __('portal.public.topic_'.($index + 1).'_text') }}</small>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <form id="inquiryForm" class="contact-form" method="POST" action="{{ route('contact.store') }}">
            @csrf
            <h2>{{ __('portal.public.contact_form_title') }}</h2>

            @if (session('contact_success'))
                <div class="inquiry-result" role="status">{{ session('contact_success') }}</div>
            @endif

            @if ($errors->any())
                <div class="form-note" role="alert">
                    {{ __('portal.public.contact_error') }}
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="contact-fields">
                <div class="site-field">
                    <label for="inquiry-name">{{ __('portal.public.field_name') }} *</label>
                    <input id="inquiry-name" name="name" type="text" value="{{ old('name') }}" required maxlength="255" autocomplete="name">
                    @error('name')<small class="field-error">{{ $message }}</small>@enderror
                </div>
                <div class="site-field">
                    <label for="inquiry-company">{{ __('portal.public.field_company') }}</label>
                    <input id="inquiry-company" name="company" type="text" value="{{ old('company') }}" maxlength="255" autocomplete="organization">
                    @error('company')<small class="field-error">{{ $message }}</small>@enderror
                </div>
                <div class="site-field">
                    <label for="inquiry-email">{{ __('portal.public.field_email') }}</label>
                    <input id="inquiry-email" name="email" type="email" value="{{ old('email') }}" maxlength="255" autocomplete="email">
                    @error('email')<small class="field-error">{{ $message }}</small>@enderror
                </div>
                <div class="site-field">
                    <label for="inquiry-phone">{{ __('portal.public.field_phone') }}</label>
                    <input id="inquiry-phone" name="phone" type="tel" value="{{ old('phone') }}" maxlength="32" autocomplete="tel">
                    @error('phone')<small class="field-error">{{ $message }}</small>@enderror
                </div>
                <div class="site-field full">
                    <label for="inquiry-message">{{ __('portal.public.field_message') }} *</label>
                    <textarea id="inquiry-message" name="message" required maxlength="5000">{{ old('message') }}</textarea>
                    @error('message')<small class="field-error">{{ $message }}</small>@enderror
                </div>
            </div>

            <p class="form-note">{{ __('portal.public.contact_privacy') }}</p>
            <button type="submit" class="site-btn orange">{{ __('portal.public.contact_submit') }}</button>
        </form>
    </div>
</section>
@endsection
