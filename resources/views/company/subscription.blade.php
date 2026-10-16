@extends('layouts.company')

@section('title', __('company.subscription_title'))
@section('crumb', __('company.subscription_title'))

@section('content')
@include('company._assets')
@include('company._alerts')

<div class="page-head">
    <div>
        <h1>{{ __('company.subscription_title') }}</h1>
        <p>{{ __('company.subscription_subtitle') }}</p>
    </div>
</div>

<div class="two-col">
    <div class="card">
        <div class="card-head">
            <div><h2>{{ __('company.active_subscription') }}</h2></div>
        </div>
        @if ($subscription)
            <dl class="detail-list">
                <div class="detail-line"><dt>{{ __('company.plan') }}</dt><dd>{{ $subscription->plan?->name_ar ?? $subscription->plan?->name_en ?? '—' }}</dd></div>
                <div class="detail-line"><dt>{{ __('company.subscription_status') }}</dt><dd><span class="status {{ $subscription->status }}">{{ __('company.status_labels.'.$subscription->status) }}</span></dd></div>
                <div class="detail-line"><dt>{{ __('company.billing_cycle') }}</dt><dd>{{ __('company.'.$subscription->billing_cycle) }}</dd></div>
                <div class="detail-line"><dt>{{ __('company.price') }}</dt><dd>{{ number_format((float) $subscription->price, 2) }} {{ $subscription->currency }}</dd></div>
                <div class="detail-line"><dt>{{ __('company.starts_at') }}</dt><dd>{{ $subscription->starts_at?->format('Y-m-d') ?? '—' }}</dd></div>
                <div class="detail-line"><dt>{{ __('company.ends_at') }}</dt><dd>{{ $subscription->ends_at?->format('Y-m-d') ?? '—' }}</dd></div>
            </dl>
        @else
            <p class="section-note">{{ __('company.no_subscription') }}</p>
        @endif
    </div>

    <div class="card">
        <div class="card-head">
            <div><h2>{{ __('company.request_activation') }}</h2></div>
        </div>
        <form method="POST" action="{{ route('company.subscription.request') }}">
            @csrf
            <div class="form-grid">
                <div class="form-field"><label for="sub-feature">{{ __('company.select_service') }}</label>
                    <select id="sub-feature" name="feature" required>
                        @foreach ($features as $feature)
                            <option value="{{ $feature }}" @selected(old('feature') === $feature)>{{ __('company.feature_'.$feature) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-field full"><label for="sub-notes">{{ __('company.request_notes') }}</label><textarea id="sub-notes" name="notes" rows="3">{{ old('notes') }}</textarea></div>
                <div class="form-field full"><button type="submit" class="btn-primary">{{ __('company.request_activation') }}</button></div>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <div><h2>{{ __('company.feature_subscriptions') }}</h2><p>{{ $featureSubscriptions->count() }}</p></div>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>{{ __('company.select_service') }}</th>
                    <th>{{ __('company.status') }}</th>
                    <th>{{ __('company.starts_at') }}</th>
                    <th>{{ __('company.ends_at') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($featureSubscriptions as $featureSubscription)
                    <tr>
                        <td>{{ $featureSubscription->feature?->name_ar ?? $featureSubscription->feature?->name_en ?? $featureSubscription->feature?->code }}</td>
                        <td><span class="status {{ $featureSubscription->isActive() ? 'active' : 'inactive' }}">{{ $featureSubscription->isActive() ? __('company.feature_active') : __('company.feature_inactive') }}</span></td>
                        <td>{{ $featureSubscription->starts_at?->format('Y-m-d') ?? '—' }}</td>
                        <td>{{ $featureSubscription->ends_at?->format('Y-m-d') ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="empty-row">{{ __('company.no_data') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if ($pendingRequests->isNotEmpty())
    <div class="card">
        <div class="card-head">
            <div><h2>{{ __('company.pending_requests') }}</h2><p>{{ $pendingRequests->count() }}</p></div>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>{{ __('company.select_service') }}</th>
                        <th>{{ __('company.request_notes') }}</th>
                        <th>{{ __('company.status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($pendingRequests as $pendingRequest)
                        <tr>
                            <td>{{ $pendingRequest->feature?->name_ar ?? $pendingRequest->feature?->code }}</td>
                            <td>{{ $pendingRequest->notes ?: '—' }}</td>
                            <td><span class="status pending">{{ __('company.status_labels.pending') }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

<div class="card">
    <div class="card-head">
        <div><h2>{{ __('company.invoices') }}</h2><p>{{ $invoices->count() }}</p></div>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>{{ __('company.invoice_number') }}</th>
                    <th>{{ __('company.amount') }}</th>
                    <th>{{ __('company.status') }}</th>
                    <th>{{ __('company.due_date') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($invoices as $invoice)
                    <tr>
                        <td><span class="number">{{ $invoice->invoice_number }}</span></td>
                        <td>{{ number_format((float) $invoice->amount, 2) }} {{ $invoice->currency }}</td>
                        <td><span class="status {{ $invoice->status }}">{{ __('company.status_labels.'.$invoice->status) }}</span></td>
                        <td>{{ $invoice->due_date?->format('Y-m-d') ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="empty-row">{{ __('company.no_invoices') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
