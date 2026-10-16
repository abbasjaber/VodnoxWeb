@extends('layouts.company')

@section('title', __('crm.title.tasks'))
@section('crumb', __('crm.title.tasks'))

@section('content')
@include('company.crm._assets')
@include('company.crm._alerts')
@include('company.crm._nav')

<div class="crm-head">
    <div>
        <div class="crm-kicker">VODNOX / CUSTOMER EXPERIENCE</div>
        <h1>{{ __('crm.title.tasks') }}</h1>
        <p>{{ __('crm.title.index') }}</p>
    </div>
    <button type="button" class="crm-btn primary" data-crm-modal
            data-crm-title="{{ __('crm.actions.add_task') }}"
            data-crm-action="{{ route('company.crm.tasks.store') }}"
            data-crm-template="tpl-task-create">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
        {{ __('crm.actions.add_task') }}
    </button>
</div>

<section class="crm-panel">
    <div class="crm-panel-head">
        <h2>{{ __('crm.title.tasks') }}</h2>
        <small>{{ $tasks->total() }}</small>
    </div>

    <form class="crm-filter" method="GET" action="{{ route('company.crm.tasks.index') }}">
        <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="{{ __('crm.actions.search') }}" aria-label="{{ __('crm.actions.search') }}">
        <select name="status" aria-label="{{ __('crm.fields.status') }}">
            <option value="">{{ __('crm.actions.all') }}</option>
            @foreach (['pending', 'completed', 'cancelled'] as $status)
                <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ __('crm.status.' . $status) }}</option>
            @endforeach
        </select>
        <select name="priority" aria-label="{{ __('crm.fields.priority') }}">
            <option value="">{{ __('crm.actions.all') }}</option>
            @foreach (['low', 'medium', 'high'] as $priority)
                <option value="{{ $priority }}" @selected($filters['priority'] === $priority)>{{ __('crm.priority.' . $priority) }}</option>
            @endforeach
        </select>
        <button type="submit" class="crm-btn">{{ __('crm.actions.search') }}</button>
        @if ($filters['q'] !== '' || $filters['status'] !== '' || $filters['priority'] !== '')
            <a class="crm-btn" href="{{ route('company.crm.tasks.index') }}">{{ __('crm.actions.reset') }}</a>
        @endif
    </form>

    <div class="crm-table">
        <table>
            <thead>
                <tr>
                    <th>{{ __('crm.fields.title') }}</th>
                    <th>{{ __('crm.fields.customer') }}</th>
                    <th>{{ __('crm.fields.assigned_to') }}</th>
                    <th>{{ __('crm.fields.due_at') }}</th>
                    <th>{{ __('crm.fields.priority') }}</th>
                    <th>{{ __('crm.fields.status') }}</th>
                    <th>{{ __('crm.actions.edit') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($tasks as $task)
                    <tr>
                        <td><b>{{ $task->title }}</b></td>
                        <td>{{ $task->customer?->name_ar ?? '-' }}</td>
                        <td>{{ $task->assignedTo?->name ?? __('crm.fields.unassigned') }}</td>
                        <td>{{ $task->due_at?->format('Y-m-d H:i') ?? '-' }}</td>
                        <td>{{ __('crm.priority.' . $task->priority) }}</td>
                        <td>
                            <span class="crm-pill {{ $task->status === 'completed' ? 'green' : ($task->status === 'cancelled' ? 'red' : 'amber') }}">{{ __('crm.status.' . $task->status) }}</span>
                        </td>
                        <td>
                            <button type="button" class="crm-btn" data-crm-modal
                                    data-crm-title="{{ __('crm.actions.edit') }}"
                                    data-crm-action="{{ route('company.crm.tasks.update', $task) }}"
                                    data-crm-template="tpl-task-edit-{{ $task->id }}">{{ __('crm.actions.edit') }}</button>
                            <form method="POST" action="{{ route('company.crm.tasks.destroy', $task) }}" data-confirm="{{ __('crm.actions.confirm_delete') }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="crm-btn">{{ __('crm.actions.delete') }}</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="crm-empty">{{ __('crm.empty.tasks') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="crm-body">
        @include('company.crm._pagination', ['paginator' => $tasks])
    </div>
</section>

<template id="tpl-task-create">
    @csrf
    @include('company.crm._task_fields', ['task' => null])
</template>

@foreach ($tasks as $task)
    <template id="tpl-task-edit-{{ $task->id }}">
        @csrf
        @method('PUT')
        @include('company.crm._task_fields', ['task' => $task])
    </template>
@endforeach
@endsection
