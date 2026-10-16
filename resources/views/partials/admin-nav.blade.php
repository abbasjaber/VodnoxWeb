<nav id="nav">
    @php
        $items = [
            ['overview', 'vodnox.overview', 'dashboard', route('admin.index')],
            ['accounts', 'vodnox.accounts', 'accounts', route('admin.organizations.index')],
            ['subscriptions', 'vodnox.subscriptions', 'subscriptions', route('admin.subscriptions.index')],
            ['plans', 'vodnox.plans', 'plans', route('admin.plans.index')],
            ['billing', 'vodnox.billing', 'billing', route('admin.billing.index')],
            ['usage', 'vodnox.usage', 'usage', route('admin.usage.index')],
        ];
        $items2 = [
            ['servers', 'vodnox.servers', 'servers', route('admin.servers.index')],
            ['integrations', 'vodnox.integrations', 'integrations', route('admin.integrations.index')],
            ['team', 'vodnox.team', 'users', route('admin.team.index')],
            ['audit', 'vodnox.audit', 'audit', route('admin.audit.index')],
            ['settings', 'vodnox.settings', 'settings', route('admin.settings.index')],
        ];
        $addons = [
            ['crm', 'vodnox.crm_addon', 'plans', route('admin.crm.index')],
            ['forms', 'VODNOX FORMS', 'plans', route('admin.forms.index')],
            ['smart-ivr', 'vodnox.smart_ivr', 'integrations', route('admin.smart-ivr.index')],
        ];
        $current = request()->route()->getName() ?? '';
    @endphp
    @foreach($items as [$key, $label, $icon, $url])
        <a class="nav-item {{ str_starts_with($current, 'admin.'.$key) || ($key==='overview' && $current==='admin.index') ? 'active' : '' }}" href="{{ $url }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><use href="#icon-{{ $icon }}"/></svg>
            <span>@lang($label)</span>
        </a>
    @endforeach
    <div class="nav-divider"></div>
    @foreach($items2 as [$key, $label, $icon, $url])
        <a class="nav-item {{ str_starts_with($current, 'admin.'.$key) ? 'active' : '' }}" href="{{ $url }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><use href="#icon-{{ $icon }}"/></svg>
            <span>@lang($label)</span>
        </a>
    @endforeach
    <div class="nav-divider"></div>
    @foreach($addons as [$key, $label, $icon, $url])
        <a class="nav-item {{ str_starts_with($current, 'admin.'.$key) ? 'active' : '' }}" href="{{ $url }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><use href="#icon-{{ $icon }}"/></svg>
            <span>@lang($label)</span>
        </a>
    @endforeach
</nav>
