@extends('layouts.company')

@section('title', __('company.calls_title'))
@section('crumb', __('company.calls_title'))

@section('content')
@include('company._assets')
@include('company._alerts')

<div class="page-head">
    <div>
        <h1>{{ __('company.calls_title') }}</h1>
        <p>{{ __('company.calls_subtitle') }}</p>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <div><h2>{{ __('company.call') }}</h2><p>{{ $calls->total() }}</p></div>
    </div>

    <form class="filters range-filters" method="GET" action="{{ route('company.calls.index') }}">
        <div class="search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.6-3.6"/></svg>
            <input type="search" name="q" value="{{ $q }}" placeholder="{{ __('company.search_call') }}" aria-label="{{ __('company.search') }}">
        </div>
        <select name="status" aria-label="{{ __('company.call_status') }}">
            <option value="">{{ __('company.all_statuses') }}</option>
            @foreach (['ringing', 'answered', 'unanswered', 'busy', 'failed', 'ended'] as $option)
                <option value="{{ $option }}" @selected($status === $option)>{{ __('company.status_labels.'.$option) }}</option>
            @endforeach
        </select>
        <select name="direction" aria-label="{{ __('company.direction') }}">
            <option value="">{{ __('company.all_directions') }}</option>
            @foreach (['inbound', 'outbound', 'internal'] as $option)
                <option value="{{ $option }}" @selected($direction === $option)>{{ __('company.dir_'.$option) }}</option>
            @endforeach
        </select>
        <input type="date" name="from" value="{{ $from }}" aria-label="{{ __('company.from') }}">
        <input type="date" name="to" value="{{ $to }}" aria-label="{{ __('company.to') }}">
        <button type="submit" class="btn-primary">{{ __('company.search') }}</button>
        @if ($q !== '' || $status !== '' || $direction !== '' || $from !== '' || $to !== '')
            <a class="btn-secondary" href="{{ route('company.calls.index') }}">{{ __('company.all') }}</a>
        @endif
    </form>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>{{ __('company.caller') }}</th>
                    <th>{{ __('company.callee') }}</th>
                    <th>{{ __('company.direction') }}</th>
                    <th>{{ __('company.call_status') }}</th>
                    <th>{{ __('company.duration') }}</th>
                    <th>{{ __('company.agent') }}</th>
                    <th>{{ __('company.date') }}</th>
                    <th>{{ __('company.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($calls as $call)
                    <tr>
                        <td><span class="number">{{ $call->caller_number }}</span></td>
                        <td><span class="number">{{ $call->callee_number }}</span></td>
                        <td>{{ __('company.dir_'.$call->direction) }}</td>
                        <td><span class="status {{ $call->status }}">{{ __('company.status_labels.'.$call->status) }}</span></td>
                        <td>{{ $call->duration_seconds ? $call->duration_seconds.'s' : '—' }}</td>
                        <td>{{ $call->user?->name ?? '—' }}</td>
                        <td>{{ $call->created_at?->format('Y-m-d H:i') }}</td>
                        <td><a class="row-link" href="{{ route('company.calls.show', $call) }}">{{ __('company.view') }}</a></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="empty-row">{{ __('company.no_data') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="table-foot">
        <span>{{ __('company.showing') }} {{ $calls->firstItem() ?? 0 }}–{{ $calls->lastItem() ?? 0 }} / {{ $calls->total() }}</span>
        @include('company._pagination', ['paginator' => $calls])
    </div>
</div>
@endsection
