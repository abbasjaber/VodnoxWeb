@extends('layouts.company')

@section('title', __('company.gateway_routing_title'))
@section('crumb', __('company.numbers_title'))

@php
    $weekdays = [0, 1, 2, 3, 4, 5, 6];

    $windowRows = old('business_hours');
    if ($windowRows === null) {
        $windowRows = $route->businessHours
            ->map(fn ($w) => ['day' => (int) $w->day, 'start' => substr((string) $w->start_time, 0, 5), 'end' => substr((string) $w->end_time, 0, 5)])
            ->values()->all();
    }
    if ($windowRows === []) {
        $windowRows = [['day' => 0, 'start' => '09:00', 'end' => '17:00']];
    }

    $exceptionRows = old('exceptions');
    if ($exceptionRows === null) {
        $exceptionRows = $route->dateExceptions
            ->map(fn ($e) => [
                'starts_on' => $e->starts_on?->format('Y-m-d'),
                'ends_on' => $e->ends_on?->format('Y-m-d'),
                'destination_extension_id' => $e->destination_extension_id,
                'label' => $e->label,
            ])
            ->values()->all();
    }

    $selectedWeekendDays = array_map('intval', (array) old('weekend_days', $route->weekendDays->pluck('day')->all()));

    $preview = session('routing_preview');
@endphp

@section('content')
@include('company._assets')
@include('company._alerts')

<div class="page-head">
    <div>
        <h1>{{ __('company.gateway_routing_title') }}</h1>
        <p>{{ __('company.gateway_routing_subtitle') }}</p>
    </div>
    <div class="head-actions">
        <a class="btn-secondary" href="{{ route('company.numbers.index') }}">{{ __('company.back') }}</a>
        <a class="btn-secondary" href="{{ route('company.gateways.settings', $gateway) }}">{{ __('company.gateway_settings_link') }}</a>
    </div>
</div>

@unless ($supportsRouting)
    <div class="section-note" role="alert">{{ __('company.gateway_routing_agent_old') }}</div>
@endunless

<p class="section-note">{{ __('company.gateway_routing_priority') }}</p>

<div class="card">
    <div class="card-head">
        <div>
            <h2>{{ $gateway->label }} · {{ __('company.gateway_port') }} #{{ $line->line_index }}</h2>
            <p>
                {{ $line->label ?: '—' }}
                @if ($line->sim_number) · {{ $line->sim_number }} @endif
                · <span class="status {{ $line->enabled ? 'active' : 'inactive' }}">{{ __('company.gateway_port_'.$line->portState()) }}</span>
            </p>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <tbody>
                <tr><th>{{ __('company.gateway_sip_identity') }}</th><td class="code-note">{{ $line->sip_username ?: '—' }}</td></tr>
                <tr><th>{{ __('company.gateway_inbound_number') }}</th><td class="code-note">{{ $line->inbound_number ?: '—' }}</td></tr>
                <tr><th>{{ __('company.gateway_routing_timezone') }}</th><td class="code-note">{{ $timezone }}</td></tr>
            </tbody>
        </table>
    </div>
</div>

@if ($destinations->isEmpty())
    <div class="section-note" role="alert">{{ __('company.gateway_routing_no_destinations') }}</div>
@endif

<form method="POST" action="{{ route('company.numbers.routing.update', ['gateway' => $gateway, 'line' => $line]) }}">
    @csrf
    @method('PUT')

    <div class="card">
        <div class="card-head"><div><h2>{{ __('company.gateway_routing_destinations') }}</h2><p>{{ __('company.gateway_routing_destinations_hint') }}</p></div></div>
        <div class="form-grid">
            <div class="form-field">
                <label for="rt-default">{{ __('company.gateway_routing_default') }}</label>
                <select id="rt-default" name="default_destination_extension_id">
                    <option value="">{{ __('company.none') }}</option>
                    @foreach ($destinations as $destination)
                        <option value="{{ $destination->id }}" @selected((int) old('default_destination_extension_id', $route->default_destination_extension_id) === $destination->id)>
                            {{ $destination->extension }}{{ $destination->label ? ' — '.$destination->label : '' }}
                        </option>
                    @endforeach
                </select>
                <small class="section-note">{{ __('company.gateway_routing_default_hint') }}</small>
            </div>

            <div class="form-field">
                <label for="rt-outside">{{ __('company.gateway_routing_outside_hours') }}</label>
                <select id="rt-outside" name="outside_hours_destination_extension_id">
                    <option value="">{{ __('company.none') }}</option>
                    @foreach ($destinations as $destination)
                        <option value="{{ $destination->id }}" @selected((int) old('outside_hours_destination_extension_id', $route->outside_hours_destination_extension_id) === $destination->id)>
                            {{ $destination->extension }}{{ $destination->label ? ' — '.$destination->label : '' }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="form-field">
                <label for="rt-weekend">{{ __('company.gateway_routing_weekend_destination') }}</label>
                <select id="rt-weekend" name="weekend_destination_extension_id">
                    <option value="">{{ __('company.gateway_routing_weekend_same_as_outside') }}</option>
                    @foreach ($destinations as $destination)
                        <option value="{{ $destination->id }}" @selected((int) old('weekend_destination_extension_id', $route->weekend_destination_extension_id) === $destination->id)>
                            {{ $destination->extension }}{{ $destination->label ? ' — '.$destination->label : '' }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="form-field">
                <label for="rt-fallback">{{ __('company.gateway_routing_fallback') }}</label>
                <select id="rt-fallback" name="fallback_destination_extension_id">
                    <option value="">{{ __('company.none') }}</option>
                    @foreach ($destinations as $destination)
                        <option value="{{ $destination->id }}" @selected((int) old('fallback_destination_extension_id', $route->fallback_destination_extension_id) === $destination->id)>
                            {{ $destination->extension }}{{ $destination->label ? ' — '.$destination->label : '' }}
                        </option>
                    @endforeach
                </select>
                <small class="section-note">{{ __('company.gateway_routing_fallback_hint') }}</small>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><div><h2>{{ __('company.gateway_routing_temporary') }}</h2><p>{{ __('company.gateway_routing_temporary_hint') }}</p></div></div>
        <div class="form-grid">
            <div class="form-field">
                <label for="rt-temp-enabled">{{ __('company.gateway_routing_temporary_enabled') }}</label>
                <input id="rt-temp-enabled" type="checkbox" name="temporary_forward_enabled" value="1" @checked(old('temporary_forward_enabled', $route->temporary_forward_enabled))>
            </div>
            <div class="form-field">
                <label for="rt-temp-dest">{{ __('company.gateway_routing_temporary_destination') }}</label>
                <select id="rt-temp-dest" name="temporary_forward_extension_id">
                    <option value="">{{ __('company.none') }}</option>
                    @foreach ($destinations as $destination)
                        <option value="{{ $destination->id }}" @selected((int) old('temporary_forward_extension_id', $route->temporary_forward_extension_id) === $destination->id)>
                            {{ $destination->extension }}{{ $destination->label ? ' — '.$destination->label : '' }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><div><h2>{{ __('company.days') }}</h2><p>{{ __('company.gateway_routing_weekend_hint') }}</p></div></div>
        <div class="form-grid">
            @foreach ($weekdays as $weekday)
                <div class="form-field">
                    <label>
                        <input type="checkbox" name="weekend_days[]" value="{{ $weekday }}" @checked(in_array($weekday, $selectedWeekendDays, true))>
                        {{ __('company.day_'.$weekday) }}
                    </label>
                </div>
            @endforeach
        </div>
    </div>

    <div class="card">
        <div class="card-head">
            <div><h2>{{ __('company.gateway_routing_business_hours') }}</h2><p>{{ __('company.gateway_routing_business_hours_hint') }}</p></div>
            <button type="button" class="btn-secondary" data-routing-add="rt-windows" data-routing-template="tpl-window">{{ __('company.add_day') }}</button>
        </div>
        <div id="rt-windows" data-routing-rows="business_hours">
            @foreach ($windowRows as $index => $window)
                <div class="form-grid" data-routing-row>
                    <div class="form-field"><label>{{ __('company.day') }}</label>
                        <select name="business_hours[{{ $index }}][day]">
                            @foreach ($weekdays as $weekday)
                                <option value="{{ $weekday }}" @selected((int) ($window['day'] ?? 0) === $weekday)>{{ __('company.day_'.$weekday) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-field"><label>{{ __('company.start_time') }}</label><input type="time" name="business_hours[{{ $index }}][start]" value="{{ $window['start'] ?? '09:00' }}"></div>
                    <div class="form-field"><label>{{ __('company.end_time') }}</label><input type="time" name="business_hours[{{ $index }}][end]" value="{{ $window['end'] ?? '17:00' }}"></div>
                    <button type="button" class="btn-quiet" data-routing-remove>{{ __('company.remove_day') }}</button>
                </div>
            @endforeach
        </div>
        <p class="section-note">{{ __('company.gateway_routing_midnight_hint') }}</p>
    </div>

    <div class="card">
        <div class="card-head">
            <div><h2>{{ __('company.gateway_routing_exceptions') }}</h2><p>{{ __('company.gateway_routing_exceptions_hint') }}</p></div>
            <button type="button" class="btn-secondary" data-routing-add="rt-exceptions" data-routing-template="tpl-exception">{{ __('company.gateway_routing_add_exception') }}</button>
        </div>
        <div id="rt-exceptions" data-routing-rows="exceptions">
            @foreach ($exceptionRows as $index => $exception)
                <div class="form-grid" data-routing-row>
                    <div class="form-field"><label>{{ __('company.gateway_routing_exception_label') }}</label><input type="text" name="exceptions[{{ $index }}][label]" value="{{ $exception['label'] ?? '' }}"></div>
                    <div class="form-field"><label>{{ __('company.gateway_routing_exception_starts') }}</label><input type="date" name="exceptions[{{ $index }}][starts_on]" value="{{ $exception['starts_on'] ?? '' }}"></div>
                    <div class="form-field"><label>{{ __('company.gateway_routing_exception_ends') }}</label><input type="date" name="exceptions[{{ $index }}][ends_on]" value="{{ $exception['ends_on'] ?? '' }}"></div>
                    <div class="form-field"><label>{{ __('company.destination') }}</label>
                        <select name="exceptions[{{ $index }}][destination_extension_id]">
                            <option value="">{{ __('company.none') }}</option>
                            @foreach ($destinations as $destination)
                                <option value="{{ $destination->id }}" @selected((int) ($exception['destination_extension_id'] ?? 0) === $destination->id)>
                                    {{ $destination->extension }}{{ $destination->label ? ' — '.$destination->label : '' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <button type="button" class="btn-quiet" data-routing-remove>{{ __('company.remove_day') }}</button>
                </div>
            @endforeach
        </div>
        <p class="section-note">{{ __('company.gateway_routing_exception_end_hint') }}</p>
    </div>

    @if ($canManage)
        <div class="head-actions">
            <button type="submit" class="btn-primary">{{ __('company.save') }}</button>
        </div>
    @endif
</form>

<div class="card">
    <div class="card-head"><div><h2>{{ __('company.gateway_routing_preview') }}</h2><p>{{ __('company.gateway_routing_preview_hint') }}</p></div></div>
    <form method="POST" action="{{ route('company.numbers.routing.preview', ['gateway' => $gateway, 'line' => $line]) }}">
        @csrf
        <div class="form-grid">
            <div class="form-field"><label for="pv-date">{{ __('company.gateway_routing_preview_date') }}</label><input id="pv-date" type="date" name="preview_date" value="{{ old('preview_date', now()->setTimezone($timezone)->format('Y-m-d')) }}" required></div>
            <div class="form-field"><label for="pv-time">{{ __('company.gateway_routing_preview_time') }}</label><input id="pv-time" type="time" name="preview_time" value="{{ old('preview_time', '10:00') }}" required></div>
            <div class="form-field"><button type="submit" class="btn-secondary">{{ __('company.gateway_routing_preview_run') }}</button></div>
        </div>
        <p class="section-note">{{ __('company.gateway_routing_preview_saved_note') }}</p>
    </form>

    @if ($preview)
        <div class="table-wrap">
            <table>
                <tbody>
                    <tr><th>{{ __('company.gateway_routing_preview_at') }}</th><td class="code-note">{{ $preview['at'] }} ({{ $preview['timezone'] }})</td></tr>
                    <tr><th>{{ __('company.gateway_routing_preview_rule') }}</th><td>{{ __('company.gateway_routing_rule_'.$preview['matched_rule']) }}</td></tr>
                    <tr>
                        <th>{{ __('company.gateway_routing_preview_destination') }}</th>
                        <td>
                            @if ($preview['destination'])
                                {{ $preview['destination'] }}
                                @if ($preview['destination_extension']) · {{ $preview['destination_extension'] }} @endif
                            @else
                                <span class="status failed">{{ __('company.gateway_routing_rule_none') }}</span>
                                — {{ __('company.gateway_routing_reason_'.$preview['reason']) }}
                            @endif
                            @if ($preview['rule'] === 'fallback')
                                <br><small>{{ __('company.gateway_routing_preview_used_fallback') }}</small>
                            @endif
                            @if ($preview['exception_label'])
                                <br><small>{{ __('company.gateway_routing_preview_exception') }}: {{ $preview['exception_label'] }}</small>
                            @endif
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    @endif
</div>

<template id="tpl-window">
    <div class="form-grid" data-routing-row>
        <div class="form-field"><label>{{ __('company.day') }}</label>
            <select name="business_hours[0][day]">
                @foreach ($weekdays as $weekday)
                    <option value="{{ $weekday }}">{{ __('company.day_'.$weekday) }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-field"><label>{{ __('company.start_time') }}</label><input type="time" name="business_hours[0][start]" value="09:00"></div>
        <div class="form-field"><label>{{ __('company.end_time') }}</label><input type="time" name="business_hours[0][end]" value="17:00"></div>
        <button type="button" class="btn-quiet" data-routing-remove>{{ __('company.remove_day') }}</button>
    </div>
</template>

<template id="tpl-exception">
    <div class="form-grid" data-routing-row>
        <div class="form-field"><label>{{ __('company.gateway_routing_exception_label') }}</label><input type="text" name="exceptions[0][label]" value=""></div>
        <div class="form-field"><label>{{ __('company.gateway_routing_exception_starts') }}</label><input type="date" name="exceptions[0][starts_on]" value=""></div>
        <div class="form-field"><label>{{ __('company.gateway_routing_exception_ends') }}</label><input type="date" name="exceptions[0][ends_on]" value=""></div>
        <div class="form-field"><label>{{ __('company.destination') }}</label>
            <select name="exceptions[0][destination_extension_id]">
                <option value="">{{ __('company.none') }}</option>
                @foreach ($destinations as $destination)
                    <option value="{{ $destination->id }}">{{ $destination->extension }}{{ $destination->label ? ' — '.$destination->label : '' }}</option>
                @endforeach
            </select>
        </div>
        <button type="button" class="btn-quiet" data-routing-remove>{{ __('company.remove_day') }}</button>
    </div>
</template>
@endsection

@push('scripts')
<script>
(function () {
    'use strict';

    function reindex(container) {
        var key = container.getAttribute('data-routing-rows');
        container.querySelectorAll('[data-routing-row]').forEach(function (row, index) {
            row.querySelectorAll('[name]').forEach(function (node) {
                node.setAttribute('name', node.getAttribute('name').replace(/^([a-z_]+)\[[^\]]*\]/, '$1[' + index + ']'));
            });
        });
    }

    document.addEventListener('click', function (event) {
        var add = event.target.closest('[data-routing-add]');
        if (add) {
            event.preventDefault();
            var template = document.getElementById(add.getAttribute('data-routing-template'));
            var container = document.getElementById(add.getAttribute('data-routing-add'));
            if (!template || !container) { return; }
            var holder = document.createElement('div');
            holder.innerHTML = template.innerHTML;
            var row = holder.firstElementChild;
            if (row) {
                container.appendChild(row);
                reindex(container);
            }
            return;
        }

        var remove = event.target.closest('[data-routing-remove]');
        if (remove) {
            event.preventDefault();
            var target = remove.closest('[data-routing-row]');
            var parent = target ? target.parentNode : null;
            if (target && parent) {
                parent.removeChild(target);
                reindex(parent);
            }
        }
    });
})();
</script>
@endpush
