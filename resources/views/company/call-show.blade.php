@extends('layouts.company')

@section('title', __('company.call_details'))
@section('crumb', __('company.call_details'))

@section('content')
@include('company._assets')
@include('company._alerts')

<div class="page-head">
    <div>
        <h1>{{ __('company.call_details') }}</h1>
        <p><span class="number">{{ $call->caller_number }}</span> → <span class="number">{{ $call->callee_number }}</span></p>
    </div>
    <div class="head-actions">
        <a class="btn-secondary" href="{{ route('company.calls.index') }}">{{ __('company.calls_title') }}</a>
    </div>
</div>

<div class="two-col">
    <div class="card">
        <div class="card-head">
            <div><h2>{{ __('company.details') }}</h2></div>
            <span class="status {{ $call->status }}">{{ __('company.status_labels.'.$call->status) }}</span>
        </div>
        <dl class="detail-list">
            <div class="detail-line"><dt>{{ __('company.caller') }}</dt><dd><span class="number">{{ $call->caller_number }}</span></dd></div>
            <div class="detail-line"><dt>{{ __('company.callee') }}</dt><dd><span class="number">{{ $call->callee_number }}</span></dd></div>
            <div class="detail-line"><dt>{{ __('company.direction') }}</dt><dd>{{ __('company.dir_'.$call->direction) }}</dd></div>
            <div class="detail-line"><dt>{{ __('company.agent') }}</dt><dd>{{ $call->user?->name ?? '—' }}</dd></div>
            <div class="detail-line"><dt>{{ __('company.customer') }}</dt><dd>{{ $call->customer?->name ?? '—' }}</dd></div>
            <div class="detail-line"><dt>{{ __('company.started_at') }}</dt><dd>{{ $call->started_at?->format('Y-m-d H:i:s') ?? '—' }}</dd></div>
            <div class="detail-line"><dt>{{ __('company.answered_at') }}</dt><dd>{{ $call->answered_at?->format('Y-m-d H:i:s') ?? '—' }}</dd></div>
            <div class="detail-line"><dt>{{ __('company.ended_at') }}</dt><dd>{{ $call->ended_at?->format('Y-m-d H:i:s') ?? '—' }}</dd></div>
            <div class="detail-line"><dt>{{ __('company.duration') }}</dt><dd>{{ $call->duration_seconds ? $call->duration_seconds.'s' : '—' }}</dd></div>
            <div class="detail-line"><dt>{{ __('company.billable') }}</dt><dd>{{ $call->billable_seconds ? $call->billable_seconds.'s' : '—' }}</dd></div>
        </dl>
    </div>

    <div class="card">
        <div class="card-head">
            <div><h2>{{ __('company.recording') }}</h2><p>{{ $call->recordings->count() }}</p></div>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>{{ __('company.file_name') }}</th>
                        <th>{{ __('company.duration_seconds') }}</th>
                        <th>{{ __('company.file_size') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($call->recordings as $recording)
                        <tr>
                            <td><span class="file-name">{{ $recording->file_name }}</span></td>
                            <td>{{ $recording->duration_seconds ? $recording->duration_seconds.'s' : '—' }}</td>
                            <td>{{ $recording->size ? number_format($recording->size) : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="empty-row">{{ __('company.no_recording') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <div><h2>{{ __('company.events') }}</h2><p>{{ $call->events->count() }}</p></div>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>{{ __('company.event_type') }}</th>
                    <th>{{ __('company.occurred_at') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($call->events as $event)
                    <tr>
                        <td>{{ $event->event_type }}</td>
                        <td>{{ $event->occurred_at?->format('Y-m-d H:i:s') ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="2" class="empty-row">{{ __('company.no_data') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
