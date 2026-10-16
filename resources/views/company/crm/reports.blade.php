@extends('layouts.company')

@section('title', __('crm.title.reports'))
@section('crumb', __('crm.title.reports'))

@section('content')
@include('company.crm._assets')
@include('company.crm._alerts')
@include('company.crm._nav')

<div class="crm-head">
    <div>
        <div class="crm-kicker">VODNOX / CUSTOMER EXPERIENCE</div>
        <h1>{{ __('crm.title.reports') }}</h1>
        <p>{{ __('crm.title.index') }}</p>
    </div>
</div>

<div class="crm-metrics">
    <div class="crm-metric">
        <span>{{ __('crm.kpi.customers') }}</span>
        <strong>{{ number_format($customersTotal) }}</strong>
    </div>
    <div class="crm-metric">
        <span>{{ __('crm.kpi.open_opportunities_value') }}</span>
        <strong>{{ number_format($opportunitiesValueTotal, 2) }}</strong>
    </div>
    <div class="crm-metric">
        <span>{{ __('crm.reports.tasks_overdue') }}</span>
        <strong>{{ number_format($overdueTasks) }}</strong>
    </div>
    <div class="crm-metric">
        <span>{{ __('crm.title.complaints') }}</span>
        <strong>{{ number_format($complaintsTotal) }}</strong>
    </div>
</div>

<div class="crm-grid">
    <div class="crm-stack">
        <section class="crm-panel">
            <div class="crm-panel-head">
                <h2>{{ __('crm.reports.opportunities_by_stage') }}</h2>
                <small>{{ __('crm.reports.count') }}: {{ number_format($opportunitiesTotal) }}</small>
            </div>
            <div class="crm-body">
                @if ($opportunitiesByStage->isEmpty())
                    <div class="crm-empty">{{ __('crm.reports.no_data') }}</div>
                @else
                    @foreach ($opportunitiesByStage as $row)
                        @php($percent = $opportunitiesTotal > 0 ? round($row->total / $opportunitiesTotal * 100) : 0)
                        <div class="crm-report-row">
                            <div>
                                <span>{{ $row->name_ar ?? __('crm.reports.unassigned_stage') }}</span>
                                <b>{{ number_format($row->total) }} · {{ number_format((float) $row->value, 2) }}</b>
                            </div>
                            <div class="crm-track"><span style="width: {{ $percent }}%"></span></div>
                        </div>
                    @endforeach
                @endif
            </div>
        </section>

        <section class="crm-panel">
            <div class="crm-panel-head">
                <h2>{{ __('crm.reports.interactions_30_days') }}</h2>
                <small>{{ __('crm.reports.count') }}: {{ number_format($interactionsTotal) }}</small>
            </div>
            <div class="crm-body">
                @if (empty($interactionsDaily))
                    <div class="crm-empty">{{ __('crm.reports.no_data') }}</div>
                @else
                    @php($interactionsMax = max($interactionsDaily))
                    @foreach ($interactionsDaily as $day => $count)
                        @php($percent = $interactionsMax > 0 ? round($count / $interactionsMax * 100) : 0)
                        <div class="crm-report-row">
                            <div><span>{{ $day }}</span><b>{{ number_format($count) }}</b></div>
                            <div class="crm-track"><span style="width: {{ $percent }}%"></span></div>
                        </div>
                    @endforeach
                @endif
            </div>
        </section>
    </div>

    <div class="crm-stack">
        <section class="crm-panel">
            <div class="crm-panel-head">
                <h2>{{ __('crm.reports.customers_by_status') }}</h2>
            </div>
            <div class="crm-body">
                <dl class="crm-details">
                    @forelse ($customersByStatus as $status => $count)
                        <div><dt>{{ __('crm.status.' . $status) }}</dt><dd>{{ number_format($count) }}</dd></div>
                    @empty
                        <div class="crm-empty">{{ __('crm.reports.no_data') }}</div>
                    @endforelse
                </dl>
            </div>
        </section>

        <section class="crm-panel">
            <div class="crm-panel-head">
                <h2>{{ __('crm.reports.tasks_by_status') }}</h2>
            </div>
            <div class="crm-body">
                <dl class="crm-details">
                    @forelse ($tasksByStatus as $status => $count)
                        <div><dt>{{ __('crm.status.' . $status) }}</dt><dd>{{ number_format($count) }}</dd></div>
                    @empty
                        <div class="crm-empty">{{ __('crm.reports.no_data') }}</div>
                    @endforelse
                    <div><dt>{{ __('crm.reports.tasks_overdue') }}</dt><dd>{{ number_format($overdueTasks) }}</dd></div>
                </dl>
            </div>
        </section>

        <section class="crm-panel">
            <div class="crm-panel-head">
                <h2>{{ __('crm.reports.complaints_by_status') }}</h2>
            </div>
            <div class="crm-body">
                <dl class="crm-details">
                    @forelse ($complaintsByStatus as $status => $count)
                        <div><dt>{{ __('crm.status.' . $status) }}</dt><dd>{{ number_format($count) }}</dd></div>
                    @empty
                        <div class="crm-empty">{{ __('crm.reports.no_data') }}</div>
                    @endforelse
                </dl>
            </div>
        </section>

        <section class="crm-panel">
            <div class="crm-panel-head">
                <h2>{{ __('crm.reports.complaints_by_priority') }}</h2>
            </div>
            <div class="crm-body">
                <dl class="crm-details">
                    @forelse ($complaintsByPriority as $priority => $count)
                        <div><dt>{{ __('crm.priority.' . $priority) }}</dt><dd>{{ number_format($count) }}</dd></div>
                    @empty
                        <div class="crm-empty">{{ __('crm.reports.no_data') }}</div>
                    @endforelse
                </dl>
            </div>
        </section>
    </div>
</div>
@endsection
