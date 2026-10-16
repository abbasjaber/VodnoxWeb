@extends('layouts.admin')

@section('title', __('admin.usage_title'))
@section('crumb', __('admin.usage_title'))

@php
    $mb = fn ($bytes) => number_format(((int) $bytes) / 1048576, 2);
@endphp

@section('content')
@include('admin.partials.flash')

<div class="page-head">
    <div>
        <h1>{{ __('admin.usage_title') }}</h1>
        <p>{{ __('admin.usage_subtitle') }}</p>
    </div>
</div>

<div class="card">
    <div class="card-head"><div><h2>{{ __('admin.usage_title') }}</h2></div></div>

    <form class="filters" method="GET" action="{{ route('admin.usage.index') }}">
        <label class="search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input type="search" name="q" value="{{ $q }}" placeholder="{{ __('admin.search_placeholder') }}">
        </label>
        <button type="submit" class="btn-secondary">{{ __('admin.search') }}</button>
        <a class="btn-quiet" href="{{ route('admin.usage.index') }}">{{ __('admin.reset') }}</a>
    </form>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>{{ __('admin.name') }}</th>
                    <th>{{ __('admin.usage_users') }}</th>
                    <th>{{ __('admin.usage_extensions') }}</th>
                    <th>{{ __('admin.usage_calls_month') }}</th>
                    <th>{{ __('admin.usage_storage') }}</th>
                    <th>{{ __('admin.usage_numbers') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($organizations as $org)
                    <tr>
                        <td>
                            <div class="company-cell">
                                <span class="company-avatar">{{ mb_substr($org->name_ar ?? $org->name_en, 0, 1) }}</span>
                                <div>
                                    <strong>{{ $org->name_ar ?? $org->name_en }}</strong>
                                    <small>{{ $org->code }}</small>
                                </div>
                            </div>
                        </td>
                        <td class="number">{{ number_format($org->users_count) }}</td>
                        <td class="number">{{ number_format($org->extensions_count) }}</td>
                        <td class="number">{{ number_format((int) ($callsThisMonth[$org->id] ?? 0)) }}</td>
                        <td class="number">{{ $mb($storage[$org->id] ?? 0) }} {{ __('admin.usage_mb') }}</td>
                        <td class="number">{{ number_format((int) ($numbers[$org->id] ?? 0)) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="empty-row">{{ __('admin.no_data') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="table-foot">
        <span>{{ $organizations->total() }}</span>
        <span>{{ $organizations->links('pagination::bootstrap-5') }}</span>
    </div>
</div>

@push('scripts')
@include('admin.partials.scripts')
@endpush
@endsection
