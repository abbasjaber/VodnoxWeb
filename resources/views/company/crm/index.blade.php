@extends('layouts.company')

@section('title', __('crm.title.index'))
@section('crumb', __('crm.title.index'))

@section('content')
@include('company.crm._assets')
@include('company.crm._alerts')

@if (! $active)
    <div class="crm-head">
        <div>
            <div class="crm-kicker">VODNOX / CUSTOMER EXPERIENCE</div>
            <h1>{{ __('crm.activation.heading') }}</h1>
            <p>{{ __('crm.activation.subtitle') }}</p>
        </div>
    </div>

    <section class="crm-locked">
        <div class="crm-lock-symbol">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V6a4 4 0 0 1 8 0v4M12 14v3"/></svg>
        </div>
        <h2>{{ __('crm.activation.heading') }}</h2>
        <p>{{ __('crm.activation.subtitle') }}</p>

        <div class="crm-benefits">
            <div>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="8" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3M16 5a3 3 0 0 1 0 6m2 3a5 5 0 0 1 3 4v3"/></svg>
                <b>{{ __('crm.activation.benefit_customers_title') }}</b>
                <p>{{ __('crm.activation.benefit_customers_text') }}</p>
            </div>
            <div>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 3 9 5-9 5-9-5 9-5Zm-9 9 9 5 9-5M3 16l9 5 9-5"/></svg>
                <b>{{ __('crm.activation.benefit_opportunities_title') }}</b>
                <p>{{ __('crm.activation.benefit_opportunities_text') }}</p>
            </div>
            <div>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 6v6l4 2"/></svg>
                <b>{{ __('crm.activation.benefit_followup_title') }}</b>
                <p>{{ __('crm.activation.benefit_followup_text') }}</p>
            </div>
        </div>

        <form method="POST" action="{{ route('company.subscription.request') }}">
            @csrf
            <input type="hidden" name="feature" value="crm">
            <div class="crm-fields">
                <div class="crm-field full">
                    <label for="crm-activation-notes">{{ __('crm.fields.notes') }}</label>
                    <textarea id="crm-activation-notes" name="notes" maxlength="1000"></textarea>
                </div>
            </div>
            <div class="crm-admin-actions">
                <button type="submit" class="crm-btn primary">{{ __('crm.actions.request_activation') }}</button>
            </div>
        </form>

        <small>{{ __('crm.activation.hint') }}</small>

        @if ($subscription)
            <div class="crm-subscription-badge">
                <b>{{ __('crm.activation.current_subscription') }}</b>
                {{ __('crm.kpi.subscription') }} ·
                @switch ($subscription->status)
                    @case ('active') {{ __('crm.status.active') }} @break
                    @case ('suspended') {{ __('crm.status.inactive') }} @break
                    @default {{ $subscription->status }}
                @endswitch
                @if ($subscription->ends_at)
                    · {{ __('crm.kpi.ends_at') }} {{ $subscription->ends_at->format('Y-m-d') }}
                @endif
            </div>
        @endif
    </section>
@else
    @include('company.crm._nav')

    <div class="crm-head">
        <div>
            <div class="crm-kicker">VODNOX / CUSTOMER EXPERIENCE</div>
            <h1>{{ __('crm.title.index') }}</h1>
            <p>{{ __('crm.activation.subtitle') }}</p>
        </div>
        <a class="crm-btn primary" href="{{ route('company.crm.customers.index') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
            {{ __('crm.actions.add_customer') }}
        </a>
    </div>

    <div class="crm-top-note">
        <span>
            {{ __('crm.kpi.subscription') }} ·
            @switch ($subscription?->status)
                @case ('active') {{ __('crm.status.active') }} @break
                @case ('suspended') {{ __('crm.status.inactive') }} @break
                @case ('expired') {{ __('crm.status.closed') }} @break
                @default {{ $subscription?->status ?? __('crm.status.inactive') }}
            @endswitch
            @if ($subscription?->ends_at)
                · {{ __('crm.kpi.ends_at') }} {{ $subscription->ends_at->format('Y-m-d') }}
            @endif
            · {{ __('crm.kpi.open_opportunities_value') }}: {{ number_format($metrics['open_opportunities_value'], 2) }} IQD
        </span>
    </div>

    <div class="crm-metrics">
        <div class="crm-metric">
            <span>{{ __('crm.kpi.customers') }}</span>
            <strong>{{ number_format($metrics['customers']) }}</strong>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="8" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3M16 5a3 3 0 0 1 0 6m2 3a5 5 0 0 1 3 4v3"/></svg>
        </div>
        <div class="crm-metric">
            <span>{{ __('crm.kpi.open_opportunities') }}</span>
            <strong>{{ number_format($metrics['open_opportunities']) }}</strong>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20V10m5 10V4m5 16v-7m5 7V7M2 21h20"/></svg>
        </div>
        <div class="crm-metric">
            <span>{{ __('crm.kpi.open_tasks') }}</span>
            <strong>{{ number_format($metrics['open_tasks']) }}</strong>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 6v6l4 2"/></svg>
        </div>
        <div class="crm-metric">
            <span>{{ __('crm.kpi.open_complaints') }}</span>
            <strong>{{ number_format($metrics['open_complaints']) }}</strong>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 4H3v14h5l4 3 4-3h5V4ZM12 8v4m0 2v1"/></svg>
        </div>
    </div>

    <section class="crm-panel">
        <div class="crm-panel-head">
            <h2>{{ __('crm.kpi.recent_interactions') }}</h2>
        </div>
        <div class="crm-body">
            @if ($recentInteractions->isEmpty())
                <div class="crm-empty">{{ __('crm.empty.recent_interactions') }}</div>
            @else
                <div class="crm-timeline">
                    @foreach ($recentInteractions as $interaction)
                        <div class="crm-event">
                            <time>{{ optional($interaction->occurred_at)->format('Y-m-d H:i') }}</time>
                            <b>{{ __('crm.type.' . $interaction->type) }}</b>
                            <p>{{ $interaction->customer?->name_ar ?? $interaction->customer?->name_en ?? '-' }} · {{ $interaction->user?->name ?? __('crm.fields.unassigned') }}</p>
                            @if ($interaction->content)
                                <p>{{ $interaction->content }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </section>
@endif
@endsection
