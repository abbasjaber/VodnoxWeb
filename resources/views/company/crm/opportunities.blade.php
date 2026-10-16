@extends('layouts.company')

@section('title', __('crm.title.opportunities'))
@section('crumb', __('crm.title.opportunities'))

@section('content')
@include('company.crm._assets')
@include('company.crm._alerts')
@include('company.crm._nav')

<div class="crm-head">
    <div>
        <div class="crm-kicker">VODNOX / CUSTOMER EXPERIENCE</div>
        <h1>{{ __('crm.title.opportunities') }}</h1>
        <p>{{ __('crm.title.index') }}</p>
    </div>
    <button type="button" class="crm-btn primary" data-crm-modal
            data-crm-title="{{ __('crm.actions.add_opportunity') }}"
            data-crm-action="{{ route('company.crm.opportunities.store') }}"
            data-crm-template="tpl-opportunity-create">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
        {{ __('crm.actions.add_opportunity') }}
    </button>
</div>

<section class="crm-panel">
    <div class="crm-panel-head">
        <h2>{{ __('crm.title.opportunities') }}</h2>
        <small>{{ $opportunities->total() }}</small>
    </div>

    <form class="crm-filter" method="GET" action="{{ route('company.crm.opportunities.index') }}">
        <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="{{ __('crm.actions.search') }}" aria-label="{{ __('crm.actions.search') }}">
        <select name="stage" aria-label="{{ __('crm.fields.stage') }}">
            <option value="">{{ __('crm.actions.all') }}</option>
            @foreach ($stages as $stage)
                <option value="{{ $stage->id }}" @selected((string) $filters['stage'] === (string) $stage->id)>{{ $stage->name_ar }}</option>
            @endforeach
        </select>
        <select name="status" aria-label="{{ __('crm.fields.status') }}">
            <option value="">{{ __('crm.actions.all') }}</option>
            @foreach (['open', 'won', 'lost'] as $status)
                <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ __('crm.status.' . $status) }}</option>
            @endforeach
        </select>
        <button type="submit" class="crm-btn">{{ __('crm.actions.search') }}</button>
        @if ($filters['q'] !== '' || $filters['stage'] !== '' || $filters['status'] !== '')
            <a class="crm-btn" href="{{ route('company.crm.opportunities.index') }}">{{ __('crm.actions.reset') }}</a>
        @endif
    </form>

    <div class="crm-table">
        <table>
            <thead>
                <tr>
                    <th>{{ __('crm.fields.title') }}</th>
                    <th>{{ __('crm.fields.customer') }}</th>
                    <th>{{ __('crm.fields.stage') }}</th>
                    <th>{{ __('crm.fields.value') }}</th>
                    <th>{{ __('crm.fields.status') }}</th>
                    <th>{{ __('crm.fields.assigned_to') }}</th>
                    <th>{{ __('crm.actions.edit') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($opportunities as $opportunity)
                    <tr>
                        <td><b>{{ $opportunity->title }}</b></td>
                        <td>{{ $opportunity->customer?->name_ar ?? '-' }}</td>
                        <td>{{ $opportunity->stage?->name_ar ?? __('crm.reports.unassigned_stage') }}</td>
                        <td>{{ number_format((float) $opportunity->value, 2) }} {{ $opportunity->currency }}</td>
                        <td>
                            <span class="crm-pill {{ $opportunity->status === 'open' ? 'amber' : ($opportunity->status === 'won' ? 'green' : 'red') }}">{{ __('crm.status.' . $opportunity->status) }}</span>
                        </td>
                        <td>{{ $opportunity->assignedTo?->name ?? __('crm.fields.unassigned') }}</td>
                        <td>
                            <button type="button" class="crm-btn" data-crm-modal
                                    data-crm-title="{{ __('crm.actions.edit') }}"
                                    data-crm-action="{{ route('company.crm.opportunities.update', $opportunity) }}"
                                    data-crm-template="tpl-opportunity-edit-{{ $opportunity->id }}">{{ __('crm.actions.edit') }}</button>
                            <form method="POST" action="{{ route('company.crm.opportunities.destroy', $opportunity) }}" data-confirm="{{ __('crm.actions.confirm_delete') }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="crm-btn">{{ __('crm.actions.delete') }}</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="crm-empty">{{ __('crm.empty.opportunities') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="crm-body">
        @include('company.crm._pagination', ['paginator' => $opportunities])
    </div>
</section>

<template id="tpl-opportunity-create">
    @csrf
    @include('company.crm._opportunity_fields', ['opportunity' => null])
</template>

@foreach ($opportunities as $opportunity)
    <template id="tpl-opportunity-edit-{{ $opportunity->id }}">
        @csrf
        @method('PUT')
        @include('company.crm._opportunity_fields', ['opportunity' => $opportunity])
    </template>
@endforeach
@endsection
