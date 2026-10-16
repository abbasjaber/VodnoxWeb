@extends('layouts.company')

@section('title', __('crm.title.customers'))
@section('crumb', __('crm.title.customers'))

@section('content')
@include('company.crm._assets')
@include('company.crm._alerts')
@include('company.crm._nav')

<div class="crm-head">
    <div>
        <div class="crm-kicker">VODNOX / CUSTOMER EXPERIENCE</div>
        <h1>{{ __('crm.title.customers') }}</h1>
        <p>{{ __('crm.title.index') }}</p>
    </div>
    @if ($customerLimitReached)
        <button type="button" class="crm-btn" disabled>{{ __('crm.actions.add_customer') }}</button>
    @else
        <button type="button" class="crm-btn primary" data-crm-modal
                data-crm-title="{{ __('crm.actions.add_customer') }}"
                data-crm-action="{{ route('company.crm.customers.store') }}"
                data-crm-template="tpl-customer-create">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
            {{ __('crm.actions.add_customer') }}
        </button>
    @endif
</div>

@if ($customerLimitReached)
    <div class="crm-top-note"><span>{{ __('crm.messages.customer_limit_reached') }}</span></div>
@endif

<section class="crm-panel">
    <div class="crm-panel-head">
        <h2>{{ __('crm.title.customers') }}</h2>
        <small>{{ $customers->total() }}</small>
    </div>

    <form class="crm-filter" method="GET" action="{{ route('company.crm.customers.index') }}">
        <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="{{ __('crm.actions.search') }}" aria-label="{{ __('crm.actions.search') }}">
        <select name="status" aria-label="{{ __('crm.fields.status') }}">
            <option value="">{{ __('crm.actions.all') }}</option>
            <option value="active" @selected($filters['status'] === 'active')>{{ __('crm.status.active') }}</option>
            <option value="inactive" @selected($filters['status'] === 'inactive')>{{ __('crm.status.inactive') }}</option>
        </select>
        <button type="submit" class="crm-btn">{{ __('crm.actions.search') }}</button>
        @if ($filters['q'] !== '' || $filters['status'] !== '')
            <a class="crm-btn" href="{{ route('company.crm.customers.index') }}">{{ __('crm.actions.reset') }}</a>
        @endif
    </form>

    <div class="crm-table">
        <table>
            <thead>
                <tr>
                    <th>{{ __('crm.fields.customer') }}</th>
                    <th>{{ __('crm.fields.assigned_to') }}</th>
                    <th>{{ __('crm.fields.email') }}</th>
                    <th>{{ __('crm.fields.status') }}</th>
                    <th>{{ __('crm.fields.created_at') }}</th>
                    <th>{{ __('crm.actions.edit') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($customers as $customer)
                    <tr>
                        <td>
                            <a class="crm-name" href="{{ route('company.crm.customers.show', $customer) }}">
                                <span>{{ mb_substr($customer->name_ar, 0, 1) }}</span>
                                <div>
                                    <b>{{ $customer->name_ar }}</b>
                                    <small>{{ $customer->phone ?? $customer->name_en ?? '-' }}</small>
                                </div>
                            </a>
                        </td>
                        <td>{{ $customer->assignedTo?->name ?? __('crm.fields.unassigned') }}</td>
                        <td>{{ $customer->email ?? '-' }}</td>
                        <td>
                            @if ($customer->status === 'active')
                                <span class="crm-pill green">{{ __('crm.status.active') }}</span>
                            @else
                                <span class="crm-pill">{{ __('crm.status.inactive') }}</span>
                            @endif
                        </td>
                        <td>{{ $customer->created_at?->format('Y-m-d') }}</td>
                        <td>
                            <button type="button" class="crm-btn" data-crm-modal
                                    data-crm-title="{{ __('crm.actions.edit') }}"
                                    data-crm-action="{{ route('company.crm.customers.update', $customer) }}"
                                    data-crm-template="tpl-customer-edit-{{ $customer->id }}">{{ __('crm.actions.edit') }}</button>
                            <form method="POST" action="{{ route('company.crm.customers.destroy', $customer) }}" data-confirm="{{ __('crm.actions.confirm_delete') }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="crm-btn">{{ __('crm.actions.delete') }}</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="crm-empty">{{ __('crm.empty.customers') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="crm-body">
        @include('company.crm._pagination', ['paginator' => $customers])
    </div>
</section>

<template id="tpl-customer-create">
    @csrf
    @include('company.crm._customer_fields', ['customer' => null])
</template>

@foreach ($customers as $customer)
    <template id="tpl-customer-edit-{{ $customer->id }}">
        @csrf
        @method('PUT')
        @include('company.crm._customer_fields', ['customer' => $customer])
    </template>
@endforeach
@endsection
