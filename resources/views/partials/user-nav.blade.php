@php
    $navItems = [
        ['dialer', 'vodnox.calling', 'keypad', route('user.index')],
        ['contacts', 'vodnox.contacts', 'users', route('user.contacts.index')],
        ['history', 'vodnox.my_call_history', 'clock', route('user.history.index')],
        ['voicemail', 'vodnox.voicemail', 'mail', route('user.voicemail.index')],
        ['settings', 'vodnox.my_account_settings', 'settings', route('user.settings.index')],
    ];
    $icons = [
        'keypad' => '<rect x="3" y="3" width="5" height="5" rx="1"/><rect x="10" y="3" width="5" height="5" rx="1"/><rect x="17" y="3" width="4" height="5" rx="1"/><rect x="3" y="10" width="5" height="5" rx="1"/><rect x="10" y="10" width="5" height="5" rx="1"/><rect x="17" y="10" width="4" height="5" rx="1"/><rect x="10" y="17" width="5" height="4" rx="1"/>',
        'users' => '<circle cx="9" cy="8" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3M16 5a3 3 0 0 1 0 6m2 3a5 5 0 0 1 3 4v3"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 6v6l4 2"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 6 9 7 9-7"/>',
        'settings' => '<path d="m9 3 1 3h4l1-3 3 2-1 3 2 3 3 1-1 4-3 1-2 3-3-1-3 1-2-3-3-1-1-4 3-1 2-3-1-3 3-2Z"/><circle cx="12" cy="12" r="3"/>',
    ];
    $current = request()->route()->getName() ?? '';
@endphp
<nav id="nav">
    @foreach($navItems as [$key, $label, $icon, $url])
        <a class="nav-item {{ str_starts_with($current, 'user.'.$key) || ($key==='dialer' && $current==='user.index') ? 'active' : '' }}" href="{{ $url }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $icons[$icon] ?? '' !!}</svg>
            <span>@lang($label)</span>
        </a>
    @endforeach
</nav>
