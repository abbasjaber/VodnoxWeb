@extends('layouts.company')

@section('title', __('crm.title.complaints'))
@section('crumb', __('crm.title.complaints'))

@section('content')
@include('company.crm._assets')
@include('company.crm._alerts')
@include('company.crm._nav')

<div class="crm-head">
    <div>
        <div class="crm-kicker">VODNOX / CUSTOMER EXPERIENCE</div>
        <h1>{{ __('crm.title.complaints') }}</h1>
        <p>{{ __('crm.title.index') }}</p>
    </div>
    <button type="button" class="crm-btn primary" data-crm-modal
            data-crm-title="{{ __('crm.actions.add_complaint') }}"
            data-crm-action="{{ route('company.crm.complaints.store') }}"
            data-crm-template="tpl-complaint-create">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
        {{ __('crm.actions.add_complaint') }}
    </button>
</div>

<section class="crm-panel">
    <div class="crm-panel-head">
        <h2>{{ __('crm.title.complaints') }}</h2>
        <small>{{ $complaints->total() }}</small>
    </div>

    <form class="crm-filter" method="GET" action="{{ route('company.crm.complaints.index') }}">
        <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="{{ __('crm.actions.search') }}" aria-label="{{ __('crm.actions.search') }}">
        <select name="status" aria-label="{{ __('crm.fields.status') }}">
            <option value="">{{ __('crm.actions.all') }}</option>
            @foreach (['open', 'in_progress', 'resolved', 'closed'] as $status)
                <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ __('crm.status.' . $status) }}</option>
            @endforeach
        </select>
        <select name="priority" aria-label="{{ __('crm.fields.priority') }}">
            <option value="">{{ __('crm.actions.all') }}</option>
            @foreach (['low', 'medium', 'high'] as $priority)
                <option value="{{ $priority }}" @selected($filters['priority'] === $priority)>{{ __('crm.priority.' . $priority) }}</option>
            @endforeach
        </select>
        <button type="submit" class="crm-btn">{{ __('crm.actions.search') }}</button>
        @if ($filters['q'] !== '' || $filters['status'] !== '' || $filters['priority'] !== '')
            <a class="crm-btn" href="{{ route('company.crm.complaints.index') }}">{{ __('crm.actions.reset') }}</a>
        @endif
    </form>

    <div class="crm-table">
        <table>
            <thead>
                <tr>
                    <th>{{ __('crm.fields.subject') }}</th>
                    <th>{{ __('crm.fields.customer') }}</th>
                    <th>{{ __('crm.fields.department') }}</th>
                    <th>{{ __('crm.fields.assigned_to') }}</th>
                    <th>{{ __('crm.fields.priority') }}</th>
                    <th>{{ __('crm.fields.status') }}</th>
                    <th>{{ __('crm.actions.edit') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($complaints as $complaint)
                    <tr>
                        <td><b>{{ $complaint->subject }}</b></td>
                        <td>{{ $complaint->customer?->name_ar ?? '-' }}</td>
                        <td>{{ $complaint->department?->name_ar ?? '-' }}</td>
                        <td>{{ $complaint->assignee?->name ?? __('crm.fields.unassigned') }}</td>
                        <td>{{ __('crm.priority.' . $complaint->priority) }}</td>
                        <td>
                            <span class="crm-pill {{ in_array($complaint->status, ['resolved', 'closed'], true) ? 'green' : ($complaint->status === 'in_progress' ? 'amber' : 'red') }}">{{ __('crm.status.' . $complaint->status) }}</span>
                        </td>
                        <td>
                            <button type="button" class="crm-btn" data-crm-modal
                                    data-crm-title="{{ __('crm.actions.edit') }}"
                                    data-crm-action="{{ route('company.crm.complaints.update', $complaint) }}"
                                    data-crm-template="tpl-complaint-edit-{{ $complaint->id }}">{{ __('crm.actions.edit') }}</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="crm-empty">{{ __('crm.empty.complaints') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="crm-body">
        @include('company.crm._pagination', ['paginator' => $complaints])
    </div>
</section>

<template id="tpl-complaint-create">
    @csrf
    @include('company.crm._complaint_fields', ['complaint' => null])
</template>

@foreach ($complaints as $complaint)
    <template id="tpl-complaint-edit-{{ $complaint->id }}">
        @csrf
        @method('PUT')
        @include('company.crm._complaint_fields', ['complaint' => $complaint])
    </template>
@endforeach
@endsection
