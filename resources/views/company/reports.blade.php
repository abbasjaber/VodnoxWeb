@extends('layouts.company')

@section('title', __('company.reports_title'))
@section('crumb', __('company.reports_title'))

@section('content')
@include('company._assets')
@include('company._alerts')

<div class="page-head">
    <div>
        <h1>{{ __('company.reports_title') }}</h1>
        <p>{{ __('company.reports_subtitle') }}</p>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <div><h2>{{ __('company.range') }}</h2><p>{{ $from }} → {{ $to }}</p></div>
    </div>
    <form class="filters range-filters" method="GET" action="{{ route('company.reports.index') }}">
        <input type="date" name="from" value="{{ $from }}" aria-label="{{ __('company.from') }}">
        <input type="date" name="to" value="{{ $to }}" aria-label="{{ __('company.to') }}">
        <button type="submit" class="btn-primary">{{ __('company.filter') }}</button>
    </form>
</div>

<div class="stats">
    <div class="stat">
        <div class="stat-top"><span>{{ __('company.total_calls') }}</span></div>
        <strong>{{ number_format($summary['total']) }}</strong>
        <small>{{ $from }} → {{ $to }}</small>
    </div>
    <div class="stat">
        <div class="stat-top"><span>{{ __('company.answered_calls') }}</span></div>
        <strong>{{ number_format($summary['answered']) }}</strong>
        <small class="green">{{ __('company.answer_rate') }}: {{ $summary['answer_rate'] }}%</small>
    </div>
    <div class="stat">
        <div class="stat-top"><span>{{ __('company.missed_calls') }}</span></div>
        <strong>{{ number_format($summary['missed']) }}</strong>
    </div>
    <div class="stat">
        <div class="stat-top"><span>{{ __('company.avg_duration') }}</span></div>
        <strong>{{ number_format($summary['avg_duration']) }}s</strong>
    </div>
</div>

@php
    $maxDay = (int) ($perDay->max('total') ?? 0);
@endphp
<div class="two-col">
    <div class="card">
        <div class="card-head">
            <div><h2>{{ __('company.calls_per_day') }}</h2></div>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>{{ __('company.date') }}</th>
                        <th>{{ __('company.total_calls') }}</th>
                        <th>{{ __('company.answered_calls') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($perDay as $day)
                        <tr>
                            <td>{{ $day->day }}</td>
                            <td>{{ number_format($day->total) }}</td>
                            <td>{{ number_format($day->answered) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="empty-row">{{ __('company.no_data') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-head">
            <div><h2>{{ __('company.by_direction') }}</h2></div>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>{{ __('company.direction') }}</th>
                        <th>{{ __('company.count') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($byDirection as $direction)
                        <tr>
                            <td>{{ __('company.dir_'.$direction->direction) }}</td>
                            <td>{{ number_format($direction->total) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="2" class="empty-row">{{ __('company.no_data') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="two-col">
    <div class="card">
        <div class="card-head">
            <div><h2>{{ __('company.by_status') }}</h2></div>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>{{ __('company.call_status') }}</th>
                        <th>{{ __('company.count') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($byStatus as $status)
                        <tr>
                            <td><span class="status {{ $status->status }}">{{ __('company.status_labels.'.$status->status) }}</span></td>
                            <td>{{ number_format($status->total) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="2" class="empty-row">{{ __('company.no_data') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-head">
            <div><h2>{{ __('company.by_agent') }}</h2></div>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>{{ __('company.agent') }}</th>
                        <th>{{ __('company.total_calls') }}</th>
                        <th>{{ __('company.answered_calls') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($byAgent as $agent)
                        <tr>
                            <td>{{ $agent->agent_name ?? __('company.unassigned') }}</td>
                            <td>{{ number_format($agent->total) }}</td>
                            <td>{{ number_format($agent->answered) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="empty-row">{{ __('company.no_data') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
