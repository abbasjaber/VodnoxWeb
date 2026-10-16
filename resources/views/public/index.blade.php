@extends('layouts.public')

@section('title', 'VODNOX')
@section('meta_description', __('portal.meta.home'))
@section('site_page', 'home')

@section('content')
<section class="hero">
    <div class="site-shell hero-layout">
        <div class="hero-copy">
            <div class="eyebrow"><i></i>{{ __('portal.public.hero_eyebrow') }}</div>
            <h1>{!! __('portal.public.hero_title') !!}</h1>
            <p>{{ __('portal.public.hero_text') }}</p>
            <div class="hero-actions">
                <a class="site-btn orange" href="{{ route('contact') }}">{{ __('portal.public.hero_cta_1') }}</a>
                <a class="site-btn outline" href="{{ route('services') }}">{{ __('portal.public.hero_cta_2') }}</a>
            </div>
            <div class="hero-small">
                <span>{{ __('portal.public.hero_badge_1') }}</span>
                <span>{{ __('portal.public.hero_badge_2') }}</span>
                <span>{{ __('portal.public.hero_badge_3') }}</span>
            </div>
        </div>
        <div class="hero-visual">
            <div class="signal-chip"><i></i>{{ __('portal.public.hero_note_1') }}</div>
            <div class="visual-note">{{ __('portal.public.hero_note_2') }}</div>
        </div>
    </div>
</section>

<div class="site-shell service-strip">
    @foreach (['CLOUD PBX', 'SMART ROUTING', 'CALL QUEUES', 'AGENT WORKSPACE', 'WEB / MOBILE'] as $tag)
        <span><i></i>{{ $tag }}</span>
    @endforeach
</div>

<section class="site-section site-shell">
    <div class="section-label">{{ __('portal.public.label_one_system') }}</div>
    <div class="section-heading">
        <h2>{!! __('portal.public.home_heading') !!}</h2>
        <p>{{ __('portal.public.home_text') }}</p>
    </div>
    <div class="service-grid">
        @foreach ([1, 2, 3] as $index)
            <article class="service-card">
                <span class="service-number">0{{ $index }}</span>
                <h3>{{ __('portal.public.service_'.$index.'_title') }}</h3>
                <p>{{ __('portal.public.service_'.$index.'_text') }}</p>
                <span class="service-tag">{{ __('portal.public.service_'.$index.'_tag') }}</span>
            </article>
        @endforeach
    </div>
    <div style="margin-top:28px">
        <a class="site-btn outline" href="{{ route('services') }}">{{ __('portal.public.all_services') }}</a>
    </div>
</section>

<section class="site-section site-shell" style="padding-top:15px">
    <div class="feature-block">
        <div class="feature-copy">
            <div class="section-label">{{ __('portal.public.label_context') }}</div>
            <h2>{!! __('portal.public.feature_heading') !!}</h2>
            <p>{{ __('portal.public.feature_text') }}</p>
            <div class="feature-list">
                <div>{{ __('portal.public.feature_1') }}</div>
                <div>{{ __('portal.public.feature_2') }}</div>
                <div>{{ __('portal.public.feature_3') }}</div>
            </div>
            <a class="site-btn outline" href="{{ route('services') }}">{{ __('portal.public.explore_agent') }}</a>
        </div>
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

<section class="site-section site-shell">
    <div class="section-label">{{ __('portal.public.label_next') }}</div>
    <div class="section-heading">
        <h2>{!! __('portal.public.steps_heading') !!}</h2>
        <p>{{ __('portal.public.steps_text') }}</p>
    </div>
    <div class="steps">
        @foreach ([1, 2, 3] as $index)
            <article class="step">
                <span>0{{ $index }}</span>
                <h3>{{ __('portal.public.step_'.$index.'_title') }}</h3>
                <p>{{ __('portal.public.step_'.$index.'_text') }}</p>
            </article>
        @endforeach
    </div>
</section>

<section class="site-section site-shell">
    <div class="faq-grid">
        <div class="faq-intro">
            <div class="section-label">{{ __('portal.public.faq_label') }}</div>
            <h2>{{ __('portal.public.faq_heading') }}</h2>
            <p>{{ __('portal.public.faq_text') }}</p>
        </div>
        <div class="faq-list">
            @foreach ([1, 2, 3, 4] as $index)
                <details>
                    <summary>{{ __('portal.public.faq_q'.$index) }}</summary>
                    <p>{{ __('portal.public.faq_a'.$index) }}</p>
                </details>
            @endforeach
        </div>
    </div>
</section>
@endsection
