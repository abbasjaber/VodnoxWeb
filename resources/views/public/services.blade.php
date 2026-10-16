@extends('layouts.public')

@section('title', 'VODNOX | ' . __('vodnox.services'))
@section('meta_description', __('portal.meta.services'))
@section('site_page', 'services')

@section('content')
@php
    $shapes = [
        'cloud' => '<path d="M7 18a5 5 0 1 1 1-10 6 6 0 0 1 11 3 4 4 0 0 1-1 8H7Zm5-8v11m-3-3 3 3 3-3"/>',
        'flow' => '<rect x="8" y="2" width="8" height="6" rx="1"/><rect x="2" y="16" width="7" height="6" rx="1"/><rect x="15" y="16" width="7" height="6" rx="1"/><path d="M12 8v4H5v4m7-4h7v4"/>',
        'queue' => '<circle cx="5" cy="5" r="2"/><circle cx="5" cy="12" r="2"/><circle cx="5" cy="19" r="2"/><path d="M10 5h11m-11 7h11m-11 7h11"/>',
        'desk' => '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 22h8m-4-5v5M7 8h10m-10 4h6"/>',
        'chart' => '<path d="M4 20V10m5 10V4m6 16v-7m5 7V7M2 21h20"/>',
        'mobile' => '<rect x="6" y="2" width="12" height="20" rx="3"/><path d="M10 5h4m-3 14h2"/>',
    ];
    $order = ['cloud', 'flow', 'queue', 'desk', 'chart', 'mobile'];
@endphp

<section class="inner-hero">
    <div class="site-shell">
        <div class="section-label">{{ __('portal.public.services_label') }}</div>
        <h1>{!! __('portal.public.services_hero_title') !!}</h1>
        <p>{{ __('portal.public.services_hero_text') }}</p>
    </div>
</section>

<section class="site-section site-shell">
    <div class="service-grid">
        @foreach ($order as $index => $shape)
            <article class="service-card">
                <span class="service-number">0{{ $index + 1 }}</span>
                <div class="service-symbol">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $shapes[$shape] !!}</svg>
                </div>
                <h3>{{ __('portal.public.service_'.($index + 1).'_title') }}</h3>
                <p>{{ __('portal.public.service_'.($index + 1).'_text') }}</p>
                <span class="service-tag">{{ __('portal.public.service_'.($index + 1).'_tag') }}</span>
            </article>
        @endforeach
    </div>
</section>

<section class="site-section site-shell" id="workspace" style="padding-top:0">
    <div class="feature-block">
        <div class="product-preview">
            <div class="preview-head">
                <img src="{{ asset('assets/vodnox/vodnox-logo.png') }}" alt="VODNOX">
                <span>{{ __('portal.public.preview_head') }}</span>
            </div>
            <div class="preview-body">
                <div class="preview-sidebar">
                    <span>{{ __('portal.public.preview_side_1') }}</span>
                    <span>{{ __('portal.public.preview_side_2') }}</span>
                    <span>{{ __('portal.public.preview_side_3') }}</span>
                    <span>{{ __('portal.public.preview_side_4') }}</span>
                    <span>{{ __('portal.public.preview_side_5') }}</span>
                </div>
                <div class="preview-conversation">
                    <h3>{{ __('portal.public.preview_heading') }}</h3>
                    <p>{{ __('portal.public.preview_text') }}</p>
                    <div class="preview-note">
                        {{ __('portal.public.preview_note_title') }}<br>{{ __('portal.public.preview_note_text') }}
                    </div>
                </div>
            </div>
            <div class="preview-caption">{{ __('portal.public.preview_caption') }}</div>
        </div>
        <div class="feature-copy">
            <div class="section-label">{{ __('portal.public.label_built') }}</div>
            <h2>{!! __('portal.public.services_feature_heading') !!}</h2>
            <p>{{ __('portal.public.services_feature_text') }}</p>
            <div class="feature-list">
                <div>{{ __('portal.public.services_feature_1') }}</div>
                <div>{{ __('portal.public.services_feature_2') }}</div>
                <div>{{ __('portal.public.services_feature_3') }}</div>
            </div>
            <a class="site-btn orange" href="{{ route('contact') }}">{{ __('portal.public.services_cta') }}</a>
        </div>
    </div>
</section>

<section class="orange-band">
    <div class="site-shell band-inner">
        <div>
            <h2>{{ __('portal.public.cta_title') }}</h2>
            <p>{{ __('portal.public.cta_text') }}</p>
        </div>
        <a class="site-btn" href="{{ route('contact') }}">{{ __('portal.public.cta_button') }}</a>
    </div>
</section>
@endsection
