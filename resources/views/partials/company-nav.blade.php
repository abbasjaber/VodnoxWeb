@php
    $navItems = [
        ['overview', 'vodnox.overview', 'dashboard', route('company.index')],
        ['team', 'vodnox.team', 'users', route('company.team.index')],
        ['departments', 'vodnox.departments', 'accounts', route('company.departments.index')],
        ['extensions', 'vodnox.extensions_devices', 'phone', route('company.extensions.index')],
        ['queues', 'vodnox.call_queues', 'users', route('company.queues.index')],
        ['ivr', 'vodnox.ivr_menus', 'integrations', route('company.ivr.index')],
        ['numbers', 'vodnox.numbers_routing', 'phone', route('company.numbers.index')],
        ['calls', 'vodnox.call_history', 'clock', route('company.calls.index')],
        ['recordings', 'vodnox.recordings', 'record', route('company.recordings.index')],
        ['reports', 'vodnox.performance_reports', 'usage', route('company.reports.index')],
        ['subscription', 'vodnox.org_subscription', 'subscriptions', route('company.subscription.index')],
        ['settings', 'vodnox.org_settings', 'settings', route('company.settings.index')],
        ['audit', 'vodnox.activity_log', 'audit', route('company.audit.index')],
    ];
    $organization = auth()->user()?->organization;
    // R5: the Smart IVR add-on is hidden from navigation until its independent
    // subscription is active (the controller also refuses direct access).
    $smartIvrActive = $organization
        ? app(\App\Services\SubscriptionService::class)->isActive($organization, 'smart_ivr')
        : false;
    $addons = [
        ['communications', 'vodnox.communications', 'phone', route('company.communications.index')],
        ['gateways', 'vodnox.gateways', 'integrations', route('company.gateways.index')],
        ['crm', 'vodnox.crm', 'plans', route('company.crm.index')],
        ['forms', 'VODNOX FORMS', 'plans', route('company.forms.index')],
    ];
    if ($smartIvrActive) {
        $addons[] = ['smart-ivr', 'vodnox.smart_ivr', 'integrations', route('company.smart-ivr.index')];
    }
    $icons = [
        'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'users' => '<circle cx="9" cy="8" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3M16 5a3 3 0 0 1 0 6m2 3a5 5 0 0 1 3 4v3"/>',
        'accounts' => '<path d="M4 21V5l8-3 8 3v16M2 21h20M9 21v-5h6v5M8 7h1m6 0h1M8 11h1m6 0h1"/>',
        'phone' => '<path d="m7 3 3 5-3 3a15 15 0 0 0 6 6l3-3 5 3-1 4C10 22 2 14 3 4l4-1Z"/>',
        'integrations' => '<path d="M8 3v5m8-5v5M6 8h12v3a6 6 0 0 1-12 0V8Zm6 9v4"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 6v6l4 2"/>',
        'record' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3"/>',
        'usage' => '<path d="M4 20V10m5 10V4m5 16v-7m5 7V7M2 21h20"/>',
        'subscriptions' => '<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M7 3v4m10-4v4M3 10h18m-14 5 3 3 6-6"/>',
        'settings' => '<path d="m9 3 1 3h4l1-3 3 2-1 3 2 3 3 1-1 4-3 1-2 3-3-1-3 1-2-3-3-1-1-4 3-1 2-3-1-3 3-2Z"/><circle cx="12" cy="12" r="3"/>',
        'audit' => '<path d="M5 4h14v17H5V4Zm3-1h8v3H8V3Zm0 8h8m-8 4h8"/>',
        'plans' => '<path d="m12 3 9 5-9 5-9-5 9-5Zm-9 9 9 5 9-5M3 16l9 5 9-5"/>',
    ];
    $current = request()->route()->getName() ?? '';
    // Note: a closure kept in a variable (not a global function) so rendering
    // this partial more than once in a single process never redeclares it.
    $navItemActive = fn (string $key): bool => str_starts_with($current, 'company.'.$key)
        || ($key === 'overview' && $current === 'company.index');
@endphp
<nav id="nav">
    @foreach($navItems as [$key, $label, $icon, $url])
        <a class="nav-item {{ $navItemActive($key) ? 'active' : '' }}" href="{{ $url }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $icons[$icon] ?? '' !!}</svg>
            <span>@lang($label)</span>
        </a>
    @endforeach
    <div class="nav-divider"></div>
    @foreach($addons as [$key, $label, $icon, $url])
        <a class="nav-item {{ str_starts_with($current, 'company.'.$key) ? 'active' : '' }}" href="{{ $url }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $icons[$icon] ?? '' !!}</svg>
            <span>@lang($label)</span>
        </a>
    @endforeach
</nav>
