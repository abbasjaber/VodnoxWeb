@php
    $nav = [
        ['home', '/', 'vodnox.home'],
        ['services', '/services', 'vodnox.services'],
        ['pricing', '/pricing', 'vodnox.pricing'],
        ['about', '/about', 'vodnox.about'],
        ['contact', '/contact', 'vodnox.contact'],
    ];
    $current = request()->path();
@endphp
<header id="siteHeader" class="site-header">
    <div class="site-shell header-inner">
        <a class="site-brand" href="{{ url('/') }}">
            <img src="{{ asset('assets/vodnox/vodnox-logo.png') }}" alt="VODNOX" class="brand-logo">
        </a>
        <nav class="site-nav" id="websiteNav" aria-label="Main">
            @foreach($nav as [$key, $url, $label])
                <a href="{{ url($url) }}" class="{{ $current === ltrim($url, '/') || ($key==='home' && $current==='/') ? 'active' : '' }}">@lang($label)</a>
            @endforeach
        </nav>
        <div class="header-actions">
            <button type="button" class="theme-toggle" data-theme-toggle aria-label="@lang('vodnox.toggle_theme')" aria-pressed="false"></button>
            <a class="language" href="{{ route('locale.switch', ['lang' => app()->getLocale() === 'ar' ? 'en' : 'ar']) }}">{{ app()->getLocale() === 'ar' ? 'EN' : 'ع' }}</a>
            <a class="site-btn" href="{{ route('login') }}">@lang('vodnox.sign_in')</a>
        </div>
        <button class="site-menu" id="websiteMenu" aria-expanded="false" aria-label="Menu">☰</button>
    </div>
</header>
