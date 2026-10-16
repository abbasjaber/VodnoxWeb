@extends('layouts.admin')

@section('title', __('admin.billing_title'))
@section('crumb', __('admin.billing_title'))

@php
    $statuses = ['draft', 'unpaid', 'paid', 'overdue', 'cancelled'];
    $methods = ['bank_transfer', 'cash', 'card'];
@endphp

@section('content')
@include('admin.partials.flash')

<div class="page-head">
    <div>
        <h1>{{ __('admin.billing_title') }}</h1>
        <p>{{ __('admin.billing_subtitle') }}</p>
    </div>
    <div class="head-actions">
        <button type="button" class="btn-primary" data-admin-create
            data-admin-title="{{ __('admin.inv_new') }}"
            data-admin-action="{{ route('admin.billing.invoices.store') }}"
            data-admin-method="POST"
            data-admin-template="#tplInvoiceCreate">{{ __('admin.inv_new') }}</button>
    </div>
</div>

<div class="stats" style="grid-template-columns:repeat(3,1fr)">
    <div class="stat">
        <div class="stat-top"><span>{{ __('admin.tot_invoices') }}</span></div>
        <strong>{{ number_format($totals['total'], 2) }}</strong>
    </div>
    <div class="stat">
        <div class="stat-top"><span>{{ __('admin.tot_collected') }}</span></div>
        <strong>{{ number_format($totals['collected'], 2) }}</strong>
    </div>
    <div class="stat">
        <div class="stat-top"><span>{{ __('admin.tot_outstanding') }}</span></div>
        <strong>{{ number_format($totals['outstanding'], 2) }}</strong>
    </div>
</div>

<div class="section-note">{{ __('admin.pay_note_manual') }}</div>

<div class="card" style="margin-bottom:22px">
    <div class="card-head"><div><h2>{{ __('admin.pay_pending') }}</h2></div></div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>{{ __('admin.inv_org') }}</th>
                    <th>{{ __('admin.inv_number') }}</th>
                    <th>{{ __('admin.pay_amount') }}</th>
                    <th>{{ __('admin.pay_method') }}</th>
                    <th>{{ __('admin.pay_reference') }}</th>
                    <th>{{ __('admin.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($pendingPayments as $payment)
                    <tr>
                        <td>{{ $payment->invoice?->organization?->name_ar ?? $payment->invoice?->organization?->name_en ?? '—' }}</td>
                        <td class="code-note">{{ $payment->invoice?->invoice_number ?? '—' }}</td>
                        <td class="number">{{ number_format((float) $payment->amount, 2) }} {{ $payment->currency }}</td>
                        <td>@lang('admin.method_'.str_replace('bank_transfer', 'bank', $payment->method))</td>
                        <td>{{ $payment->reference ?: '—' }}</td>
                        <td>
                            <div class="detail-actions" style="padding:0">
                                <form method="POST" action="{{ route('admin.billing.payments.confirm', $payment) }}">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="row-link" style="color:#21846b">{{ __('admin.pay_confirm') }}</button>
                                </form>
                                <form method="POST" action="{{ route('admin.billing.payments.reject', $payment) }}" data-admin-confirm="{{ __('admin.confirm') }}">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="row-link" style="color:#c55b61">{{ __('admin.pay_reject') }}</button>
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
</div>

<div class="card">
    <div class="card-head"><div><h2>{{ __('admin.billing_title') }}</h2></div></div>

    <form class="filters" method="GET" action="{{ route('admin.billing.index') }}">
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
        <a class="btn-quiet" href="{{ route('admin.billing.index') }}">{{ __('admin.reset') }}</a>
    </form>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>{{ __('admin.inv_number') }}</th>
                    <th>{{ __('admin.inv_org') }}</th>
                    <th>{{ __('admin.inv_amount') }}</th>
                    <th>{{ __('admin.status') }}</th>
                    <th>{{ __('admin.inv_due') }}</th>
                    <th>{{ __('admin.inv_paid_at') }}</th>
                    <th>{{ __('admin.inv_remaining') }}</th>
                    <th>{{ __('admin.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($invoices as $invoice)
                    <tr>
                        <td class="code-note">{{ $invoice->invoice_number }}</td>
                        <td>{{ $invoice->organization?->name_ar ?? $invoice->organization?->name_en ?? '—' }}</td>
                        <td class="number">{{ number_format((float) $invoice->amount, 2) }} {{ $invoice->currency }}</td>
                        <td><span class="status {{ $invoice->status }}">@lang('admin.st_'.$invoice->status)</span></td>
                        <td>{{ $invoice->due_date?->format('Y-m-d') ?? '—' }}</td>
                        <td>{{ $invoice->paid_at?->format('Y-m-d') ?? '—' }}</td>
                        <td class="number">{{ number_format($invoice->remainingBalance(), 2) }}</td>
                        <td>
                            <div class="detail-actions" style="padding:0">
                                <button type="button" class="row-link" data-admin-edit
                                    data-admin-title="{{ __('admin.inv_edit') }}"
                                    data-admin-action="{{ route('admin.billing.invoices.update', $invoice) }}"
                                    data-admin-method="PUT"
                                    data-admin-template="#tplInvoiceEdit"
                                    data-admin-values="{{ json_encode([
                                        'organization_id' => $invoice->organization_id,
                                        'invoice_number' => $invoice->invoice_number,
                                        'amount' => $invoice->amount,
                                        'currency' => $invoice->currency,
                                        'status' => $invoice->status,
                                        'due_date' => $invoice->due_date?->format('Y-m-d'),
                                        'notes' => $invoice->notes,
                                    ]) }}">{{ __('admin.edit') }}</button>
                                <button type="button" class="row-link" data-admin-create
                                    data-admin-title="{{ __('admin.pay_new') }}"
                                    data-admin-action="{{ route('admin.billing.payments.store', $invoice) }}"
                                    data-admin-method="POST"
                                    data-admin-template="#tplPaymentCreate"
                                    data-admin-values="{{ json_encode([
                                        'currency' => $invoice->currency,
                                        'amount' => $invoice->remainingBalance(),
                                    ]) }}">{{ __('admin.pay_new') }}</button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="empty-row">{{ __('admin.no_data') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="table-foot">
        <span>{{ $invoices->total() }}</span>
        <span>{{ $invoices->links('pagination::bootstrap-5') }}</span>
    </div>
</div>

@include('admin.partials.dialog')

<template id="tplInvoiceCreate">
    <div class="form-grid">
        <div class="form-field">
            <label>{{ __('admin.inv_org') }} <span class="required">*</span></label>
            <select name="organization_id" required>
                @foreach ($organizations as $org)
                    <option value="{{ $org->id }}">{{ $org->name_ar ?? $org->name_en }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-field"><label>{{ __('admin.inv_number') }}</label><input type="text" name="invoice_number" placeholder="INV-..."></div>
        <div class="form-field"><label>{{ __('admin.inv_amount') }} <span class="required">*</span></label><input type="number" step="0.01" min="0" name="amount" required></div>
        <div class="form-field"><label>{{ __('admin.currency') }}</label><input type="text" name="currency" value="USD"></div>
        <div class="form-field">
            <label>{{ __('admin.status') }}</label>
            <select name="status">
                @foreach ($statuses as $s)
                    <option value="{{ $s }}">@lang('admin.st_'.$s)</option>
                @endforeach
            </select>
        </div>
        <div class="form-field"><label>{{ __('admin.inv_due') }}</label><input type="date" name="due_date"></div>
        <div class="form-field full"><label>{{ __('admin.notes') }}</label><textarea name="notes"></textarea></div>
    </div>
</template>

<template id="tplInvoiceEdit">
    <div class="form-grid">
        <div class="form-field">
            <label>{{ __('admin.inv_org') }} <span class="required">*</span></label>
            <select name="organization_id" required>
                @foreach ($organizations as $org)
                    <option value="{{ $org->id }}">{{ $org->name_ar ?? $org->name_en }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-field"><label>{{ __('admin.inv_number') }}</label><input type="text" name="invoice_number"></div>
        <div class="form-field"><label>{{ __('admin.inv_amount') }} <span class="required">*</span></label><input type="number" step="0.01" min="0" name="amount" required></div>
        <div class="form-field"><label>{{ __('admin.currency') }}</label><input type="text" name="currency"></div>
        <div class="form-field">
            <label>{{ __('admin.status') }}</label>
            <select name="status">
                @foreach ($statuses as $s)
                    <option value="{{ $s }}">@lang('admin.st_'.$s)</option>
                @endforeach
            </select>
        </div>
        <div class="form-field"><label>{{ __('admin.inv_due') }}</label><input type="date" name="due_date"></div>
        <div class="form-field full"><label>{{ __('admin.notes') }}</label><textarea name="notes"></textarea></div>
    </div>
</template>

<template id="tplPaymentCreate">
    <div class="form-grid">
        <div class="form-field"><label>{{ __('admin.pay_amount') }} <span class="required">*</span></label><input type="number" step="0.01" min="0.01" name="amount" required></div>
        <div class="form-field"><label>{{ __('admin.currency') }}</label><input type="text" name="currency"></div>
        <div class="form-field">
            <label>{{ __('admin.pay_method') }}</label>
            <select name="method">
                @foreach ($methods as $m)
                    <option value="{{ $m }}">@lang('admin.method_'.($m === 'bank_transfer' ? 'bank' : $m))</option>
                @endforeach
            </select>
        </div>
        <div class="form-field"><label>{{ __('admin.pay_reference') }}</label><input type="text" name="reference"></div>
        <div class="form-field full"><label>{{ __('admin.pay_proof') }}</label><input type="file" name="proof" accept=".jpg,.jpeg,.png,.pdf"></div>
        <div class="form-field full"><label>{{ __('admin.notes') }}</label><textarea name="notes"></textarea></div>
    </div>
</template>

@push('scripts')
@include('admin.partials.scripts')
@endpush
@endsection
