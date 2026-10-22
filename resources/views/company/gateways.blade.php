@extends('layouts.company')

@section('title', __('company.gateways_title'))
@section('crumb', __('company.gateways_title'))

@php
    $maxLines = 0;
    foreach ($profiles as $profile) {
        $maxLines = max($maxLines, (int) ($profile['lines'] ?? 1));
    }
    $maxLines = max($maxLines, 1);

    $portStateKey = fn (string $state) => 'company.gateway_port_'.($state ?: 'unknown');
    $stateTone = [
        'unused' => 'inactive',
        'configuring' => 'pending',
        'configured' => 'pending',
        'registered' => 'active',
        'unregistered' => 'inactive',
        'unknown' => 'inactive',
        'blocked' => 'failed',
    ];
@endphp

@section('content')
@include('company._assets')
@include('company._alerts')

<div class="page-head">
    <div>
        <h1>{{ __('company.gateways_title') }}</h1>
        <p>{{ __('company.gateways_subtitle') }}</p>
    </div>
    <div class="head-actions">
        @if ($canManage)
            <button type="button" class="btn-primary"
                    data-company-modal
                    data-company-title="{{ __('company.gateway_new') }}"
                    data-company-action="{{ route('company.gateways.store') }}"
                    data-company-submit="{{ __('company.save') }}"
                    data-company-template="tpl-gateway-create">{{ __('company.gateway_new') }}</button>
        @endif
    </div>
</div>

@include('company._technical-bar')

{{--
    Only states that need the operator's attention keep a coloured note: an
    agent too old for gateway or routing support, and a workspace running
    against the simulation instead of the live agent.
--}}
@unless ($supportsGateways)
    <div class="section-note" role="alert">{{ __('company.gateway_agent_old') }}</div>
@endunless

@unless ($supportsRouting)
    <div class="section-note" role="alert">{{ __('company.gateway_routing_agent_old') }}</div>
@endunless

@if ($simulated)
    <div class="section-note">{{ __('company.gateway_simulated') }}</div>
@endif

@forelse ($devices as $device)
    @php
        $used = $device->usedLinesCount();
        $available = $device->availableLinesCount();
        $profileLabel = __(
            ($profiles[$device->model]['label_'.app()->getLocale()] ?? null)
            ?: ($profiles[$device->model]['label_en'] ?? $device->model)
        );
    @endphp
    <div class="card">
        <div class="device-head">
            <div class="device-title">
                <h2>{{ $device->label }}</h2>
                <p class="device-meta">
                    {{ $profileLabel }}
                    @if ($device->firmware) · {{ $device->firmware }} @endif
                </p>
            </div>
            <span class="status {{ $device->enabled ? 'active' : 'inactive' }}">{{ $device->enabled ? __('company.gateway_enabled') : __('company.gateway_disabled') }}</span>
        </div>

        {{-- Provisioning, registration and the port count on one aligned row. --}}
        <div class="device-facts">
            <span class="device-fact">
                <small>{{ __('company.gateway_provisioning_state') }}</small>
                <span class="status {{ $device->provisioning_status }}">{{ __('company.gateway_provisioning_'.($device->provisioning_status ?: 'pending')) }}</span>
            </span>
            <span class="device-fact">
                <small>{{ __('company.gateway_registration_state') }}</small>
                <span class="status {{ $device->registrationLabel() }}">{{ __('company.gateway_registration_'.$device->registrationLabel()) }}</span>
            </span>
            <span class="device-fact port-stat">
                <small>{{ __('company.gateway_ports_used') }}</small>
                <b>{{ $used }} / {{ $device->max_lines }}</b>
                <em>{{ __('company.gateway_ports_available') }}: {{ $available }}</em>
            </span>
        </div>

        {{-- Everyday actions; disabling and deleting sit apart in their own
             group so they are never clicked by accident. --}}
        <div class="device-actions">
            <div class="action-group">
                <a class="btn-secondary btn-sm" href="{{ route('company.gateways.settings', $device) }}">{{ __('company.gateway_settings_link') }}</a>
                <button type="button" class="btn-secondary btn-sm"
                        data-company-modal
                        data-company-method="get"
                        data-company-title="{{ __('company.gateway_guide_dialog_title') }}"
                        data-company-action="{{ route('company.gateways.setup-guide', $device) }}"
                        data-company-submit="{{ __('company.gateway_guide_download_short') }}"
                        data-company-template="tpl-gateway-guide">{{ __('company.gateway_guide_download') }}</button>
                <form method="POST" action="{{ route('company.gateways.refresh', $device) }}">
                    @csrf
                    <button type="submit" class="btn-secondary btn-sm">{{ __('company.gateway_refresh_status') }}</button>
                </form>
                @if ($canManage)
                    <form method="POST" action="{{ route('company.gateways.retry', $device) }}">
                        @csrf
                        <button type="submit" class="btn-secondary btn-sm">{{ __('company.gateway_retry') }}</button>
                    </form>
                @endif
            </div>
            @if ($canManage)
                <div class="action-group danger">
                    <form method="POST" action="{{ route('company.gateways.toggle', $device) }}">
                        @csrf
                        <button type="submit" class="btn-secondary btn-sm">{{ $device->enabled ? __('company.gateway_disable') : __('company.gateway_enable') }}</button>
                    </form>
                    <form method="POST" action="{{ route('company.gateways.destroy', $device) }}" data-confirm="{{ __('company.confirm_delete') }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn-danger btn-sm">{{ __('company.delete') }}</button>
                    </form>
                </div>
            @endif
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>{{ __('company.gateway_port') }}</th>
                        <th>{{ __('company.gateway_line_label') }}</th>
                        <th>{{ __('company.gateway_sim_number') }}</th>
                        <th>{{ __('company.gateway_port_state') }}</th>
                        <th>{{ __('company.gateway_observed_at') }}</th>
                        <th>{{ __('company.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($device->lines as $line)
                        @php $state = $line->portState(); @endphp
                        <tr>
                            <td><strong>#{{ $line->line_index }}</strong></td>
                            <td>{{ $line->label ?: '—' }}</td>
                            <td class="code-note">{{ $line->sim_number ?: '—' }}</td>
                            <td>
                                <span class="status {{ $stateTone[$state] ?? 'inactive' }}">{{ __($portStateKey($state)) }}</span>
                                @if ($line->provisioning_error)
                                    <br><small>{{ __('company.gateway_provisioning_failed_reason') }}: {{ $line->provisioning_error }}</small>
                                @endif
                            </td>
                            <td class="code-note">
                                {{ $line->registration_observed_at?->format('Y-m-d H:i') ?? '—' }}
                                <br><small>{{ __('company.gateway_registration_'.$line->registrationLabel()) }}</small>
                            </td>
                            <td>
                                <div class="table-actions">
                                    <a class="row-link" href="{{ route('company.numbers.routing.edit', ['gateway' => $device, 'line' => $line]) }}">{{ __('company.gateway_manage_routing') }}</a>
                                    @if ($canManage)
                                        <button type="button" class="row-link"
                                                data-company-modal
                                                data-company-title="{{ __('company.gateway_edit_port') }}"
                                                data-company-action="{{ route('company.gateways.lines.update', [$device, $line]) }}"
                                                data-company-submit="{{ __('company.save') }}"
                                                data-company-template="tpl-gateway-line-edit-{{ $line->id }}">{{ __('company.edit') }}</button>
                                    @endif
                                    @if ($canRotateSecret)
                                        <form method="POST" action="{{ route('company.gateways.lines.rotate', [$device, $line]) }}" data-confirm="{{ __('company.gateway_rotate_confirm') }}">
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

{{-- Adding a device is deliberately short: label, model, optional details and
     the ports that carry a SIM. No destination, employee or schedule is asked
     for here — that belongs to the numbers & routing workspace. --}}
<template id="tpl-gateway-create">
    @csrf
    <div class="form-grid">
        <div class="form-field"><label for="gw-label">{{ __('company.gateway_label') }}</label><input id="gw-label" type="text" name="label" value="{{ old('label') }}" required></div>
        <div class="form-field"><label for="gw-model">{{ __('company.gateway_model') }}</label>
            <select id="gw-model" name="model" data-gateway-model>
                @foreach ($profiles as $key => $profile)
                    <option value="{{ $key }}" data-lines="{{ (int) ($profile['lines'] ?? 1) }}" @selected(old('model') === $key)>{{ __(($profile['label_'.app()->getLocale()] ?? null) ?: ($profile['label_en'] ?? $key)) }}</option>
                @endforeach
            </select>
            <small class="field-help">
                @foreach ($profiles as $key => $profile)
                    @if (! empty($profile['notes_'.app()->getLocale()]))
                        <span data-gateway-note="{{ $key }}" hidden>{{ __($profile['notes_'.app()->getLocale()]) }}</span>
                    @endif
                @endforeach
            </small>
        </div>
    </div>

    <p class="note-plain">{{ __('company.gateway_ports_hint') }}</p>
    <div id="gw-lines" data-gateway-lines>
        @for ($index = 0; $index < $maxLines; $index++)
            <div class="form-grid" data-company-row>
                <div class="form-field"><label>{{ __('company.gateway_port') }} #{{ $index + 1 }}</label></div>
                <div class="form-field"><label>{{ __('company.gateway_line_label') }}</label><input type="text" name="lines[{{ $index }}][label]" value="{{ old('lines.'.$index.'.label') }}"></div>
                <div class="form-field"><label>{{ __('company.gateway_sim_number') }}</label><input type="text" name="lines[{{ $index }}][sim_number]" value="{{ old('lines.'.$index.'.sim_number') }}"></div>
                <div class="form-field"><label>{{ __('company.gateway_port_in_use') }}</label><input type="checkbox" name="lines[{{ $index }}][enabled]" value="1" @checked(old('lines.'.$index.'.enabled'))></div>
            </div>
        @endfor
    </div>

    <details>
        <summary>{{ __('company.gateway_advanced') }}</summary>
        <div class="form-grid">
            <div class="form-field"><label for="gw-firmware">{{ __('company.gateway_firmware') }}</label><input id="gw-firmware" type="text" name="firmware" value="{{ old('firmware') }}"></div>
            <div class="form-field"><label for="gw-notes">{{ __('company.gateway_network_notes') }}</label><input id="gw-notes" type="text" name="network_notes" value="{{ old('network_notes') }}"></div>
        </div>
    </details>

    <p class="note-plain">{{ __('company.gateway_after_create_note') }}</p>
</template>

{{--
    The setup-guide dialog: one small window with the include-passwords option
    (unchecked by default) and a clear download button. The dialog posts
    nothing — it is a plain GET to the export route, so the file is streamed
    straight back and never cached.
--}}
<template id="tpl-gateway-guide">
    @if ($canViewSecret)
        <label class="guide-option is-off">
            <input type="checkbox" name="include_passwords" value="1">
            <span>
                <b>{{ __('company.gateway_guide_include_passwords') }}</b>
                <small>{{ __('company.gateway_guide_include_passwords_hint') }}</small>
            </span>
        </label>
    @endif
    <p class="note-plain">{{ __('company.gateway_guide_note') }}</p>
</template>

@foreach ($devices as $device)
    @foreach ($device->lines as $line)
        <template id="tpl-gateway-line-edit-{{ $line->id }}">
            @csrf
            @method('PUT')
            <div class="form-grid">
                <div class="form-field"><label for="gle{{ $line->id }}-label">{{ __('company.gateway_port') }} #{{ $line->line_index }} — {{ __('company.gateway_line_label') }}</label><input id="gle{{ $line->id }}-label" type="text" name="label" value="{{ $line->label }}"></div>
                <div class="form-field"><label for="gle{{ $line->id }}-sim">{{ __('company.gateway_sim_number') }}</label><input id="gle{{ $line->id }}-sim" type="text" name="sim_number" value="{{ $line->sim_number }}"></div>
                <div class="form-field">
                    <label for="gle{{ $line->id }}-enabled">{{ __('company.gateway_port_in_use') }}</label>
                    <input id="gle{{ $line->id }}-enabled" type="checkbox" name="enabled" value="1" @checked($line->enabled)>
                </div>
            </div>
            <p class="note-plain">{{ __('company.gateway_port_in_use_hint') }}</p>
        </template>
    @endforeach
@endforeach
@endsection

@push('scripts')
<script>
(function () {
    'use strict';

    /*
     * The device fields live inside <template id="tpl-gateway-create">, so they
     * are not part of the document until the dialog is opened and its body is
     * filled from the template. Querying them at load time therefore returned
     * nothing, the sync never ran, and every port block stayed visible even for
     * a one-port GoIP.
     *
     * The port blocks are now synced from the *open* dialog: as soon as its
     * body is populated (and again after a failed validation round trip), then
     * on every model change through event delegation scoped to that dialog body.
     */
    function syncLines(body) {
        var model = body.querySelector('[data-gateway-model]');
        var container = body.querySelector('[data-gateway-lines]');
        if (!model || !container) { return; }

        var option = model.options[model.selectedIndex];
        var wanted = parseInt((option && option.getAttribute('data-lines')) || '1', 10);
        if (!(wanted >= 1)) { wanted = 1; }

        /* Only the ports of the chosen profile are shown, and the extra blocks
           are disabled as well as hidden so they can never be submitted. */
        container.querySelectorAll('[data-company-row]').forEach(function (row, index) {
            var unused = index >= wanted;
            row.hidden = unused;
            row.querySelectorAll('[name]').forEach(function (node) { node.disabled = unused; });
        });

        /* The note under the select follows the chosen device too. */
        body.querySelectorAll('[data-gateway-note]').forEach(function (note) {
            note.hidden = note.getAttribute('data-gateway-note') !== model.value;
        });
    }

    var modalBody = document.getElementById('modalBody');

    if (modalBody) {
        /* The dialog body is replaced on every open, so the initial state is
           re-applied whenever its content changes — including when a rejected
           form comes back with its old input. */
        new MutationObserver(function () { syncLines(modalBody); })
            .observe(modalBody, {childList: true});

        /* Delegated: the model <select> is re-created on every open, so a
           listener bound to the node itself would not survive a reopen. */
        modalBody.addEventListener('change', function (event) {
            if (event.target.closest('[data-gateway-model]')) { syncLines(modalBody); }
        });
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
