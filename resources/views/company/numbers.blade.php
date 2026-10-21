@extends('layouts.company')

@section('title', __('company.numbers_title'))
@section('crumb', __('company.numbers_title'))

@section('content')
@include('company._assets')
@include('company._alerts')

<div class="page-head">
    <div>
        <h1>{{ __('company.numbers_title') }}</h1>
        <p>{{ __('company.numbers_subtitle') }}</p>
    </div>
    <div class="head-actions">
        <a class="btn-secondary" href="{{ route('company.gateways.index') }}">{{ __('company.gateways_title') }}</a>
        <button type="button" class="btn-primary"
                data-company-modal
                data-company-title="{{ __('company.add_number') }}"
                data-company-action="{{ route('company.numbers.store') }}"
                data-company-submit="{{ __('company.save') }}"
                data-company-template="tpl-number-create">{{ __('company.add_number') }}</button>
    </div>
</div>

{{-- Group A: internal employee numbers. These are the employee extensions of
     the organization; each row shows where that employee is already reached
     from, so no second forwarding entry has to be created for them. --}}
<div class="card">
    <div class="card-head">
        <div><h2>{{ __('company.numbers_group_extensions') }}</h2><p>{{ __('company.numbers_group_extensions_hint') }}</p></div>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>{{ __('company.agent') }}</th>
                    <th>{{ __('company.extension') }}</th>
                    <th>{{ __('company.status') }}</th>
                    <th>{{ __('company.numbers_ext_usage') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($extensions as $extension)
                    <tr>
                        <td>{{ $extension->label ?: ($extension->user?->name ?? '—') }}</td>
                        <td class="code-note">{{ $extension->extension }}</td>
                        <td><span class="status {{ $extension->status === 'active' ? 'active' : 'inactive' }}">{{ __('company.status_labels.'.$extension->status) }}</span></td>
                        <td>
                            @php $usage = $extensionUsage[$extension->id] ?? []; @endphp
                            @if ($usage)
                                @foreach (array_unique($usage) as $place)
                                    <span class="tag">{{ $place }}</span>
                                @endforeach
                            @else
                                <small>{{ __('company.numbers_ext_no_usage') }}</small>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="empty-row">{{ __('company.no_data') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Group B: gateway lines and their numbers. The SIM number is descriptive
     only; the routing itself lives on the port and is edited here. --}}
<div class="card">
    <div class="card-head">
        <div><h2>{{ __('company.numbers_group_lines') }}</h2><p>{{ __('company.numbers_group_lines_hint') }}</p></div>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>{{ __('company.numbers_line_device') }}</th>
                    <th>{{ __('company.gateway_port') }}</th>
                    <th>{{ __('company.gateway_line_label') }}</th>
                    <th>{{ __('company.gateway_sim_number') }}</th>
                    <th>{{ __('company.numbers_line_state') }}</th>
                    <th>{{ __('company.numbers_line_default_destination') }}</th>
                    <th>{{ __('company.numbers_line_schedule') }}</th>
                    <th>{{ __('company.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($gatewayDevices as $device)
                    @foreach ($device->lines as $line)
                        @php
                            $route = $line->route;
                            $hasWeekly = ($route?->businessHours?->isNotEmpty() ?? false) || ($route?->weekendDays?->isNotEmpty() ?? false);
                            $hasExceptions = $route?->dateExceptions?->isNotEmpty() ?? false;
                        @endphp
                        <tr>
                            <td>{{ $device->label }}</td>
                            <td><strong>#{{ $line->line_index }}</strong></td>
                            <td>{{ $line->label ?: '—' }}</td>
                            <td class="code-note">{{ $line->sim_number ?: '—' }}</td>
                            <td><span class="status {{ $line->enabled ? 'active' : 'inactive' }}">{{ __('company.gateway_port_'.$line->portState()) }}</span></td>
                            <td>
                                @if ($route?->defaultDestination)
                                    {{ $route->defaultDestination->extension }}{{ $route->defaultDestination->label ? ' — '.$route->defaultDestination->label : '' }}
                                @else
                                    —
                                @endif
                            </td>
                            <td>
                                @if ($hasWeekly)
                                    <span class="tag">{{ __('company.numbers_schedule_weekly') }}</span>
                                @else
                                    <span class="tag">{{ __('company.numbers_schedule_always') }}</span>
                                @endif
                                @if ($hasExceptions)
                                    <span class="tag">{{ __('company.numbers_schedule_exceptions') }}</span>
                                @endif
                                @if ($route?->temporary_forward_enabled)
                                    <span class="tag">{{ __('company.numbers_schedule_temporary') }}</span>
                                @endif
                            </td>
                            <td>
                                <a class="row-link" href="{{ route('company.numbers.routing.edit', ['gateway' => $device, 'line' => $line]) }}">{{ __('company.gateway_manage_routing') }}</a>
                            </td>
                        </tr>
                    @endforeach
                @empty
                    <tr><td colspan="8" class="empty-row">{{ __('company.numbers_no_lines') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<p class="section-note">{{ __('company.routing_hint') }}</p>

<div class="card">
    <div class="card-head">
        <div><h2>{{ __('company.phone_number') }}</h2><p>{{ $numbers->total() }}</p></div>
    </div>

    <form class="filters" method="GET" action="{{ route('company.numbers.index') }}">
        <div class="search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.6-3.6"/></svg>
            <input type="search" name="q" value="{{ $q }}" placeholder="{{ __('company.search') }}" aria-label="{{ __('company.search') }}">
        </div>
        <select name="status" aria-label="{{ __('company.status') }}">
            <option value="">{{ __('company.all') }}</option>
            <option value="active" @selected($status === 'active')>{{ __('company.active') }}</option>
            <option value="inactive" @selected($status === 'inactive')>{{ __('company.inactive') }}</option>
        </select>
        <button type="submit" class="btn-primary">{{ __('company.search') }}</button>
        @if ($q !== '' || $status !== '')
            <a class="btn-secondary" href="{{ route('company.numbers.index') }}">{{ __('company.all') }}</a>
        @endif
    </form>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>{{ __('company.phone_number') }}</th>
                    <th>{{ __('company.number_label') }}</th>
                    <th>{{ __('company.routing_target') }}</th>
                    <th>{{ __('company.number_status') }}</th>
                    <th>{{ __('company.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($numbers as $number)
                    @php
                        $routingValue = $number->routing_type.($number->routing_target_id ? ':'.$number->routing_target_id : '');
                    @endphp
                    <tr>
                        <td><span class="number">{{ $number->number }}</span></td>
                        <td>{{ $number->label ?: '—' }}</td>
                        <td>{{ $routingLabels[$routingValue] ?? $routingValue }}</td>
                        <td><span class="status {{ $number->status }}">{{ __('company.status_labels.'.$number->status) }}</span></td>
                        <td>
                            <div class="table-actions">
                                <button type="button" class="row-link"
                                        data-company-modal
                                        data-company-title="{{ __('company.edit_number') }}"
                                        data-company-action="{{ route('company.numbers.update', $number) }}"
                                        data-company-submit="{{ __('company.save') }}"
                                        data-company-template="tpl-number-edit-{{ $number->id }}">{{ __('company.edit') }}</button>
                                <form method="POST" action="{{ route('company.numbers.destroy', $number) }}" data-confirm="{{ __('company.confirm_delete') }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="row-link">{{ __('company.delete') }}</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="empty-row">{{ __('company.no_data') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="table-foot">
        <span>{{ __('company.showing') }} {{ $numbers->firstItem() ?? 0 }}–{{ $numbers->lastItem() ?? 0 }} / {{ $numbers->total() }}</span>
        @include('company._pagination', ['paginator' => $numbers])
    </div>
</div>

<template id="tpl-number-create">
    @csrf
    <div class="form-grid">
        <div class="form-field"><label for="nc-number">{{ __('company.phone_number') }}</label><input id="nc-number" type="text" name="number" value="{{ old('number') }}" required></div>
        <div class="form-field"><label for="nc-label">{{ __('company.number_label') }}</label><input id="nc-label" type="text" name="label" value="{{ old('label') }}"></div>
        <div class="form-field"><label for="nc-routing">{{ __('company.routing_target') }}</label>
            <select id="nc-routing" name="routing" required>
                @foreach ($routingLabels as $value => $label)
                    <option value="{{ $value }}" @selected(old('routing') === (string) $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-field"><label for="nc-status">{{ __('company.number_status') }}</label>
            <select id="nc-status" name="status" required>
                <option value="active" @selected(old('status', 'active') === 'active')>{{ __('company.active') }}</option>
                <option value="inactive" @selected(old('status') === 'inactive')>{{ __('company.inactive') }}</option>
            </select>
        </div>
    </div>
</template>

@foreach ($numbers as $number)
    @php
        $routingValue = $number->routing_type.($number->routing_target_id ? ':'.$number->routing_target_id : '');
    @endphp
    <template id="tpl-number-edit-{{ $number->id }}">
        @csrf
        @method('PUT')
        <div class="form-grid">
            <div class="form-field"><label for="ne{{ $number->id }}-number">{{ __('company.phone_number') }}</label><input id="ne{{ $number->id }}-number" type="text" name="number" value="{{ $number->number }}" required></div>
            <div class="form-field"><label for="ne{{ $number->id }}-label">{{ __('company.number_label') }}</label><input id="ne{{ $number->id }}-label" type="text" name="label" value="{{ $number->label }}"></div>
            <div class="form-field"><label for="ne{{ $number->id }}-routing">{{ __('company.routing_target') }}</label>
                <select id="ne{{ $number->id }}-routing" name="routing" required>
                    @foreach ($routingLabels as $value => $label)
                        <option value="{{ $value }}" @selected($routingValue === (string) $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-field"><label for="ne{{ $number->id }}-status">{{ __('company.number_status') }}</label>
                <select id="ne{{ $number->id }}-status" name="status" required>
                    <option value="active" @selected($number->status === 'active')>{{ __('company.active') }}</option>
                    <option value="inactive" @selected($number->status === 'inactive')>{{ __('company.inactive') }}</option>
                </select>
            </div>
        </div>
    </template>
@endforeach
@endsection
