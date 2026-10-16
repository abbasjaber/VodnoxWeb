<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <script src="{{ asset('assets/vodnox/theme.js') }}"></script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#101D35">
    <link rel="icon" type="image/png" href="{{ asset('assets/vodnox/vodnox-app-icon-gold.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('assets/vodnox/vodnox-app-icon-gold.png') }}">
    <meta name="description" content="@yield('meta_description', __('vodnox.meta_description'))">
    <title>@yield('title', 'VODNOX')</title>
    <link rel="stylesheet" href="{{ asset('assets/vodnox/fonts.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/vodnox/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/vodnox/theme.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/vodnox/website.css') }}">
    <style>
        .site-brand .brand-logo { max-height: 44px; width: auto; display: block; }
        .footer-brand .brand-logo { max-height: 40px; width: auto; display: block; margin-bottom: 12px; }
    </style>
    @stack('styles')
</head>
<body data-site-page="@yield('site_page', 'home')">
<svg class="logo-color-defs" xmlns="http://www.w3.org/2000/svg" width="0" height="0" aria-hidden="true" focusable="false" style="position:absolute;pointer-events:none"><defs><filter id="vodnox-dark-logo" color-interpolation-filters="sRGB" x="0" y="0" width="100%" height="100%"><feColorMatrix type="matrix" values="1 0 0 0 0 1 0 0 0 0 1 0 0 0 0 0 0 0 1 0"/><feComponentTransfer><feFuncR type="discrete" tableValues="1 1"/><feFuncG type="discrete" tableValues="1 0.6235294118"/><feFuncB type="discrete" tableValues="1 0.2078431373"/><feFuncA type="identity"/></feComponentTransfer></filter></defs></svg>
<a class="skip-link" href="#siteMain">@lang('vodnox.skip_to_content')</a>
@include('partials.public-header')
<main id="siteMain">
    @yield('content')
</main>
@include('partials.public-footer')
<div id="siteToast" class="site-toast" role="status" aria-live="polite"></div>
<script src="{{ asset('assets/vodnox/website.js') }}"></script>
@stack('scripts')
</body>
</html>
