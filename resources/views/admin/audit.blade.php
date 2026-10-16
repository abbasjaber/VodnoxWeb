@extends('layouts.admin')

@section('title', __('admin.audit_title'))
@section('crumb', __('admin.audit_title'))

@section('content')
@include('admin.partials.flash')

<div class="page-head">
    <div>
        <h1>{{ __('admin.audit_title') }}</h1>
        <p>{{ __('admin.audit_subtitle') }}</p>
    </div>
</div>

<div class="card">
    <div class="card-head"><div><h2>{{ __('admin.audit_title') }}</h2></div></div>

    <form class="filters" method="GET" action="{{ route('admin.audit.index') }}">
        <label class="search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input type="search" name="q" value="{{ $q }}" placeholder="{{ __('admin.search_placeholder') }}">
        </label>
        <select name="organization">
            <option value="">{{ __('admin.all') }}</option>
            @foreach ($organizations as $org)
                <option value="{{ $org->id }}" @selected((string) $organization === (string) $org->id)>{{ $org->name_ar ?? $org->name_en }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn-secondary">{{ __('admin.filter') }}</button>
        <a class="btn-quiet" href="{{ route('admin.audit.index') }}">{{ __('admin.reset') }}</a>
    </form>

    <div class="table-wrap">
        <table class="audit-table">
            <thead>
                <tr>
                    <th>{{ __('admin.audit_action') }}</th>
                    <th>{{ __('admin.audit_user') }}</th>
                    <th>{{ __('admin.audit_org') }}</th>
                    <th>{{ __('admin.audit_target') }}</th>
                    <th>{{ __('admin.audit_ip') }}</th>
                    <th>{{ __('admin.audit_time') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($logs as $log)
                    <tr>
                        <td class="code-note">{{ $log->action }}</td>
                        <td>{{ $log->user?->name ?? $log->user?->username ?? '—' }}</td>
                        <td>{{ $log->organization?->name_ar ?? $log->organization?->name_en ?? '—' }}</td>
                        <td>
                            @if ($log->auditable_type)
                                <span class="tag">{{ class_basename($log->auditable_type) }}#{{ $log->auditable_id }}</span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="number">{{ $log->ip_address ?: '—' }}</td>
                        <td>{{ $log->created_at?->format('Y-m-d H:i:s') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="empty-row">{{ __('admin.no_data') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="table-foot">
        <span>{{ $logs->total() }}</span>
        <span>{{ $logs->links('pagination::bootstrap-5') }}</span>
    </div>
</div>

@push('scripts')
@include('admin.partials.scripts')
@endpush
@endsection
