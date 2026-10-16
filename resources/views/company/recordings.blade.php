@extends('layouts.company')

@section('title', __('company.recordings_title'))
@section('crumb', __('company.recordings_title'))

@section('content')
@include('company._assets')
@include('company._alerts')

<div class="page-head">
    <div>
        <h1>{{ __('company.recordings_title') }}</h1>
        <p>{{ __('company.recordings_subtitle') }}</p>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <div><h2>{{ __('company.recording') }}</h2><p>{{ $recordings->total() }}</p></div>
    </div>

    <form class="filters" method="GET" action="{{ route('company.recordings.index') }}">
        <div class="search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.6-3.6"/></svg>
            <input type="search" name="q" value="{{ $q }}" placeholder="{{ __('company.file_name') }}" aria-label="{{ __('company.search') }}">
        </div>
        <button type="submit" class="btn-primary">{{ __('company.search') }}</button>
        @if ($q !== '')
            <a class="btn-secondary" href="{{ route('company.recordings.index') }}">{{ __('company.all') }}</a>
        @endif
    </form>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>{{ __('company.file_name') }}</th>
                    <th>{{ __('company.call') }}</th>
                    <th>{{ __('company.duration_seconds') }}</th>
                    <th>{{ __('company.file_size') }}</th>
                    <th>{{ __('company.mime_type') }}</th>
                    <th>{{ __('company.date') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($recordings as $recording)
                    <tr>
                        <td><span class="file-name">{{ $recording->file_name }}</span></td>
                        <td>
                            @if ($recording->call)
                                <a class="row-link" href="{{ route('company.calls.show', $recording->call) }}">#{{ $recording->call_id }}</a>
                            @else
                                —
                            @endif
                        </td>
                        <td>{{ $recording->duration_seconds ? $recording->duration_seconds.'s' : '—' }}</td>
                        <td>{{ $recording->size ? number_format($recording->size) : '—' }}</td>
                        <td>{{ $recording->mime_type ?: '—' }}</td>
                        <td>{{ $recording->created_at?->format('Y-m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="empty-row">{{ __('company.no_recordings') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="table-foot">
        <span>{{ __('company.showing') }} {{ $recordings->firstItem() ?? 0 }}–{{ $recordings->lastItem() ?? 0 }} / {{ $recordings->total() }}</span>
        @include('company._pagination', ['paginator' => $recordings])
    </div>
</div>
@endsection
