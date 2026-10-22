@extends('layouts.company')

@section('title', __('company.gateway_settings_title'))
@section('crumb', $gateway->label)

@php
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
    $sampleLine = $gateway->lines->first();
@endphp

@section('content')
@include('company._assets')
@include('company._alerts')

<div class="page-head">
    <div>
        <h1>{{ __('company.gateway_settings_title') }} — {{ $gateway->label }}</h1>
        <p>{{ __('company.gateway_settings_subtitle') }}</p>
    </div>
    <div class="head-actions">
        <button type="button" class="btn-primary"
                data-company-modal
                data-company-method="get"
                data-company-title="{{ __('company.gateway_guide_dialog_title') }}"
                data-company-action="{{ route('company.gateways.setup-guide', $gateway) }}"
                data-company-submit="{{ __('company.gateway_guide_download_short') }}"
                data-company-template="tpl-gateway-guide">{{ __('company.gateway_guide_download') }}</button>
        <a class="btn-secondary" href="{{ route('company.gateways.index') }}">{{ __('company.back') }}</a>
    </div>
</div>

@include('company._technical-bar')

@if (! empty($profile['notes_'.app()->getLocale()]))
    <p class="note-plain">{{ __($profile['notes_'.app()->getLocale()]) }}</p>
@endif

@unless ($supportsRouting)
    <div class="section-note" role="alert">{{ __('company.gateway_routing_agent_old') }}</div>
@endunless

<div class="card">
    <div class="card-head">
        <div><h2>{{ __('company.gateway_shared_settings') }}</h2><p>{{ __('company.gateway_shared_settings_note') }}</p></div>
    </div>
    <div class="table-wrap">
        <table>
            <tbody>
                <tr><th>{{ __('company.gateway_server_host') }}</th><td class="code-note"><span data-copy-value>{{ $serverSettings['host'] ?: '—' }}</span> <button type="button" class="row-link" data-copy>{{ __('company.copy') }}</button></td></tr>
                <tr><th>{{ __('company.gateway_server_port') }}</th><td class="code-note"><span data-copy-value>{{ $serverSettings['port'] }}</span> <button type="button" class="row-link" data-copy>{{ __('company.copy') }}</button></td></tr>
                <tr><th>{{ __('company.gateway_server_transport') }}</th><td class="code-note"><span data-copy-value>{{ strtoupper($serverSettings['transport']) }}</span> <button type="button" class="row-link" data-copy>{{ __('company.copy') }}</button></td></tr>
                <tr><th>{{ __('company.gateway_server_domain') }}</th><td class="code-note"><span data-copy-value>{{ $serverSettings['domain'] ?: '—' }}</span> <button type="button" class="row-link" data-copy>{{ __('company.copy') }}</button></td></tr>
                <tr><th>{{ __('company.gateway_server_register') }}</th><td class="code-note"><span data-copy-value>{{ $serverSettings['register_interval'] }}</span> <button type="button" class="row-link" data-copy>{{ __('company.copy') }}</button></td></tr>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <div><h2>{{ __('company.gateway_port_settings') }}</h2><p>{{ __('company.gateway_port_settings_note') }}</p></div>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>{{ __('company.gateway_port') }}</th>
                    <th>{{ __('company.gateway_line_label') }}</th>
                    <th>{{ __('company.gateway_guide_auth_id') }}</th>
                    <th>{{ __('company.gateway_guide_password') }}</th>
                    <th>{{ __('company.gateway_guide_phone_number') }}</th>
                    <th>{{ __('company.gateway_inbound_number') }}</th>
                    <th>{{ __('company.gateway_port_state') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($gateway->lines as $line)
                    @php $state = $line->portState(); @endphp
                    <tr>
                        <td><strong>{{ __('company.gateway_port') }} #{{ $line->line_index }}</strong></td>
                        <td>{{ $line->label ?: '—' }}</td>
                        <td class="code-note">
                            <span data-copy-value>{{ $line->sip_username ?: '—' }}</span>
                            <button type="button" class="row-link" data-copy>{{ __('company.copy') }}</button>
                        </td>
                        <td>
                            @if ($canViewSecret)
                                <button type="button" class="row-link"
                                        data-gateway-secret
                                        data-gateway-secret-url="{{ route('company.gateways.lines.secret', [$gateway, $line]) }}"
                                        data-gateway-secret-target="secret-{{ $line->id }}">{{ __('company.gateway_show_secret') }}</button>
                                <div class="code-note" id="secret-{{ $line->id }}" hidden></div>
                            @else
                                <small>{{ __('company.gateway_secret_forbidden') }}</small>
                            @endif
                        </td>
                        <td class="code-note">
                            <span data-copy-value>{{ $line->sip_username ?: '—' }}</span>
                            <button type="button" class="row-link" data-copy>{{ __('company.copy') }}</button>
                        </td>
                        <td class="code-note">{{ $line->inbound_number ?: '—' }}</td>
                        <td><span class="status {{ $line->enabled ? 'active' : 'inactive' }}">{{ __('company.gateway_port_'.$state) }}</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <p class="note-plain">{{ __('company.gateway_port_settings_hint') }}</p>
</div>

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
    <p class="note-plain">{{ __('company.gateway_guide_rtp_note') }}</p>
    <p class="note-plain">{{ __('company.gateway_guide_voip_flag') }}</p>
    <p class="note-plain">{{ __('company.gateway_guide_fortigate') }}</p>
</div>

{{-- The same small setup-guide dialog used on the devices page: the
     include-passwords option is unchecked by default and the download is a
     plain GET to the export route. --}}
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
@endsection

@push('scripts')
<script>
(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        var copy = event.target.closest('[data-copy]');
        if (copy) {
            event.preventDefault();
            var holder = copy.closest('td');
            var source = holder ? holder.querySelector('[data-copy-value]') : null;
            var value = source ? source.textContent.trim() : '';
            if (value && navigator.clipboard) {
                navigator.clipboard.writeText(value).then(function () {
                    copy.textContent = @json(__('company.copied'));
                    window.setTimeout(function () { copy.textContent = @json(__('company.copy')); }, 1500);
                });
            }
            return;
        }

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
            target.textContent = payload.sip_password;
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
