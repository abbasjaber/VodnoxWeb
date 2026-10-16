@extends('layouts.company')

@section('title', __('company.communications_title'))
@section('crumb', __('company.communications_title'))

@section('content')
@include('company._assets')
@include('company._alerts')

<div class="page-head">
    <div>
        <h1>{{ __('company.communications_title') }}</h1>
        <p>{{ __('company.communications_subtitle') }}</p>
    </div>
    <div class="head-actions">
        <button type="button" class="btn-primary"
                data-company-modal
                data-company-title="{{ __('company.add_schedule') }}"
                data-company-action="{{ route('company.communications.store') }}"
                data-company-submit="{{ __('company.save') }}"
                data-company-template="tpl-schedule-create">{{ __('company.add_schedule') }}</button>
    </div>
</div>

<div class="comm-grid">
    <div class="comm-stack">
        <div class="card">
            <div class="card-head">
                <div><h2>{{ __('company.schedules') }}</h2><p>{{ $schedules->count() }}</p></div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>{{ __('company.schedule') }}</th>
                            <th>{{ __('company.schedule_strategy') }}</th>
                            <th>{{ __('company.days') }}</th>
                            <th>{{ __('company.targets') }}</th>
                            <th>{{ __('company.status') }}</th>
                            <th>{{ __('company.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($schedules as $schedule)
                            <tr>
                                <td>
                                    <strong>{{ $schedule->name_ar }}</strong><br>
                                    <small>{{ $schedule->name_en }}</small>
                                </td>
                                <td>
                                    {{ __('company.strategy_'.$schedule->strategy) }}
                                    <br><small>{{ $schedule->ring_duration }}s</small>
                                </td>
                                <td>
                                    @foreach ($schedule->days as $day)
                                        <span class="tag">{{ __('company.day_'.$day->day) }} {{ substr($day->start_time, 0, 5) }}–{{ substr($day->end_time, 0, 5) }}</span>
                                    @endforeach
                                </td>
                                <td>
                                    <ol class="destination-list">
                                        @foreach ($schedule->targets as $target)
                                            <li>
                                                <span>{{ $target->order }}. {{ $target->user?->name ?? '—' }}</span>
                                                @if ($schedule->strategy === 'sequential')
                                                    <span class="comm-clock">{{ $target->ring_duration ?? $schedule->ring_duration }}s</span>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ol>
                                </td>
                                <td><span class="status {{ $schedule->is_active ? 'active' : 'inactive' }}">{{ $schedule->is_active ? __('company.active') : __('company.inactive') }}</span></td>
                                <td>
                                    <div class="table-actions">
                                        <button type="button" class="row-link"
                                                data-company-modal
                                                data-company-title="{{ __('company.edit_schedule') }}"
                                                data-company-action="{{ route('company.communications.update', $schedule) }}"
                                                data-company-submit="{{ __('company.save') }}"
                                                data-company-template="tpl-schedule-edit-{{ $schedule->id }}">{{ __('company.edit') }}</button>
                                        <form method="POST" action="{{ route('company.communications.destroy', $schedule) }}" data-confirm="{{ __('company.confirm_delete') }}">
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
        </div>
    </div>

    <div class="comm-preview">
        <div class="card">
            <div class="card-head">
                <div><h2>{{ __('company.test_route') }}</h2><p>{{ __('company.test_route_hint') }}</p></div>
            </div>
            <form id="comm-test"
                  data-comm-error="{{ __('company.test_invalid_datetime') }}"
                  style="padding:0 22px 22px">
                @csrf
                <div class="form-field">
                    <label for="comm-test-schedule">{{ __('company.schedule') }}</label>
                    <select id="comm-test-schedule" data-comm-test-schedule>
                        @foreach ($schedules as $schedule)
                            <option value="{{ $schedule->id }}" data-url="{{ route('company.communications.test', $schedule) }}" @selected($testSchedule && $testSchedule->id === $schedule->id)>{{ $schedule->name_ar }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-field">
                    <label for="comm-test-datetime">{{ __('company.test_datetime') }}</label>
                    <input id="comm-test-datetime" type="datetime-local" name="datetime">
                </div>
                <div class="form-field">
                    <button type="submit" class="btn-primary">{{ __('company.test_run') }}</button>
                </div>
                <div id="comm-test-result"></div>
            </form>
        </div>
    </div>
</div>

<template id="tpl-comm-day">
    <div data-company-row style="display:grid;grid-template-columns:1.2fr 1fr 1fr auto;gap:8px;align-items:end;margin-bottom:10px">
        <div class="form-field"><label>{{ __('company.day') }}</label>
            <select name="days[0][day]">
                @for ($d = 0; $d <= 6; $d++)
                    <option value="{{ $d }}">{{ __('company.day_'.$d) }}</option>
                @endfor
            </select>
        </div>
        <div class="form-field"><label>{{ __('company.start_time') }}</label><input type="time" name="days[0][start_time]" value="09:00"></div>
        <div class="form-field"><label>{{ __('company.end_time') }}</label><input type="time" name="days[0][end_time]" value="17:00"></div>
        <button type="button" class="btn-quiet" data-comm-remove-row>{{ __('company.remove_day') }}</button>
    </div>
</template>

<template id="tpl-comm-target">
    <div data-company-row style="display:grid;grid-template-columns:1fr auto auto;gap:8px;align-items:end;margin-bottom:10px">
        <div class="form-field"><label>{{ __('company.target_1') }}</label>
            <select name="targets[0][user_id]">
                <option value="">—</option>
                @foreach ($users as $user)
                    <option value="{{ $user->id }}">{{ $user->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-field comm-target-ring"><label>{{ __('company.target_ring') }}</label><input type="number" name="targets[0][ring_duration]" min="5" max="120" value="20"></div>
        <button type="button" class="btn-quiet" data-comm-remove-row>{{ __('company.remove_day') }}</button>
    </div>
</template>

<template id="tpl-schedule-create">
    @csrf
    <div class="form-grid">
        <div class="form-field"><label for="sc-name-ar">{{ __('company.name_ar') }}</label><input id="sc-name-ar" type="text" name="name_ar" value="{{ old('name_ar') }}" required></div>
        <div class="form-field"><label for="sc-name-en">{{ __('company.name_en') }}</label><input id="sc-name-en" type="text" name="name_en" value="{{ old('name_en') }}" required></div>
        <div class="form-field"><label for="sc-strategy">{{ __('company.schedule_strategy') }}</label>
            <select id="sc-strategy" name="strategy" data-comm-strategy required>
                <option value="simultaneous" @selected(old('strategy') === 'simultaneous')>{{ __('company.strategy_simultaneous') }}</option>
                <option value="sequential" @selected(old('strategy') === 'sequential')>{{ __('company.strategy_sequential') }}</option>
            </select>
        </div>
        <div class="form-field"><label for="sc-ring">{{ __('company.ring_duration') }}</label><input id="sc-ring" type="number" name="ring_duration" min="5" max="120" value="{{ old('ring_duration', 20) }}" required><small>{{ __('company.ring_duration_hint') }}</small></div>
        <div class="form-field full"><label class="checkbox-line"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', true))> {{ __('company.active') }}</label></div>
    </div>

    <div class="card-head"><div><h2>{{ __('company.days') }}</h2></div>
        <button type="button" class="btn-secondary" data-comm-add-day="comm-days-create">{{ __('company.add_day') }}</button>
    </div>
    <div id="comm-days-create" data-company-rows>
        <div data-company-row style="display:grid;grid-template-columns:1.2fr 1fr 1fr auto;gap:8px;align-items:end;margin-bottom:10px">
            <div class="form-field"><label>{{ __('company.day') }}</label>
                <select name="days[0][day]">
                    @for ($d = 0; $d <= 6; $d++)
                        <option value="{{ $d }}">{{ __('company.day_'.$d) }}</option>
                    @endfor
                </select>
            </div>
            <div class="form-field"><label>{{ __('company.start_time') }}</label><input type="time" name="days[0][start_time]" value="09:00"></div>
            <div class="form-field"><label>{{ __('company.end_time') }}</label><input type="time" name="days[0][end_time]" value="17:00"></div>
            <button type="button" class="btn-quiet" data-comm-remove-row>{{ __('company.remove_day') }}</button>
        </div>
    </div>

    <div class="card-head"><div><h2>{{ __('company.targets') }}</h2><p>{{ __('company.targets_hint') }}</p></div>
        <button type="button" class="btn-secondary" data-comm-add-target="comm-targets-create">{{ __('company.add') }}</button>
    </div>
    <div id="comm-targets-create" data-company-rows>
        <div data-company-row style="display:grid;grid-template-columns:1fr auto auto;gap:8px;align-items:end;margin-bottom:10px">
            <div class="form-field"><label>{{ __('company.target_1') }}</label>
                <select name="targets[0][user_id]">
                    <option value="">—</option>
                    @foreach ($users as $user)
                        <option value="{{ $user->id }}">{{ $user->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-field comm-target-ring"><label>{{ __('company.target_ring') }}</label><input type="number" name="targets[0][ring_duration]" min="5" max="120" value="20"></div>
            <button type="button" class="btn-quiet" data-comm-remove-row>{{ __('company.remove_day') }}</button>
        </div>
    </div>

    <div class="form-grid">
        <div class="form-field"><label for="sc-na-type">{{ __('company.no_answer_type') }}</label>
            <select id="sc-na-type" name="no_answer_type" data-comm-noanswer required>
                <option value="voicemail" @selected(old('no_answer_type') === 'voicemail')>{{ __('company.na_voicemail') }}</option>
                <option value="message" @selected(old('no_answer_type') === 'message')>{{ __('company.na_message') }}</option>
                <option value="hangup" @selected(old('no_answer_type') === 'hangup')>{{ __('company.na_hangup') }}</option>
                <option value="agent" @selected(old('no_answer_type') === 'agent')>{{ __('company.na_agent') }}</option>
            </select>
        </div>
        <div class="form-field comm-na-target"><label for="sc-na-target">{{ __('company.no_answer_target') }}</label>
            <select id="sc-na-target" name="no_answer_target_id">
                <option value="">—</option>
                @foreach ($users as $user)
                    <option value="{{ $user->id }}">{{ $user->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-field"><label for="sc-fallback">{{ __('company.fallback_ring_duration') }}</label><input id="sc-fallback" type="number" name="fallback_ring_duration" min="5" max="120" value="{{ old('fallback_ring_duration', 20) }}"></div>
        <div class="form-field"><label for="sc-oh-type">{{ __('company.outside_hours_type') }}</label>
            <select id="sc-oh-type" name="outside_hours_type" data-comm-outside required>
                <option value="voicemail" @selected(old('outside_hours_type') === 'voicemail')>{{ __('company.oh_voicemail') }}</option>
                <option value="ivr" @selected(old('outside_hours_type') === 'ivr')>{{ __('company.oh_ivr') }}</option>
                <option value="hangup" @selected(old('outside_hours_type') === 'hangup')>{{ __('company.oh_hangup') }}</option>
            </select>
        </div>
        <div class="form-field comm-oh-target"><label for="sc-oh-target">{{ __('company.outside_hours_target') }}</label>
            <select id="sc-oh-target" name="outside_hours_target_id">
                <option value="">—</option>
                @foreach ($ivrMenus as $menu)
                    <option value="{{ $menu->id }}">{{ $menu->name_ar }}</option>
                @endforeach
            </select>
        </div>
    </div>
</template>

@foreach ($schedules as $schedule)
    <template id="tpl-schedule-edit-{{ $schedule->id }}">
        @csrf
        @method('PUT')
        <div class="form-grid">
            <div class="form-field"><label for="se{{ $schedule->id }}-name-ar">{{ __('company.name_ar') }}</label><input id="se{{ $schedule->id }}-name-ar" type="text" name="name_ar" value="{{ $schedule->name_ar }}" required></div>
            <div class="form-field"><label for="se{{ $schedule->id }}-name-en">{{ __('company.name_en') }}</label><input id="se{{ $schedule->id }}-name-en" type="text" name="name_en" value="{{ $schedule->name_en }}" required></div>
            <div class="form-field"><label for="se{{ $schedule->id }}-strategy">{{ __('company.schedule_strategy') }}</label>
                <select id="se{{ $schedule->id }}-strategy" name="strategy" data-comm-strategy required>
                    <option value="simultaneous" @selected($schedule->strategy === 'simultaneous')>{{ __('company.strategy_simultaneous') }}</option>
                    <option value="sequential" @selected($schedule->strategy === 'sequential')>{{ __('company.strategy_sequential') }}</option>
                </select>
            </div>
            <div class="form-field"><label for="se{{ $schedule->id }}-ring">{{ __('company.ring_duration') }}</label><input id="se{{ $schedule->id }}-ring" type="number" name="ring_duration" min="5" max="120" value="{{ $schedule->ring_duration }}" required><small>{{ __('company.ring_duration_hint') }}</small></div>
            <div class="form-field full"><label class="checkbox-line"><input type="checkbox" name="is_active" value="1" @checked($schedule->is_active)> {{ __('company.active') }}</label></div>
        </div>

        <div class="card-head"><div><h2>{{ __('company.days') }}</h2></div>
            <button type="button" class="btn-secondary" data-comm-add-day="comm-days-{{ $schedule->id }}">{{ __('company.add_day') }}</button>
        </div>
        <div id="comm-days-{{ $schedule->id }}" data-company-rows>
            @foreach ($schedule->days as $index => $day)
                <div data-company-row style="display:grid;grid-template-columns:1.2fr 1fr 1fr auto;gap:8px;align-items:end;margin-bottom:10px">
                    <div class="form-field"><label>{{ __('company.day') }}</label>
                        <select name="days[{{ $index }}][day]">
                            @for ($d = 0; $d <= 6; $d++)
                                <option value="{{ $d }}" @selected((int) $day->day === $d)>{{ __('company.day_'.$d) }}</option>
                            @endfor
                        </select>
                    </div>
                    <div class="form-field"><label>{{ __('company.start_time') }}</label><input type="time" name="days[{{ $index }}][start_time]" value="{{ substr($day->start_time, 0, 5) }}"></div>
                    <div class="form-field"><label>{{ __('company.end_time') }}</label><input type="time" name="days[{{ $index }}][end_time]" value="{{ substr($day->end_time, 0, 5) }}"></div>
                    <button type="button" class="btn-quiet" data-comm-remove-row>{{ __('company.remove_day') }}</button>
                </div>
            @endforeach
        </div>

        <div class="card-head"><div><h2>{{ __('company.targets') }}</h2><p>{{ __('company.targets_hint') }}</p></div>
            <button type="button" class="btn-secondary" data-comm-add-target="comm-targets-{{ $schedule->id }}">{{ __('company.add') }}</button>
        </div>
        <div id="comm-targets-{{ $schedule->id }}" data-company-rows>
            @foreach ($schedule->targets as $index => $target)
                <div data-company-row style="display:grid;grid-template-columns:1fr auto auto;gap:8px;align-items:end;margin-bottom:10px">
                    <div class="form-field"><label>{{ $index === 0 ? __('company.target_1') : __('company.target_2') }}</label>
                        <select name="targets[{{ $index }}][user_id]">
                            <option value="">—</option>
                            @foreach ($users as $user)
                                <option value="{{ $user->id }}" @selected($target->user_id === $user->id)>{{ $user->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-field comm-target-ring"><label>{{ __('company.target_ring') }}</label><input type="number" name="targets[{{ $index }}][ring_duration]" min="5" max="120" value="{{ $target->ring_duration ?? $schedule->ring_duration }}"></div>
                    <button type="button" class="btn-quiet" data-comm-remove-row>{{ __('company.remove_day') }}</button>
                </div>
            @endforeach
        </div>

        <div class="form-grid">
            <div class="form-field"><label for="se{{ $schedule->id }}-na-type">{{ __('company.no_answer_type') }}</label>
                <select id="se{{ $schedule->id }}-na-type" name="no_answer_type" data-comm-noanswer required>
                    <option value="voicemail" @selected($schedule->no_answer_type === 'voicemail')>{{ __('company.na_voicemail') }}</option>
                    <option value="message" @selected($schedule->no_answer_type === 'message')>{{ __('company.na_message') }}</option>
                    <option value="hangup" @selected($schedule->no_answer_type === 'hangup')>{{ __('company.na_hangup') }}</option>
                    <option value="agent" @selected($schedule->no_answer_type === 'agent')>{{ __('company.na_agent') }}</option>
                </select>
            </div>
            <div class="form-field comm-na-target"><label for="se{{ $schedule->id }}-na-target">{{ __('company.no_answer_target') }}</label>
                <select id="se{{ $schedule->id }}-na-target" name="no_answer_target_id">
                    <option value="">—</option>
                    @foreach ($users as $user)
                        <option value="{{ $user->id }}" @selected($schedule->no_answer_target_id === $user->id)>{{ $user->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-field"><label for="se{{ $schedule->id }}-fallback">{{ __('company.fallback_ring_duration') }}</label><input id="se{{ $schedule->id }}-fallback" type="number" name="fallback_ring_duration" min="5" max="120" value="{{ $schedule->fallback_ring_duration ?? 20 }}"></div>
            <div class="form-field"><label for="se{{ $schedule->id }}-oh-type">{{ __('company.outside_hours_type') }}</label>
                <select id="se{{ $schedule->id }}-oh-type" name="outside_hours_type" data-comm-outside required>
                    <option value="voicemail" @selected($schedule->outside_hours_type === 'voicemail')>{{ __('company.oh_voicemail') }}</option>
                    <option value="ivr" @selected($schedule->outside_hours_type === 'ivr')>{{ __('company.oh_ivr') }}</option>
                    <option value="hangup" @selected($schedule->outside_hours_type === 'hangup')>{{ __('company.oh_hangup') }}</option>
                </select>
            </div>
            <div class="form-field comm-oh-target"><label for="se{{ $schedule->id }}-oh-target">{{ __('company.outside_hours_target') }}</label>
                <select id="se{{ $schedule->id }}-oh-target" name="outside_hours_target_id">
                    <option value="">—</option>
                    @foreach ($ivrMenus as $menu)
                        <option value="{{ $menu->id }}" @selected($schedule->outside_hours_target_id === $menu->id)>{{ $menu->name_ar }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </template>
@endforeach
@endsection
