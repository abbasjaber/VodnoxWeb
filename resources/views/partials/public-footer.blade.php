<footer id="siteFooter" class="site-footer">
    <div class="site-shell footer-inner">
        <div class="footer-brand">
            <img src="{{ asset('assets/vodnox/vodnox-logo.png') }}" alt="VODNOX" class="brand-logo">
            <p>@lang('vodnox.footer_tagline')</p>
        </div>
        <div class="footer-links">
            <div class="footer-col">
                <h4>@lang('vodnox.platform')</h4>
                <a href="{{ url('/services') }}">@lang('vodnox.services')</a>
                <a href="{{ url('/pricing') }}">@lang('vodnox.pricing')</a>
                <a href="{{ url('/about') }}">@lang('vodnox.about')</a>
            </div>
            <div class="footer-col">
                <h4>@lang('vodnox.company')</h4>
                <a href="{{ url('/contact') }}">@lang('vodnox.contact')</a>
                <a href="{{ route('login') }}">@lang('vodnox.sign_in')</a>
            </div>
        </div>
        <div class="footer-bottom">
            <span>© 2026 VODNOX · @lang('vodnox.rights_reserved')</span>
        </div>
    </div>
</footer>
