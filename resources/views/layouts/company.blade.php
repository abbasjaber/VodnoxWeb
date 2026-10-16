<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <script src="{{ asset('assets/vodnox/theme.js') }}"></script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#101D35">
    <link rel="icon" type="image/png" href="{{ asset('assets/vodnox/vodnox-app-icon-gold.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('assets/vodnox/vodnox-app-icon-gold.png') }}">
    <title>@yield('title', 'VODNOX') — @lang('vodnox.business_workspace')</title>
    <link rel="stylesheet" href="{{ asset('assets/vodnox/fonts.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/vodnox/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/vodnox/admin.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/vodnox/company.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/vodnox/theme.css') }}">
    @stack('styles')
</head>
<body>
<svg class="logo-color-defs" xmlns="http://www.w3.org/2000/svg" width="0" height="0" aria-hidden="true" focusable="false" style="position:absolute;pointer-events:none"><defs><filter id="vodnox-dark-logo" color-interpolation-filters="sRGB" x="0" y="0" width="100%" height="100%"><feColorMatrix type="matrix" values="1 0 0 0 0 1 0 0 0 0 1 0 0 0 0 0 0 0 1 0"/><feComponentTransfer><feFuncR type="discrete" tableValues="1 1"/><feFuncG type="discrete" tableValues="1 0.6235294118"/><feFuncB type="discrete" tableValues="1 0.2078431373"/><feFuncA type="identity"/></feComponentTransfer></filter></defs></svg>

<aside class="sidebar" id="sidebar">
    <a class="brand" href="{{ route('home') }}"><img src="{{ asset('assets/vodnox/vodnox-logo.png') }}" alt="VODNOX"><span>BUSINESS WORKSPACE</span></a>
    <div class="tenant-selector">
        <span class="tenant-avatar">{{ mb_substr(auth()->user()->organization?->name_ar ?? 'A', 0, 1) }}</span>
        <div>
            <strong>{{ auth()->user()->organization?->name_ar ?? auth()->user()->organization?->name_en ?? '-' }}</strong>
            <small>{{ auth()->user()->organization?->code ?? '' }} · ORGANIZATION</small>
        </div>
    </div>
    <div class="nav-label">@lang('vodnox.workspace')</div>
    @include('partials.company-nav')
    <div class="side-bottom">
        <div class="environment"><i></i><span>@lang('vodnox.preview')</span><small>{{ auth()->user()->username }}</small></div>
        <form method="POST" action="{{ route('logout') }}">@csrf<button class="logout" type="submit">@lang('vodnox.logout')</button></form>
    </div>
</aside>

<div class="app">
    <header class="topbar">
        <div class="breadcrumb">
            <button class="icon-btn mobile-menu" id="menuBtn" aria-label="@lang('vodnox.menu')">☰</button>
            <span>@lang('vodnox.workspace')</span>
            <span class="sep">/</span>
            <b id="crumb">@yield('crumb', '')</b>
        </div>
        <div class="top-actions">
            <button type="button" class="theme-toggle" data-theme-toggle aria-label="@lang('vodnox.toggle_theme')" aria-pressed="false"></button>
            <a class="language" href="{{ route('locale.switch', ['lang' => app()->getLocale() === 'ar' ? 'en' : 'ar']) }}">{{ app()->getLocale() === 'ar' ? 'English' : 'العربية' }}</a>
            <span class="top-divider"></span>
            <div class="profile">
                <span class="avatar">{{ mb_substr(auth()->user()->name ?? 'A', 0, 1) }}</span>
                <div><b>@lang('vodnox.org_admin')</b><small>ORGANIZATION ADMIN</small></div>
            </div>
        </div>
    </header>
    <main>
        @yield('content')
        <footer>
            <span>VODNOX <span class="footer-dot">·</span> <span>@lang('vodnox.footer')</span></span>
            <span>{{ auth()->user()->organization?->code ?? '' }} / COMPANY WORKSPACE</span>
        </footer>
    </main>
</div>

@include('partials.modal')
<div id="toast" class="app-toast" role="status" aria-live="polite"></div>

<script src="{{ asset('assets/vodnox/company-icons.js') }}"></script>
<script src="{{ asset('assets/vodnox/company.js') }}"></script>
@stack('scripts')
</body>
</html>
