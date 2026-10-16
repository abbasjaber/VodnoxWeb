@extends('layouts.company')

@section('title', __('company.queues_title'))
@section('crumb', __('company.queues_title'))

@section('content')
@include('company._assets')
@include('company._alerts')

<div class="page-head">
    <div>
        <h1>{{ __('company.queues_title') }}</h1>
        <p>{{ __('company.queues_subtitle') }}</p>
    </div>
    <div class="head-actions">
        <button type="button" class="btn-primary"
                data-company-modal
                data-company-title="{{ __('company.add_queue') }}"
                data-company-action="{{ route('company.queues.store') }}"
                data-company-submit="{{ __('company.save') }}"
                data-company-template="tpl-queue-create">{{ __('company.add_queue') }}</button>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <div><h2>{{ __('company.queue') }}</h2><p>{{ $queues->total() }}</p></div>
    </div>

    <form class="filters" method="GET" action="{{ route('company.queues.index') }}">
        <div class="search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.6-3.6"/></svg>
            <input type="search" name="q" value="{{ $q }}" placeholder="{{ __('company.search') }}" aria-label="{{ __('company.search') }}">
        </div>
        <select name="status" aria-label="{{ __('company.status') }}">
            <option value="">{{ __('company.all') }}</option>
            <option value="active" @selected($status === 'active')>{{ __('company.active') }}</option>
            <option value="inactive" @selected($status === 'inactive')>{{ __('company.inactive') }}</option>
        </select>
        <button type="submit" class="btn-primary">{{ __('company.search') }}</button>
        @if ($q !== '' || $status !== '')
            <a class="btn-secondary" href="{{ route('company.queues.index') }}">{{ __('company.all') }}</a>
        @endif
    </form>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>{{ __('company.name') }}</th>
                    <th>{{ __('company.strategy') }}</th>
                    <th>{{ __('company.timeout') }}</th>
                    <th>{{ __('company.queue_members') }}</th>
                    <th>{{ __('company.status') }}</th>
                    <th>{{ __('company.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($queues as $queue)
                    <tr>
                        <td><strong>{{ $queue->name_ar }}</strong><br><small>{{ $queue->name_en }}</small></td>
                        <td>{{ __('company.strategy_'.$queue->strategy) }}</td>
                        <td>{{ $queue->timeout }}s</td>
                        <td>
                            <div class="queue-members">
                                @forelse ($queue->members->take(4) as $member)
                                    <span class="mini-avatar" title="{{ $member->name }}">{{ mb_substr($member->name, 0, 1) }}</span>
                                @empty
                                    —
                                @endforelse
                                @if ($queue->members->count() > 4)
                                    <span class="mini-avatar">+{{ $queue->members->count() - 4 }}</span>
                                @endif
                            </div>
                        </td>
                        <td><span class="status {{ $queue->is_active ? 'active' : 'inactive' }}">{{ $queue->is_active ? __('company.active') : __('company.inactive') }}</span></td>
                        <td>
                            <div class="table-actions">
                                <button type="button" class="row-link"
                                        data-company-modal
                                        data-company-title="{{ __('company.edit_queue') }}"
                                        data-company-action="{{ route('company.queues.update', $queue) }}"
                                        data-company-submit="{{ __('company.save') }}"
                                        data-company-template="tpl-queue-edit-{{ $queue->id }}">{{ __('company.edit') }}</button>
                                <form method="POST" action="{{ route('company.queues.destroy', $queue) }}" data-confirm="{{ __('company.confirm_delete') }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="row-link">{{ __('company.delete') }}</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="empty-row">{{ __('company.no_data') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="table-foot">
        <span>{{ __('company.showing') }} {{ $queues->firstItem() ?? 0 }}–{{ $queues->lastItem() ?? 0 }} / {{ $queues->total() }}</span>
        @include('company._pagination', ['paginator' => $queues])
    </div>
</div>

<template id="tpl-queue-create">
    @csrf
    <div class="form-grid">
        <div class="form-field"><label for="qc-name-ar">{{ __('company.name_ar') }}</label><input id="qc-name-ar" type="text" name="name_ar" value="{{ old('name_ar') }}" required></div>
        <div class="form-field"><label for="qc-name-en">{{ __('company.name_en') }}</label><input id="qc-name-en" type="text" name="name_en" value="{{ old('name_en') }}" required></div>
        <div class="form-field"><label for="qc-strategy">{{ __('company.strategy') }}</label>
            <select id="qc-strategy" name="strategy" required>
                @foreach (['ring_all', 'least_recent', 'fewest_calls', 'random'] as $option)
                    <option value="{{ $option }}" @selected(old('strategy') === $option)>{{ __('company.strategy_'.$option) }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-field"><label for="qc-timeout">{{ __('company.timeout') }}</label><input id="qc-timeout" type="number" name="timeout" min="5" max="300" value="{{ old('timeout', 30) }}" required></div>
        <div class="form-field full"><label class="checkbox-line"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', true))> {{ __('company.active') }}</label></div>
        <div class="form-field full">
            <label>{{ __('company.queue_members') }}</label>
            <small>{{ __('company.queue_members_hint') }}</small>
            <div class="checkbox-list">
                @foreach ($users as $user)
                    <label><input type="checkbox" name="members[]" value="{{ $user->id }}" @checked(in_array($user->id, (array) old('members', []), true))> {{ $user->name }}</label>
                @endforeach
            </div>
        </div>
    </div>
</template>

@foreach ($queues as $queue)
    <template id="tpl-queue-edit-{{ $queue->id }}">
        @csrf
        @method('PUT')
        <div class="form-grid">
            <div class="form-field"><label for="qe{{ $queue->id }}-name-ar">{{ __('company.name_ar') }}</label><input id="qe{{ $queue->id }}-name-ar" type="text" name="name_ar" value="{{ $queue->name_ar }}" required></div>
            <div class="form-field"><label for="qe{{ $queue->id }}-name-en">{{ __('company.name_en') }}</label><input id="qe{{ $queue->id }}-name-en" type="text" name="name_en" value="{{ $queue->name_en }}" required></div>
            <div class="form-field"><label for="qe{{ $queue->id }}-strategy">{{ __('company.strategy') }}</label>
                <select id="qe{{ $queue->id }}-strategy" name="strategy" required>
                    @foreach (['ring_all', 'least_recent', 'fewest_calls', 'random'] as $option)
                        <option value="{{ $option }}" @selected($queue->strategy === $option)>{{ __('company.strategy_'.$option) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-field"><label for="qe{{ $queue->id }}-timeout">{{ __('company.timeout') }}</label><input id="qe{{ $queue->id }}-timeout" type="number" name="timeout" min="5" max="300" value="{{ $queue->timeout }}" required></div>
            <div class="form-field full"><label class="checkbox-line"><input type="checkbox" name="is_active" value="1" @checked($queue->is_active)> {{ __('company.active') }}</label></div>
            <div class="form-field full">
                <label>{{ __('company.queue_members') }}</label>
                <small>{{ __('company.queue_members_hint') }}</small>
                <div class="checkbox-list">
                    @foreach ($users as $user)
                        <label><input type="checkbox" name="members[]" value="{{ $user->id }}" @checked($queue->members->contains($user->id))> {{ $user->name }}</label>
                    @endforeach
                </div>
            </div>
        </div>
    </template>
@endforeach
@endsection
