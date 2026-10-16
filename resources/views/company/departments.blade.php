@extends('layouts.company')

@section('title', __('company.departments_title'))
@section('crumb', __('company.departments_title'))

@section('content')
@include('company._assets')
@include('company._alerts')

<div class="page-head">
    <div>
        <h1>{{ __('company.departments_title') }}</h1>
        <p>{{ __('company.departments_subtitle') }}</p>
    </div>
    <div class="head-actions">
        <button type="button" class="btn-primary"
                data-company-modal
                data-company-title="{{ __('company.add_department') }}"
                data-company-action="{{ route('company.departments.store') }}"
                data-company-submit="{{ __('company.save') }}"
                data-company-template="tpl-department-create">{{ __('company.add_department') }}</button>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <div><h2>{{ __('company.department') }}</h2><p>{{ $departments->total() }}</p></div>
    </div>

    <form class="filters" method="GET" action="{{ route('company.departments.index') }}">
        <div class="search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.6-3.6"/></svg>
            <input type="search" name="q" value="{{ $q }}" placeholder="{{ __('company.search') }}" aria-label="{{ __('company.search') }}">
        </div>
        <button type="submit" class="btn-primary">{{ __('company.search') }}</button>
        @if ($q !== '')
            <a class="btn-secondary" href="{{ route('company.departments.index') }}">{{ __('company.all') }}</a>
        @endif
    </form>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>{{ __('company.name_ar') }}</th>
                    <th>{{ __('company.name_en') }}</th>
                    <th>{{ __('company.code') }}</th>
                    <th>{{ __('company.department_users') }}</th>
                    <th>{{ __('company.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($departments as $department)
                    <tr>
                        <td><strong>{{ $department->name_ar }}</strong></td>
                        <td>{{ $department->name_en }}</td>
                        <td><span class="number">{{ $department->code ?: '—' }}</span></td>
                        <td>{{ $department->users()->count() }}</td>
                        <td>
                            <div class="table-actions">
                                <button type="button" class="row-link"
                                        data-company-modal
                                        data-company-title="{{ __('company.edit_department') }}"
                                        data-company-action="{{ route('company.departments.update', $department) }}"
                                        data-company-submit="{{ __('company.save') }}"
                                        data-company-template="tpl-department-edit-{{ $department->id }}">{{ __('company.edit') }}</button>
                                <form method="POST" action="{{ route('company.departments.destroy', $department) }}" data-confirm="{{ __('company.confirm_delete') }}">
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
        <span>{{ __('company.showing') }} {{ $departments->firstItem() ?? 0 }}–{{ $departments->lastItem() ?? 0 }} / {{ $departments->total() }}</span>
        @include('company._pagination', ['paginator' => $departments])
    </div>
</div>

<template id="tpl-department-create">
    @csrf
    <div class="form-grid">
        <div class="form-field"><label for="dc-name-ar">{{ __('company.name_ar') }}</label><input id="dc-name-ar" type="text" name="name_ar" value="{{ old('name_ar') }}" required></div>
        <div class="form-field"><label for="dc-name-en">{{ __('company.name_en') }}</label><input id="dc-name-en" type="text" name="name_en" value="{{ old('name_en') }}" required></div>
        <div class="form-field"><label for="dc-code">{{ __('company.department_code') }}</label><input id="dc-code" type="text" name="code" value="{{ old('code') }}"></div>
    </div>
</template>

@foreach ($departments as $department)
    <template id="tpl-department-edit-{{ $department->id }}">
        @csrf
        @method('PUT')
        <div class="form-grid">
            <div class="form-field"><label for="de{{ $department->id }}-name-ar">{{ __('company.name_ar') }}</label><input id="de{{ $department->id }}-name-ar" type="text" name="name_ar" value="{{ $department->name_ar }}" required></div>
            <div class="form-field"><label for="de{{ $department->id }}-name-en">{{ __('company.name_en') }}</label><input id="de{{ $department->id }}-name-en" type="text" name="name_en" value="{{ $department->name_en }}" required></div>
            <div class="form-field"><label for="de{{ $department->id }}-code">{{ __('company.department_code') }}</label><input id="de{{ $department->id }}-code" type="text" name="code" value="{{ $department->code }}"></div>
        </div>
    </template>
@endforeach
@endsection
