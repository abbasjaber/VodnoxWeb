@extends('layouts.admin')

@section('title', __('admin.srv_title'))
@section('crumb', __('admin.srv_title'))

@php
    $methods = ['ami', 'ari', 'http'];
    $serverStatuses = ['connected', 'disconnected', 'error'];
@endphp

@section('content')
@include('admin.partials.flash')

<div class="page-head">
    <div>
        <h1>{{ __('admin.srv_title') }}</h1>
        <p>{{ __('admin.srv_subtitle') }}</p>
    </div>
    <div class="head-actions">
        <button type="button" class="btn-primary" data-admin-create
            data-admin-title="{{ __('admin.srv_new') }}"
            data-admin-action="{{ route('admin.servers.store') }}"
            data-admin-method="POST"
            data-admin-template="#tplServerCreate">{{ __('admin.srv_new') }}</button>
    </div>
</div>

@if ($simulated)
    <div class="section-note">{{ __('admin.srv_simulated') }}</div>
@endif

<div class="card">
    <div class="card-head"><div><h2>{{ __('admin.srv_title') }}</h2></div></div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>{{ __('admin.srv_name') }}</th>
                    <th>{{ __('admin.srv_host') }}</th>
                    <th>{{ __('admin.srv_method') }}</th>
                    <th>{{ __('admin.srv_username') }}</th>
                    <th>{{ __('admin.srv_secret') }}</th>
                    <th>{{ __('admin.status') }}</th>
                    <th>{{ __('admin.srv_last_connected') }}</th>
                    <th>{{ __('admin.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($servers as $server)
                    <tr>
                        <td>{{ $server->name }}</td>
                        <td class="code-note">{{ $server->host ? $server->host.':'.$server->port : '—' }}</td>
                        <td>@lang('admin.method_'.$server->connection_method)</td>
                        <td>{{ $server->ami_username ?: '—' }}</td>
                        <td class="code-note">{{ __('admin.masked') }}</td>
                        <td><span class="status {{ $server->status }}">@lang('admin.st_'.$server->status)</span></td>
                        <td>{{ $server->last_connected_at?->format('Y-m-d H:i') ?? '—' }}</td>
                        <td>
                            <div class="detail-actions" style="padding:0">
                                <form method="POST" action="{{ route('admin.servers.test', $server) }}">
                                    @csrf
                                    <button type="submit" class="row-link">{{ __('admin.srv_test') }}</button>
                                </form>
                                <button type="button" class="row-link" data-admin-edit
                                    data-admin-title="{{ __('admin.srv_edit') }}"
                                    data-admin-action="{{ route('admin.servers.update', $server) }}"
                                    data-admin-method="PUT"
                                    data-admin-template="#tplServerEdit"
                                    data-admin-values="{{ json_encode([
                                        'name' => $server->name,
                                        'host' => $server->host,
                                        'port' => $server->port,
                                        'connection_method' => $server->connection_method,
                                        'ami_username' => $server->ami_username,
                                        'status' => $server->status,
                                    ]) }}">{{ __('admin.edit') }}</button>
                                <form method="POST" action="{{ route('admin.servers.destroy', $server) }}" data-admin-confirm="{{ __('admin.confirm_delete') }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="row-link" style="color:#c55b61">{{ __('admin.delete') }}</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="empty-row">{{ __('admin.no_data') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@include('admin.partials.dialog')

<template id="tplServerCreate">
    <div class="form-grid">
        <div class="form-field"><label>{{ __('admin.srv_name') }} <span class="required">*</span></label><input type="text" name="name" required></div>
        <div class="form-field"><label>{{ __('admin.srv_host') }}</label><input type="text" name="host"></div>
        <div class="form-field"><label>{{ __('admin.srv_port') }}</label><input type="number" min="1" max="65535" name="port" value="5038"></div>
        <div class="form-field">
            <label>{{ __('admin.srv_method') }}</label>
            <select name="connection_method">
                @foreach ($methods as $m)
                    <option value="{{ $m }}">@lang('admin.method_'.$m)</option>
                @endforeach
            </select>
        </div>
        <div class="form-field"><label>{{ __('admin.srv_username') }}</label><input type="text" name="ami_username"></div>
        <div class="form-field"><label>{{ __('admin.srv_secret') }}</label><input type="password" name="ami_secret" autocomplete="new-password"><small>{{ __('admin.srv_secret_hint') }}</small></div>
        <div class="form-field">
            <label>{{ __('admin.status') }}</label>
            <select name="status">
                @foreach ($serverStatuses as $s)
                    <option value="{{ $s }}">@lang('admin.st_'.$s)</option>
                @endforeach
            </select>
        </div>
    </div>
</template>

<template id="tplServerEdit">
    <div class="form-grid">
        <div class="form-field"><label>{{ __('admin.srv_name') }} <span class="required">*</span></label><input type="text" name="name" required></div>
        <div class="form-field"><label>{{ __('admin.srv_host') }}</label><input type="text" name="host"></div>
        <div class="form-field"><label>{{ __('admin.srv_port') }}</label><input type="number" min="1" max="65535" name="port"></div>
        <div class="form-field">
            <label>{{ __('admin.srv_method') }}</label>
            <select name="connection_method">
                @foreach ($methods as $m)
                    <option value="{{ $m }}">@lang('admin.method_'.$m)</option>
                @endforeach
            </select>
        </div>
        <div class="form-field"><label>{{ __('admin.srv_username') }}</label><input type="text" name="ami_username"></div>
        <div class="form-field"><label>{{ __('admin.srv_secret') }}</label><input type="password" name="ami_secret" autocomplete="new-password" placeholder="{{ __('admin.leave_blank') }}"><small>{{ __('admin.srv_secret_hint') }}</small></div>
        <div class="form-field">
            <label>{{ __('admin.status') }}</label>
            <select name="status">
                @foreach ($serverStatuses as $s)
                    <option value="{{ $s }}">@lang('admin.st_'.$s)</option>
                @endforeach
            </select>
        </div>
    </div>
</template>

@push('scripts')
@include('admin.partials.scripts')
@endpush
@endsection
