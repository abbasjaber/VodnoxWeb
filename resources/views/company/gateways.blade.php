@extends('layouts.company')

@section('title', __('company.gateways_title'))
@section('crumb', __('company.gateways_title'))

@php
    $maxLines = 0;
    foreach ($profiles as $profile) {
        $maxLines = max($maxLines, (int) ($profile['lines'] ?? 1));
    }
    $maxLines = max($maxLines, 1);

    $statusKey = fn (?string $state) => 'company.gateway_provisioning_'.($state ?: 'pending');
    $registrationKey = fn (?string $state) => 'company.gateway_registration_'.($state ?: 'unknown');

    // The GoIP guide table. Values marked with a callback are resolved per line.
    $guideRows = [
        [__('company.gateway_guide_lan'), __('company.gateway_guide_lan_value')],
        [__('company.gateway_guide_endpoint_type'), __('company.gateway_guide_endpoint_type_value')],
        [__('company.gateway_guide_config_mode'), __('company.gateway_guide_config_mode_value')],
        [__('company.gateway_guide_auth_id'), fn ($line) => $line?->sip_username ?: '—'],
        [__('company.gateway_guide_password'), __('company.gateway_guide_password_value')],
        [__('company.gateway_guide_proxy'), $serverSettings['host'].':'.$serverSettings['port']],
        [__('company.gateway_guide_reregister'), $serverSettings['register_interval'].'s'],
        [__('company.gateway_guide_phone_number'), fn ($line) => $line?->sip_username ?: '—'],
        [__('company.gateway_guide_home_domain'), $serverSettings['domain']],
        [__('company.gateway_guide_outbound_proxy'), __('company.gateway_guide_empty')],
        [__('company.gateway_guide_call_in_gsm'), __('company.gateway_guide_enable')],
        [__('company.gateway_guide_forward_number'), fn ($line) => $line?->inbound_number ?: __('company.gateway_guide_forward_value')],
        [__('company.gateway_guide_cid_mode'), __('company.gateway_guide_cid_mode_value')],
        [__('company.gateway_guide_media_encryption'), __('company.gateway_guide_none')],
        [__('company.gateway_guide_nat'), __('company.gateway_guide_none')],
        [__('company.gateway_guide_codec'), __('company.gateway_guide_codec_value')],
    ];

    $sampleLine = $devices->first()?->lines->first();
@endphp

@section('content')
@include('company._assets')
@include('company._alerts')

<div class="page-head">
    <div>
        <h1>{{ __('company.gateways_title') }}</h1>
        <p>{{ __('company.gateways_subtitle') }}</p>
    </div>
    @if ($canManage)
        <div class="head-actions">
            <button type="button" class="btn-primary"
                    data-company-modal
                    data-company-title="{{ __('company.gateway_new') }}"
                    data-company-action="{{ route('company.gateways.store') }}"
                    data-company-submit="{{ __('company.save') }}"
                    data-company-template="tpl-gateway-create">{{ __('company.gateway_new') }}</button>
        </div>
    @endif
</div>

@unless ($supportsGateways)
    <div class="section-note" role="alert">{{ __('company.gateway_agent_old') }}</div>
@endunless

@if ($simulated)
    <div class="section-note">{{ __('company.gateway_simulated') }}</div>
@endif

<p class="section-note">{{ __('company.gateway_inbound_only') }}</p>

<div class="card">
    <div class="card-head">
        <div><h2>{{ __('company.gateway_server_title') }}</h2><p>{{ __('company.gateway_server_note') }}</p></div>
    </div>
    <div class="table-wrap">
        <table>
            <tbody>
                <tr><th>{{ __('company.gateway_server_host') }}</th><td class="code-note">{{ $serverSettings['host'] ?: '—' }}</td></tr>
                <tr><th>{{ __('company.gateway_server_port') }}</th><td class="code-note">{{ $serverSettings['port'] }}</td></tr>
                <tr><th>{{ __('company.gateway_server_transport') }}</th><td class="code-note">{{ strtoupper($serverSettings['transport']) }}</td></tr>
                <tr><th>{{ __('company.gateway_server_domain') }}</th><td class="code-note">{{ $serverSettings['domain'] ?: '—' }}</td></tr>
                <tr><th>{{ __('company.gateway_server_register') }}</th><td class="code-note">{{ $serverSettings['register_interval'] }}</td></tr>
            </tbody>
        </table>
    </div>
</div>

@forelse ($devices as $device)
    <div class="card">
        <div class="card-head">
            <div>
                <h2>{{ $device->label }}</h2>
                <p>
                    {{ $device->model }}
                    @if ($device->firmware) · {{ $device->firmware }} @endif
                    · <span class="status {{ $device->enabled ? 'active' : 'inactive' }}">{{ $device->enabled ? __('company.gateway_enabled') : __('company.gateway_disabled') }}</span>
                </p>
            </div>
            <div class="table-actions">
                <form method="POST" action="{{ route('company.gateways.refresh', $device) }}">
                    @csrf
                    <button type="submit" class="row-link">{{ __('company.gateway_refresh_status') }}</button>
                </form>
                @if ($canManage)
                    <form method="POST" action="{{ route('company.gateways.retry', $device) }}">
                        @csrf
                        <button type="submit" class="row-link">{{ __('company.gateway_retry') }}</button>
                    </form>
                    <form method="POST" action="{{ route('company.gateways.toggle', $device) }}">
                        @csrf
                        <button type="submit" class="row-link">{{ $device->enabled ? __('company.gateway_disable') : __('company.gateway_enable') }}</button>
                    </form>
                    <form method="POST" action="{{ route('company.gateways.destroy', $device) }}" data-confirm="{{ __('company.confirm_delete') }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="row-link">{{ __('company.delete') }}</button>
                    </form>
                @endif
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>{{ __('company.gateway_line') }}</th>
                        <th>{{ __('company.gateway_inbound_number') }}</th>
                        <th>{{ __('company.gateway_sip_identity') }}</th>
                        <th>{{ __('company.gateway_inbound_destination') }}</th>
                        <th>{{ __('company.gateway_provisioning_state') }}</th>
                        <th>{{ __('company.gateway_registration_state') }}</th>
                        <th>{{ __('company.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($device->lines as $line)
                        <tr>
                            <td>
                                <strong>#{{ $line->line_index }}</strong>
                                @if ($line->label) · {{ $line->label }} @endif
                                @if ($line->sim_number) <br><small>{{ $line->sim_number }}</small> @endif
                            </td>
                            <td class="code-note">{{ $line->inbound_number ?: '—' }}</td>
                            <td class="code-note">
                                {{ $line->sip_username ?: '—' }}
                                @if ($canViewSecret)
                                    <br><small>{{ __('company.gateway_secret_note') }}</small>
                                @endif
                            </td>
                            <td>
                                @if ($line->destination)
                                    {{ $line->destination->extension }}
                                    @if ($line->destination->label) · {{ $line->destination->label }} @endif
                                @else
                                    —
                                @endif
                            </td>
                            <td>
                                <span class="status {{ $line->provisioning_status }}">{{ __($statusKey($line->provisioning_status)) }}</span>
                                @if ($line->provisioning_error)
                                    <br><small>{{ __('company.gateway_provisioning_failed_reason') }}: {{ $line->provisioning_error }}</small>
                                @endif
                            </td>
                            <td>
                                <span class="status {{ $line->registrationLabel() }}">{{ __($registrationKey($line->registrationLabel())) }}</span>
                                <br><small>{{ __('company.gateway_observed_at') }}: {{ $line->registration_observed_at?->format('Y-m-d H:i') ?? '—' }}</small>
                            </td>
                            <td>
                                <div class="table-actions">
                                    @if ($canManage)
                                        <button type="button" class="row-link"
                                                data-company-modal
                                                data-company-title="{{ __('company.gateway_edit') }}"
                                                data-company-action="{{ route('company.gateways.lines.update', [$device, $line]) }}"
                                                data-company-submit="{{ __('company.save') }}"
                                                data-company-template="tpl-gateway-line-edit-{{ $line->id }}">{{ __('company.edit') }}</button>
                                    @endif
                                    @if ($canRotateSecret)
                                        <form method="POST" action="{{ route('company.gateways.lines.rotate', [$device, $line]) }}" data-confirm="{{ __('company.confirm_delete') }}">
                                            @csrf
                                            <button type="submit" class="row-link">{{ __('company.gateway_rotate_secret') }}</button>
                                        </form>
                                    @endif
                                    @if ($canViewSecret)
                                        <button type="button" class="row-link"
                                                data-gateway-secret
                                                data-gateway-secret-url="{{ route('company.gateways.lines.secret', [$device, $line]) }}"
                                                data-gateway-secret-target="secret-{{ $line->id }}">{{ __('company.gateway_show_secret') }}</button>
                                    @endif
                                </div>
                                @if ($canViewSecret)
                                    <div class="code-note" id="secret-{{ $line->id }}" hidden></div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@empty
    <div class="card"><p class="empty-row">{{ __('company.gateway_no_devices') }}</p></div>
@endforelse

<div class="card">
    <div class="card-head">
        <div><h2>{{ __('company.gateway_guide_title') }}</h2><p>{{ __('company.gateway_guide_intro') }}</p></div>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>{{ __('company.gateway_guide_setting') }}</th>
                    <th>{{ __('company.gateway_guide_value') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($guideRows as [$label, $value])
                    <tr>
                        <th>{{ $label }}</th>
                        <td class="code-note">{{ is_callable($value) ? $value($sampleLine) : $value }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <p class="section-note">{{ __('company.gateway_guide_rtp_note') }}</p>
    <p class="section-note">{{ __('company.gateway_guide_voip_flag') }}</p>
    <p class="section-note">{{ __('company.gateway_guide_fortigate') }}</p>
</div>

{{-- Step 1: device / Step 2: incoming route / Step 3: binding --}}
<template id="tpl-gateway-create">
    @csrf
    <div class="section-note">{{ __('company.gateway_step_device') }}</div>
    <div class="form-grid">
        <div class="form-field"><label for="gw-label">{{ __('company.gateway_label') }}</label><input id="gw-label" type="text" name="label" value="{{ old('label') }}" required></div>
        <div class="form-field"><label for="gw-model">{{ __('company.gateway_model') }}</label>
            <select id="gw-model" name="model" data-gateway-model>
                @foreach ($profiles as $key => $profile)
                    <option value="{{ $key }}" data-lines="{{ (int) ($profile['lines'] ?? 1) }}" @selected(old('model') === $key)>{{ __(($profile['label_'.app()->getLocale()] ?? null) ?: ($profile['label_en'] ?? $key)) }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-field"><label for="gw-firmware">{{ __('company.gateway_firmware') }}</label><input id="gw-firmware" type="text" name="firmware" value="{{ old('firmware') }}"></div>
        <div class="form-field"><label for="gw-notes">{{ __('company.gateway_network_notes') }}</label><input id="gw-notes" type="text" name="network_notes" value="{{ old('network_notes') }}"></div>
    </div>

    <div class="section-note">{{ __('company.gateway_step_route') }} — {{ __('company.gateway_destination_hint') }}</div>
    <div id="gw-lines" data-gateway-lines>
        @for ($index = 0; $index < $maxLines; $index++)
            <div class="form-grid" data-company-row>
                <div class="form-field"><label>{{ __('company.gateway_line') }} #{{ $index + 1 }}</label><input type="text" name="lines[{{ $index }}][label]" value="{{ old('lines.'.$index.'.label') }}"></div>
                <div class="form-field"><label>{{ __('company.gateway_sim_number') }}</label><input type="text" name="lines[{{ $index }}][sim_number]" value="{{ old('lines.'.$index.'.sim_number') }}"></div>
                <div class="form-field"><label>{{ __('company.gateway_inbound_destination') }}</label>
                    <select name="lines[{{ $index }}][inbound_extension_id]" required>
                        <option value="">{{ __('company.none') }}</option>
                        @foreach ($destinations as $destination)
                            <option value="{{ $destination->id }}" @selected(old('lines.'.$index.'.inbound_extension_id') == $destination->id)>
                                {{ $destination->extension }}{{ $destination->label ? ' — '.$destination->label : '' }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>
        @endfor
    </div>

    <div class="section-note">{{ __('company.gateway_step_binding') }} — {{ __('company.gateway_binding_hint') }}</div>
    <div class="section-note">{{ __('company.gateway_step_verify') }} — {{ __('company.gateway_inbound_only') }}</div>
</template>

@foreach ($devices as $device)
    @foreach ($device->lines as $line)
        <template id="tpl-gateway-line-edit-{{ $line->id }}">
            @csrf
            @method('PUT')
            <div class="form-grid">
                <div class="form-field"><label for="gle{{ $line->id }}-label">{{ __('company.gateway_line') }} #{{ $line->line_index }}</label><input id="gle{{ $line->id }}-label" type="text" name="label" value="{{ $line->label }}"></div>
                <div class="form-field"><label for="gle{{ $line->id }}-sim">{{ __('company.gateway_sim_number') }}</label><input id="gle{{ $line->id }}-sim" type="text" name="sim_number" value="{{ $line->sim_number }}"></div>
                <div class="form-field"><label for="gle{{ $line->id }}-dest">{{ __('company.gateway_inbound_destination') }}</label>
                    <select id="gle{{ $line->id }}-dest" name="inbound_extension_id" required>
                        @foreach ($destinations as $destination)
                            <option value="{{ $destination->id }}" @selected($line->inbound_extension_id === $destination->id)>
                                {{ $destination->extension }}{{ $destination->label ? ' — '.$destination->label : '' }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>
            <p class="section-note">{{ __('company.gateway_destination_hint') }}</p>
        </template>
    @endforeach
@endforeach
@endsection

@push('scripts')
<script>
(function () {
    'use strict';

    /* Keep the line blocks in sync with the number of lines of the chosen model. */
    var model = document.querySelector('[data-gateway-model]');
    var container = document.querySelector('[data-gateway-lines]');
    function syncLines() {
        if (!model || !container) { return; }
        var option = model.options[model.selectedIndex];
        var wanted = parseInt((option && option.getAttribute('data-lines')) || '1', 10);
        var rows = container.querySelectorAll('[data-company-row]');
        rows.forEach(function (row, index) {
            var disabled = index >= wanted;
            row.hidden = disabled;
            row.querySelectorAll('[name]').forEach(function (node) { node.disabled = disabled; });
        });
    }
    if (model) {
        model.addEventListener('change', syncLines);
        syncLines();
    }

    /* Reveal a SIP secret on demand; never cached by the browser. */
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-gateway-secret]');
        if (!button) { return; }
        event.preventDefault();
        var target = document.getElementById(button.getAttribute('data-gateway-secret-target'));
        if (!target) { return; }

        if (!target.hidden) {
            target.hidden = true;
            target.textContent = '';
            button.textContent = @json(__('company.gateway_show_secret'));
            return;
        }

        fetch(button.getAttribute('data-gateway-secret-url'), {
            headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            credentials: 'same-origin',
            cache: 'no-store'
        }).then(function (response) {
            if (!response.ok) { throw new Error('forbidden'); }
            return response.json();
        }).then(function (payload) {
            target.textContent = payload.sip_username + ' / ' + payload.sip_password
                + ' @ ' + payload.sip_host + ':' + payload.sip_port + ' (' + payload.sip_transport + ')';
            target.hidden = false;
            button.textContent = @json(__('company.gateway_hide_secret'));
        }).catch(function () {
            target.textContent = @json(__('company.gateway_secret_forbidden'));
            target.hidden = false;
        });
    });
})();
</script>
@endpush
