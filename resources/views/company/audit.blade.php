@extends('layouts.company')

@section('title', __('company.audit_title'))
@section('crumb', __('company.audit_title'))

@section('content')
@include('company._assets')
@include('company._alerts')

<div class="page-head">
    <div>
        <h1>{{ __('company.audit_title') }}</h1>
        <p>{{ __('company.audit_subtitle') }}</p>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <div><h2>{{ __('company.action') }}</h2><p>{{ $logs->total() }}</p></div>
    </div>

    <form class="filters" method="GET" action="{{ route('company.audit.index') }}">
        <div class="search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.6-3.6"/></svg>
            <input type="search" name="q" value="{{ $q }}" placeholder="{{ __('company.search_action') }}" aria-label="{{ __('company.search') }}">
        </div>
        <button type="submit" class="btn-primary">{{ __('company.search') }}</button>
        @if ($q !== '')
            <a class="btn-secondary" href="{{ route('company.audit.index') }}">{{ __('company.all') }}</a>
        @endif
    </form>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>{{ __('company.action') }}</th>
                    <th>{{ __('company.user') }}</th>
                    <th>{{ __('company.target') }}</th>
                    <th>{{ __('company.ip_address') }}</th>
                    <th>{{ __('company.date') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($logs as $log)
                    <tr>
                        <td><strong>{{ $log->action }}</strong></td>
                        <td>{{ $log->user?->name ?? '—' }}</td>
                        <td>{{ $log->auditable_type ? class_basename($log->auditable_type).' #'.$log->auditable_id : '—' }}</td>
                        <td><span class="number">{{ $log->ip_address ?: '—' }}</span></td>
                        <td>{{ $log->created_at?->format('Y-m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="empty-row">{{ __('company.no_activity') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="table-foot">
        <span>{{ __('company.showing') }} {{ $logs->firstItem() ?? 0 }}–{{ $logs->lastItem() ?? 0 }} / {{ $logs->total() }}</span>
        @include('company._pagination', ['paginator' => $logs])
    </div>
</div>
@endsection
