@extends('layouts.admin')

@section('title', __('admin.org_title'))
@section('crumb', __('admin.org_title'))

@php
    $statuses = ['pending', 'active', 'trial', 'suspended', 'expired'];
@endphp

@section('content')
@include('admin.partials.flash')

<div class="page-head">
    <div>
        <h1>{{ __('admin.org_title') }}</h1>
        <p>{{ __('admin.org_subtitle') }}</p>
    </div>
    <div class="head-actions">
        <button type="button" class="btn-primary" data-admin-create
            data-admin-title="{{ __('admin.org_new') }}"
            data-admin-action="{{ route('admin.organizations.store') }}"
            data-admin-method="POST"
            data-admin-template="#tplOrgCreate">{{ __('admin.org_new') }}</button>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <div><h2>{{ __('admin.accounts') }}</h2></div>
    </div>

    <form class="filters" method="GET" action="{{ route('admin.organizations.index') }}">
        <label class="search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input type="search" name="q" value="{{ $q }}" placeholder="{{ __('admin.search_placeholder') }}">
        </label>
        <select name="status">
            <option value="">{{ __('admin.all') }}</option>
            @foreach ($statuses as $s)
                <option value="{{ $s }}" @selected($status === $s)>@lang('admin.st_'.$s)</option>
            @endforeach
        </select>
        <button type="submit" class="btn-secondary">{{ __('admin.filter') }}</button>
        <a class="btn-quiet" href="{{ route('admin.organizations.index') }}">{{ __('admin.reset') }}</a>
    </form>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>{{ __('admin.name') }}</th>
                    <th>{{ __('admin.org_contact') }}</th>
                    <th>{{ __('admin.org_users_count') }}</th>
                    <th>{{ __('admin.status') }}</th>
                    <th>{{ __('admin.created_at') }}</th>
                    <th>{{ __('admin.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($organizations as $org)
                    <tr>
                        <td>
                            <div class="company-cell">
                                <span class="company-avatar">{{ mb_substr($org->name_ar ?? $org->name_en, 0, 1) }}</span>
                                <div>
                                    <strong>{{ $org->name_ar ?? $org->name_en }}</strong>
                                    <small>{{ $org->code }}</small>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div>{{ $org->contact_name ?: '—' }}</div>
                            <small>{{ $org->email ?: ($org->phone ?: '') }}</small>
                        </td>
                        <td><span class="number">{{ number_format($org->users_count) }}</span></td>
                        <td><span class="status {{ $org->status }}">@lang('admin.st_'.$org->status)</span></td>
                        <td>{{ $org->created_at?->format('Y-m-d') }}</td>
                        <td>
                            <div class="detail-actions" style="padding:0">
                                <a class="row-link" href="{{ route('admin.organizations.show', $org) }}">{{ __('admin.view') }}</a>
                                <button type="button" class="row-link" data-admin-edit
                                    data-admin-title="{{ __('admin.org_edit') }}"
                                    data-admin-action="{{ route('admin.organizations.update', $org) }}"
                                    data-admin-method="PUT"
                                    data-admin-template="#tplOrgEdit"
                                    data-admin-values="{{ json_encode([
                                        'code' => $org->code,
                                        'name_ar' => $org->name_ar,
                                        'name_en' => $org->name_en,
                                        'contact_name' => $org->contact_name,
                                        'email' => $org->email,
                                        'phone' => $org->phone,
                                        'city' => $org->city,
                                        'status' => $org->status,
                                        'timezone' => $org->timezone,
                                        'language' => $org->language,
                                        'internal_notes' => $org->internal_notes,
                                    ]) }}">{{ __('admin.edit') }}</button>
                                <form method="POST" action="{{ route('admin.organizations.status', $org) }}" data-admin-confirm="{{ __('admin.confirm') }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="status" value="{{ $org->status === 'suspended' ? 'active' : 'suspended' }}">
                                    <button type="submit" class="row-link">{{ $org->status === 'suspended' ? __('admin.org_activate') : __('admin.org_suspend') }}</button>
                                </form>
                                <form method="POST" action="{{ route('admin.organizations.destroy', $org) }}" data-admin-confirm="{{ __('admin.confirm_delete') }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="row-link" style="color:#c55b61">{{ __('admin.delete') }}</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="empty-row">{{ __('admin.no_data') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="table-foot">
        <span>{{ $organizations->total() }}</span>
        <span>{{ $organizations->links('pagination::bootstrap-5') }}</span>
    </div>
</div>

@include('admin.partials.dialog')

<template id="tplOrgCreate">
    <div class="form-grid">
        <div class="form-field"><label>{{ __('admin.org_code') }} <span class="required">*</span></label><input type="text" name="code" required></div>
        <div class="form-field"><label>{{ __('admin.name_ar') }} <span class="required">*</span></label><input type="text" name="name_ar" required></div>
        <div class="form-field"><label>{{ __('admin.name_en') }} <span class="required">*</span></label><input type="text" name="name_en" required></div>
        <div class="form-field"><label>{{ __('admin.contact_name') }}</label><input type="text" name="contact_name"></div>
        <div class="form-field"><label>{{ __('admin.email') }}</label><input type="email" name="email"></div>
        <div class="form-field"><label>{{ __('admin.phone') }}</label><input type="text" name="phone"></div>
        <div class="form-field"><label>{{ __('admin.city') }}</label><input type="text" name="city"></div>
        <div class="form-field">
            <label>{{ __('admin.status') }}</label>
            <select name="status">
                @foreach ($statuses as $s)
                    <option value="{{ $s }}">@lang('admin.st_'.$s)</option>
                @endforeach
            </select>
        </div>
        <div class="form-field"><label>{{ __('admin.timezone') }}</label><input type="text" name="timezone" value="Asia/Baghdad"></div>
        <div class="form-field">
            <label>{{ __('admin.language') }}</label>
            <select name="language">
                <option value="ar">العربية</option>
                <option value="en">English</option>
            </select>
        </div>
        <div class="form-field full"><label>{{ __('admin.internal_notes') }}</label><textarea name="internal_notes"></textarea></div>
        <div class="form-field full"><label style="margin-bottom:4px"><strong>{{ __('admin.org_admin_section') }}</strong></label></div>
        <div class="form-field"><label>{{ __('admin.org_admin_name') }} <span class="required">*</span></label><input type="text" name="admin_name" required></div>
        <div class="form-field"><label>{{ __('admin.org_admin_username') }} <span class="required">*</span></label><input type="text" name="admin_username" required></div>
        <div class="form-field"><label>{{ __('admin.org_admin_email') }}</label><input type="email" name="admin_email"></div>
        <div class="form-field"><label>{{ __('admin.org_admin_password') }} <span class="required">*</span></label><input type="password" name="admin_password" required><small>{{ __('admin.org_admin_password_hint') }}</small></div>
    </div>
</template>

<template id="tplOrgEdit">
    <div class="form-grid">
        <div class="form-field"><label>{{ __('admin.org_code') }} <span class="required">*</span></label><input type="text" name="code" required></div>
        <div class="form-field"><label>{{ __('admin.name_ar') }} <span class="required">*</span></label><input type="text" name="name_ar" required></div>
        <div class="form-field"><label>{{ __('admin.name_en') }} <span class="required">*</span></label><input type="text" name="name_en" required></div>
        <div class="form-field"><label>{{ __('admin.contact_name') }}</label><input type="text" name="contact_name"></div>
        <div class="form-field"><label>{{ __('admin.email') }}</label><input type="email" name="email"></div>
        <div class="form-field"><label>{{ __('admin.phone') }}</label><input type="text" name="phone"></div>
        <div class="form-field"><label>{{ __('admin.city') }}</label><input type="text" name="city"></div>
        <div class="form-field">
            <label>{{ __('admin.status') }}</label>
            <select name="status">
                @foreach ($statuses as $s)
                    <option value="{{ $s }}">@lang('admin.st_'.$s)</option>
                @endforeach
            </select>
        </div>
        <div class="form-field"><label>{{ __('admin.timezone') }}</label><input type="text" name="timezone"></div>
        <div class="form-field">
            <label>{{ __('admin.language') }}</label>
            <select name="language">
                <option value="ar">العربية</option>
                <option value="en">English</option>
            </select>
        </div>
        <div class="form-field full"><label>{{ __('admin.internal_notes') }}</label><textarea name="internal_notes"></textarea></div>
    </div>
</template>

@push('scripts')
@include('admin.partials.scripts')
@endpush
@endsection
