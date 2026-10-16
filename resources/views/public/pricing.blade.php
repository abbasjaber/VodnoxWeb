@extends('layouts.public')

@section('title', 'VODNOX | ' . __('vodnox.pricing'))
@section('meta_description', __('portal.meta.pricing'))
@section('site_page', 'pricing')

@section('content')
@php
    $checkIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg>';
    $note = is_string($pricingNote) && $pricingNote !== '' ? $pricingNote : __('portal.public.pricing_note');
@endphp

<section class="inner-hero">
    <div class="site-shell">
        <div class="section-label">{{ __('portal.public.pricing_label') }}</div>
        <h1>{!! __('portal.public.pricing_hero_title') !!}</h1>
        <p>{{ __('portal.public.pricing_hero_text') }}</p>
    </div>
</section>

<section class="site-section site-shell">
    @if ($plans->isEmpty())
        <div class="pricing-grid">
            <article class="price-card featured">
                <small>VODNOX</small>
                <h2>{{ __('portal.public.pricing_empty_title') }}</h2>
                <p>{{ __('portal.public.pricing_empty_text') }}</p>
                <div class="price-quote">{{ __('portal.public.contact_for_price') }}</div>
                <span class="price-sub">{{ __('portal.public.pricing_talk_text') }}</span>
                <a class="site-btn orange" href="{{ route('contact') }}">{{ __('portal.public.pricing_empty_button') }}</a>
            </article>
        </div>
    @else
        <div class="pricing-grid">
            @foreach ($plans as $index => $plan)
                @php
                    $name = app()->getLocale() === 'ar' ? ($plan->name_ar ?: $plan->name_en) : ($plan->name_en ?: $plan->name_ar);
                    $description = app()->getLocale() === 'ar' ? ($plan->description_ar ?: $plan->description_en) : ($plan->description_en ?: $plan->description_ar);
                @endphp
                <article class="price-card {{ $index === 1 ? 'featured' : '' }}">
                    <small>{{ $plan->currency }}</small>
                    <h2>{{ $name }}</h2>
                    @if ($description)
                        <p>{{ $description }}</p>
                    @endif
                    @if ($plan->monthly_price !== null)
                        <div class="price-quote">{{ number_format((float) $plan->monthly_price, 0) }} {{ $plan->currency }}</div>
                        <span class="price-sub">{{ __('portal.public.per_month') }}</span>
                    @else
                        <div class="price-quote">{{ __('portal.public.contact_for_price') }}</div>
                        <span class="price-sub">{{ __('portal.public.pricing_talk_text') }}</span>
                    @endif
                    @if ($plan->yearly_price !== null)
                        <span class="price-sub">{{ number_format((float) $plan->yearly_price, 0) }} {{ $plan->currency }} {{ __('portal.public.per_year') }}</span>
                    @endif
                    @if ($plan->limits->isNotEmpty())
                        <ul>
                            @foreach ($plan->limits as $limit)
                                <li>
                                    {!! $checkIcon !!}
                                    {{ __('portal.public.plan_limits_labels.'.$limit->key) }}:
                                    {{ $limit->value === null ? __('portal.public.unlimited') : $limit->value }}
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    <a class="site-btn {{ $index === 1 ? 'orange' : 'outline' }}" href="{{ route('contact') }}">{{ __('portal.public.request_quote') }}</a>
                </article>
            @endforeach
        </div>
    @endif

    <p class="pricing-note">{{ $note }}</p>
</section>

<section class="orange-band">
    <div class="site-shell band-inner">
        <div>
            <h2>{{ __('portal.public.pricing_talk_title') }}</h2>
            <p>{{ __('portal.public.pricing_talk_text') }}</p>
        </div>
        <a class="site-btn" href="{{ route('contact') }}">{{ __('portal.public.cta_button') }}</a>
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
