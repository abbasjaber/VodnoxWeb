@extends('layouts.company')

@section('title', __('company.extensions_title'))
@section('crumb', __('company.extensions_title'))

@section('content')
@include('company._assets')
@include('company._alerts')

<div class="page-head">
    <div>
        <h1>{{ __('company.extensions_title') }}</h1>
        <p>{{ __('company.extensions_subtitle') }}</p>
    </div>
    <div class="head-actions">
        <button type="button" class="btn-primary"
                data-company-modal
                data-company-title="{{ __('company.add_extension') }}"
                data-company-action="{{ route('company.extensions.store') }}"
                data-company-submit="{{ __('company.save') }}"
                data-company-template="tpl-extension-create">{{ __('company.add_extension') }}</button>
    </div>
</div>

<p class="section-note">{{ __('company.extension_secret_note') }}</p>

<div class="card">
    <div class="card-head">
        <div><h2>{{ __('company.extension') }}</h2><p>{{ $extensions->total() }}</p></div>
    </div>

    <form class="filters" method="GET" action="{{ route('company.extensions.index') }}">
        <div class="search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.6-3.6"/></svg>
            <input type="search" name="q" value="{{ $q }}" placeholder="{{ __('company.extension_label') }}" aria-label="{{ __('company.search') }}">
        </div>
        <select name="status" aria-label="{{ __('company.status') }}">
            <option value="">{{ __('company.all') }}</option>
            @foreach (['inactive', 'active', 'provisioning', 'provisioned', 'failed'] as $option)
                <option value="{{ $option }}" @selected($status === $option)>{{ __('company.status_labels.'.$option) }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn-primary">{{ __('company.search') }}</button>
        @if ($q !== '' || $status !== '')
            <a class="btn-secondary" href="{{ route('company.extensions.index') }}">{{ __('company.all') }}</a>
        @endif
    </form>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>{{ __('company.extension_number') }}</th>
                    <th>{{ __('company.extension_label') }}</th>
                    <th>{{ __('company.assigned_user') }}</th>
                    <th>{{ __('company.sip_status') }}</th>
                    <th>{{ __('company.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($extensions as $extension)
                    <tr>
                        <td><span class="number">{{ $extension->extension }}</span></td>
                        <td>{{ $extension->label ?: '—' }}</td>
                        <td>
                            @if ($extension->user)
                                <div class="company-cell">
                                    <span class="company-avatar">{{ mb_substr($extension->user->name, 0, 1) }}</span>
                                    <div><strong>{{ $extension->user->name }}</strong><small>{{ $extension->user->email }}</small></div>
                                </div>
                            @else
                                {{ __('company.unassigned') }}
                            @endif
                        </td>
                        <td><span class="status {{ $extension->status }}">{{ __('company.status_labels.'.$extension->status) }}</span></td>
                        <td>
                            <div class="table-actions">
                                <button type="button" class="row-link"
                                        data-company-modal
                                        data-company-title="{{ __('company.edit_extension') }}"
                                        data-company-action="{{ route('company.extensions.update', $extension) }}"
                                        data-company-submit="{{ __('company.save') }}"
                                        data-company-template="tpl-extension-edit-{{ $extension->id }}">{{ __('company.edit') }}</button>
                                <form method="POST" action="{{ route('company.extensions.destroy', $extension) }}" data-confirm="{{ __('company.confirm_delete') }}">
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
        <span>{{ __('company.showing') }} {{ $extensions->firstItem() ?? 0 }}–{{ $extensions->lastItem() ?? 0 }} / {{ $extensions->total() }}</span>
        @include('company._pagination', ['paginator' => $extensions])
    </div>
</div>

<template id="tpl-extension-create">
    @csrf
    <div class="form-grid">
        <div class="form-field"><label for="xc-extension">{{ __('company.extension_number') }}</label><input id="xc-extension" type="text" name="extension" value="{{ old('extension') }}" required></div>
        <div class="form-field"><label for="xc-label">{{ __('company.extension_label') }}</label><input id="xc-label" type="text" name="label" value="{{ old('label') }}"></div>
        <div class="form-field"><label for="xc-user">{{ __('company.assigned_user') }}</label>
            <select id="xc-user" name="user_id">
                <option value="">{{ __('company.unassigned') }}</option>
                @foreach ($users as $user)
                    <option value="{{ $user->id }}" @selected(old('user_id') == $user->id)>{{ $user->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-field"><label for="xc-status">{{ __('company.status') }}</label>
            <select id="xc-status" name="status">
                @foreach (['provisioning', 'active', 'inactive'] as $option)
                    <option value="{{ $option }}" @selected(old('status', 'provisioning') === $option)>{{ __('company.status_labels.'.$option) }}</option>
                @endforeach
            </select>
        </div>
    </div>
</template>

@foreach ($extensions as $extension)
    <template id="tpl-extension-edit-{{ $extension->id }}">
        @csrf
        @method('PUT')
        <div class="form-grid">
            <div class="form-field"><label for="xe{{ $extension->id }}-extension">{{ __('company.extension_number') }}</label><input id="xe{{ $extension->id }}-extension" type="text" name="extension" value="{{ $extension->extension }}" required></div>
            <div class="form-field"><label for="xe{{ $extension->id }}-label">{{ __('company.extension_label') }}</label><input id="xe{{ $extension->id }}-label" type="text" name="label" value="{{ $extension->label }}"></div>
            <div class="form-field"><label for="xe{{ $extension->id }}-user">{{ __('company.assigned_user') }}</label>
                <select id="xe{{ $extension->id }}-user" name="user_id">
                    <option value="">{{ __('company.unassigned') }}</option>
                    @foreach ($users as $user)
                        <option value="{{ $user->id }}" @selected($extension->user_id === $user->id)>{{ $user->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-field"><label for="xe{{ $extension->id }}-status">{{ __('company.status') }}</label>
                <select id="xe{{ $extension->id }}-status" name="status">
                    @foreach (['provisioning', 'active', 'inactive'] as $option)
                        <option value="{{ $option }}" @selected($extension->status === $option)>{{ __('company.status_labels.'.$option) }}</option>
                    @endforeach
                    @unless (in_array($extension->status, ['provisioning', 'active', 'inactive'], true))
                        <option value="{{ $extension->status }}" selected>{{ __('company.status_labels.'.$extension->status) }}</option>
                    @endunless
                </select>
            </div>
        </div>
    </template>
@endforeach
@endsection
