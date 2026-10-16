@php
    $crmNav = [
        ['overview', 'crm.nav.overview', route('company.crm.index'), request()->routeIs('company.crm.index')],
        ['customers', 'crm.nav.customers', route('company.crm.customers.index'), request()->routeIs('company.crm.customers.*')],
        ['opportunities', 'crm.nav.opportunities', route('company.crm.opportunities.index'), request()->routeIs('company.crm.opportunities.*')],
        ['tasks', 'crm.nav.tasks', route('company.crm.tasks.index'), request()->routeIs('company.crm.tasks.*')],
        ['complaints', 'crm.nav.complaints', route('company.crm.complaints.index'), request()->routeIs('company.crm.complaints.*')],
        ['reports', 'crm.nav.reports', route('company.crm.reports.index'), request()->routeIs('company.crm.reports.*')],
    ];
@endphp
<nav class="crm-tabs" aria-label="{{ __('crm.title.index') }}">
    @foreach ($crmNav as [$key, $label, $url, $active])
        <a class="crm-btn{{ $active ? ' primary' : '' }}" href="{{ $url }}"@if ($active) aria-current="page"@endif>@lang($label)</a>
    @endforeach
</nav>
