@extends('layouts.user')

@section('title', __('portal.agent.tasks_title'))
@section('crumb', __('portal.agent.tasks_title'))

@php
    $statusMap = [
        'pending' => __('portal.agent.status_pending'),
        'in_progress' => __('portal.agent.status_in_progress'),
        'completed' => __('portal.agent.status_completed'),
    ];
    $statusTone = ['pending' => 'pending', 'in_progress' => 'active', 'completed' => 'draft'];
    $priorityMap = [
        'low' => __('portal.agent.priority_low'),
        'medium' => __('portal.agent.priority_medium'),
        'high' => __('portal.agent.priority_high'),
    ];
    $priorityTone = ['low' => 'draft', 'medium' => 'pending', 'high' => 'expired'];
    $baghdad = fn ($moment, string $format) => $moment ? $moment->timezone(config('app.timezone'))->format($format) : '—';
@endphp

@section('content')
@include('user._workspace')

<div class="page-head">
    <div>
        <h1>{{ __('portal.agent.tasks_title') }}</h1>
        <p>{{ __('portal.agent.tasks_sub') }}</p>
    </div>
    <span class="history-count">{{ $tasks->total() }}</span>
</div>

<div class="filter-tabs">
    <a class="filter-pill {{ $status === '' ? 'active' : '' }}" href="{{ route('user.tasks.index') }}">{{ __('portal.agent.tasks_all') }}</a>
    @foreach ($statusMap as $key => $label)
        <a class="filter-pill {{ $status === $key ? 'active' : '' }}" href="{{ route('user.tasks.index', ['status' => $key]) }}">{{ $label }}</a>
    @endforeach
</div>

<section class="card">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>{{ __('portal.agent.title_field') }}</th>
                    <th>{{ __('portal.agent.due_at') }}</th>
                    <th>{{ __('portal.agent.priority') }}</th>
                    <th>{{ __('portal.agent.status') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($tasks as $task)
                    <tr>
                        <td>
                            <strong>{{ $task->title }}</strong>
                            @if ($task->customer)
                                <small>{{ $task->customer->name_ar ?: $task->customer->name_en }}</small>
                            @endif
                        </td>
                        <td><span class="number">{{ $baghdad($task->due_at, 'Y-m-d H:i') }}</span></td>
                        <td>
                            <span class="status {{ $priorityTone[$task->priority] ?? 'draft' }}">
                                {{ $priorityMap[$task->priority] ?? $task->priority }}
                            </span>
                        </td>
                        <td>
                            <span class="status {{ $statusTone[$task->status] ?? 'draft' }}">
                                {{ $statusMap[$task->status] ?? $task->status }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="empty-row">{{ __('portal.agent.no_tasks') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="table-foot">
        {{ $tasks->links('pagination::bootstrap-5') }}
    </div>
</section>
@endsection
