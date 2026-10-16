@extends('layouts.public')

@section('title', 'VODNOX | ' . __('vodnox.about'))
@section('meta_description', __('portal.meta.about'))
@section('site_page', 'about')

@section('content')
<section class="inner-hero">
    <div class="site-shell">
        <div class="section-label">{{ __('portal.public.about_label') }}</div>
        <h1>{!! __('portal.public.about_hero_title') !!}</h1>
        <p>{{ __('portal.public.about_hero_text') }}</p>
    </div>
</section>

<section class="site-section site-shell">
    <div class="section-label">{{ __('portal.public.label_point_of_view') }}</div>
    <p class="about-statement">{!! __('portal.public.about_statement') !!}</p>
    <div class="values-grid">
        @foreach ([1, 2, 3] as $index)
            <article class="value">
                <span>0{{ $index }}</span>
                <h3>{{ __('portal.public.value_'.$index.'_title') }}</h3>
                <p>{{ __('portal.public.value_'.$index.'_text') }}</p>
            </article>
        @endforeach
    </div>
</section>

<section class="site-section site-shell" style="padding-top:0">
    <div class="feature-block">
        <div class="feature-copy">
            <div class="section-label">{{ __('portal.public.label_vision') }}</div>
            <h2>{!! __('portal.public.vision_heading') !!}</h2>
            <p>{{ __('portal.public.vision_text') }}</p>
            <a class="site-btn outline" href="{{ route('services') }}">{{ __('portal.public.vision_link') }}</a>
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
@endsection
