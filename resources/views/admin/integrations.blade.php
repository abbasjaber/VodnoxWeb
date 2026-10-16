@extends('layouts.admin')

@section('title', __('admin.int_title'))
@section('crumb', __('admin.int_title'))

@section('content')
@include('admin.partials.flash')

<div class="page-head">
    <div>
        <h1>{{ __('admin.int_title') }}</h1>
        <p>{{ __('admin.int_subtitle') }}</p>
    </div>
    <div class="head-actions">
        <button type="button" class="btn-primary" data-admin-create
            data-admin-title="{{ __('admin.int_new') }}"
            data-admin-action="{{ route('admin.integrations.store') }}"
            data-admin-method="POST"
            data-admin-template="#tplIntegrationCreate">{{ __('admin.int_new') }}</button>
    </div>
</div>

<div class="card">
    <div class="card-head"><div><h2>{{ __('admin.int_title') }}</h2></div></div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>{{ __('admin.int_type') }}</th>
                    <th>{{ __('admin.int_name') }}</th>
                    <th>{{ __('admin.status') }}</th>
                    <th>{{ __('admin.int_keys') }}</th>
                    <th>{{ __('admin.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($integrations as $integration)
                    <tr>
                        <td class="code-note">{{ $integration->type }}</td>
                        <td>{{ $integration->name }}</td>
                        <td><span class="status {{ $integration->is_active ? 'active' : 'inactive' }}">{{ $integration->is_active ? __('admin.active') : __('admin.inactive') }}</span></td>
                        <td>
                            @forelse (array_keys($integration->config ?? []) as $key)
                                <span class="tag">{{ $key }}</span>
                            @empty
                                <span class="tag">—</span>
                            @endforelse
                        </td>
                        <td>
                            <div class="detail-actions" style="padding:0">
                                <button type="button" class="row-link" data-admin-edit
                                    data-admin-title="{{ __('admin.int_edit') }}"
                                    data-admin-action="{{ route('admin.integrations.update', $integration) }}"
                                    data-admin-method="PUT"
                                    data-admin-template="#tplIntegrationEdit"
                                    data-admin-values="{{ json_encode([
                                        'type' => $integration->type,
                                        'name' => $integration->name,
                                        'is_active' => (bool) $integration->is_active,
                                        'config_text' => '',
                                    ]) }}">{{ __('admin.edit') }}</button>
                                <form method="POST" action="{{ route('admin.integrations.destroy', $integration) }}" data-admin-confirm="{{ __('admin.confirm_delete') }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="row-link" style="color:#c55b61">{{ __('admin.delete') }}</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="empty-row">{{ __('admin.no_data') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@include('admin.partials.dialog')

<template id="tplIntegrationCreate">
    <div class="form-grid">
        <div class="form-field"><label>{{ __('admin.int_type') }} <span class="required">*</span></label><input type="text" name="type" required></div>
        <div class="form-field"><label>{{ __('admin.int_name') }} <span class="required">*</span></label><input type="text" name="name" required></div>
        <div class="form-field full"><label>{{ __('admin.int_config') }}</label><textarea name="config_text" placeholder="api_key=&#10;api_secret="></textarea><small>{{ __('admin.int_config_hint') }}</small></div>
        <div class="form-field full">
            <label>{{ __('admin.int_active') }}</label>
            <input type="checkbox" name="is_active" value="1" checked style="width:auto;min-height:auto">
        </div>
    </div>
</template>

<template id="tplIntegrationEdit">
    <div class="form-grid">
        <div class="form-field"><label>{{ __('admin.int_type') }} <span class="required">*</span></label><input type="text" name="type" required></div>
        <div class="form-field"><label>{{ __('admin.int_name') }} <span class="required">*</span></label><input type="text" name="name" required></div>
        <div class="form-field full"><label>{{ __('admin.int_config') }}</label><textarea name="config_text" placeholder="{{ __('admin.leave_blank') }}"></textarea><small>{{ __('admin.int_config_hint') }}</small></div>
        <div class="form-field full">
            <label>{{ __('admin.int_active') }}</label>
            <input type="checkbox" name="is_active" value="1" style="width:auto;min-height:auto">
        </div>
    </div>
</template>

@push('scripts')
@include('admin.partials.scripts')
@endpush
@endsection
