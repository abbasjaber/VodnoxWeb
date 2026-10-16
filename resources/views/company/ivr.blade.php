@extends('layouts.company')

@section('title', __('company.ivr_title'))
@section('crumb', __('company.ivr_title'))

@php
    $destOptions = [];
    foreach ($extensions as $extension) {
        $destOptions[] = ['value' => 'extension:'.$extension->id, 'label' => __('company.dest_extension').' — '.$extension->extension.($extension->label ? ' / '.$extension->label : '')];
    }
    foreach ($queues as $queue) {
        $destOptions[] = ['value' => 'queue:'.$queue->id, 'label' => __('company.dest_queue').' — '.$queue->name_ar];
    }
    foreach ($menus as $menu) {
        $destOptions[] = ['value' => 'menu:'.$menu->id, 'label' => __('company.dest_menu').' — '.$menu->name_ar];
    }
    $destOptions[] = ['value' => 'voicemail', 'label' => __('company.dest_voicemail')];
    $destOptions[] = ['value' => 'hangup', 'label' => __('company.dest_hangup')];
    $destLabels = collect($destOptions)->pluck('label', 'value');
@endphp

@section('content')
@include('company._assets')
@include('company._alerts')

<div class="page-head">
    <div>
        <h1>{{ __('company.ivr_title') }}</h1>
        <p>{{ __('company.ivr_subtitle') }}</p>
    </div>
    <div class="head-actions">
        <button type="button" class="btn-primary"
                data-company-modal
                data-company-title="{{ __('company.add_ivr') }}"
                data-company-action="{{ route('company.ivr.store') }}"
                data-company-submit="{{ __('company.save') }}"
                data-company-template="tpl-ivr-create">{{ __('company.add_ivr') }}</button>
    </div>
</div>

@forelse ($menus as $menu)
    <div class="card">
        <div class="card-head">
            <div>
                <h2>{{ $menu->name_ar }}</h2>
                <p>{{ $menu->name_en }} · {{ __('company.ivr_timeout') }}: {{ $menu->timeout }}s · {{ __('company.max_attempts') }}: {{ $menu->max_attempts }}</p>
            </div>
            <div class="table-actions">
                <span class="status {{ $menu->is_active ? 'active' : 'inactive' }}">{{ $menu->is_active ? __('company.active') : __('company.inactive') }}</span>
                <button type="button" class="row-link"
                        data-company-modal
                        data-company-title="{{ __('company.edit_ivr') }}"
                        data-company-action="{{ route('company.ivr.update', $menu) }}"
                        data-company-submit="{{ __('company.save') }}"
                        data-company-template="tpl-ivr-edit-{{ $menu->id }}">{{ __('company.edit') }}</button>
                <form method="POST" action="{{ route('company.ivr.destroy', $menu) }}" data-confirm="{{ __('company.confirm_delete') }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="row-link">{{ __('company.delete') }}</button>
                </form>
            </div>
        </div>
        <div class="routing">
            @forelse ($menu->options as $option)
                @php
                    $optionValue = $option->destination_type.($option->destination_id ? ':'.$option->destination_id : '');
                @endphp
                <div class="routing-branch">
                    <span class="digit">{{ $option->digit }}</span>
                    <span class="target">{{ $destLabels[$optionValue] ?? $optionValue }}</span>
                </div>
            @empty
                <p class="section-note">{{ __('company.no_data') }}</p>
            @endforelse
            @php
                $invalidValue = $menu->invalid_destination_type.($menu->invalid_destination_id ? ':'.$menu->invalid_destination_id : '');
                $noInputValue = $menu->no_input_destination_type.($menu->no_input_destination_id ? ':'.$menu->no_input_destination_id : '');
            @endphp
            <div class="routing-branch">
                <span class="digit">!</span>
                <span class="target">{{ __('company.invalid_destination') }}: {{ $destLabels[$invalidValue] ?? $invalidValue }}</span>
            </div>
            <div class="routing-branch">
                <span class="digit">#</span>
                <span class="target">{{ __('company.no_input_destination') }}: {{ $destLabels[$noInputValue] ?? $noInputValue }}</span>
            </div>
        </div>
    </div>
@empty
    <div class="empty">
        <p>{{ __('company.no_data') }}</p>
    </div>
@endforelse

<template id="tpl-ivr-option">
    <div class="comm-key" data-company-row>
        <div class="form-field"><label>{{ __('company.digit') }}</label><input type="text" name="options[0][digit]" maxlength="1" inputmode="numeric" pattern="[0-9]"></div>
        <div class="form-field"><label>{{ __('company.destination') }}</label>
            <select name="options[0][destination]">
                @foreach ($destOptions as $destOption)
                    <option value="{{ $destOption['value'] }}">{{ $destOption['label'] }}</option>
                @endforeach
            </select>
        </div>
        <button type="button" class="btn-quiet" data-comm-remove-row>{{ __('company.remove_day') }}</button>
    </div>
</template>

<template id="tpl-ivr-create">
    @csrf
    <div class="form-grid">
        <div class="form-field"><label for="ic-name-ar">{{ __('company.name_ar') }}</label><input id="ic-name-ar" type="text" name="name_ar" value="{{ old('name_ar') }}" required></div>
        <div class="form-field"><label for="ic-name-en">{{ __('company.name_en') }}</label><input id="ic-name-en" type="text" name="name_en" value="{{ old('name_en') }}" required></div>
        <div class="form-field"><label for="ic-timeout">{{ __('company.ivr_timeout') }}</label><input id="ic-timeout" type="number" name="timeout" min="3" max="30" value="{{ old('timeout', 5) }}" required></div>
        <div class="form-field"><label for="ic-attempts">{{ __('company.max_attempts') }}</label><input id="ic-attempts" type="number" name="max_attempts" min="0" max="3" value="{{ old('max_attempts', 3) }}" required></div>
        <div class="form-field full"><label for="ic-greeting-ar">{{ __('company.greeting_ar') }}</label><textarea id="ic-greeting-ar" name="greeting_ar" rows="2">{{ old('greeting_ar') }}</textarea></div>
        <div class="form-field full"><label for="ic-greeting-en">{{ __('company.greeting_en') }}</label><textarea id="ic-greeting-en" name="greeting_en" rows="2">{{ old('greeting_en') }}</textarea></div>
        <div class="form-field"><label for="ic-invalid">{{ __('company.invalid_destination') }}</label>
            <select id="ic-invalid" name="invalid_destination">
                @foreach ($destOptions as $destOption)
                    <option value="{{ $destOption['value'] }}">{{ $destOption['label'] }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-field"><label for="ic-noinput">{{ __('company.no_input_destination') }}</label>
            <select id="ic-noinput" name="no_input_destination">
                @foreach ($destOptions as $destOption)
                    <option value="{{ $destOption['value'] }}">{{ $destOption['label'] }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-field full"><label class="checkbox-line"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', true))> {{ __('company.active') }}</label></div>
    </div>
    <div class="card-head"><div><h2>{{ __('company.ivr_options') }}</h2></div>
        <button type="button" class="btn-secondary" data-company-add="ivr-options-create" data-company-template="tpl-ivr-option">{{ __('company.add_option') }}</button>
    </div>
    <div class="comm-stack" id="ivr-options-create" data-company-rows></div>
</template>

@foreach ($menus as $menu)
    <template id="tpl-ivr-edit-{{ $menu->id }}">
        @csrf
        @method('PUT')
        <div class="form-grid">
            <div class="form-field"><label for="ie{{ $menu->id }}-name-ar">{{ __('company.name_ar') }}</label><input id="ie{{ $menu->id }}-name-ar" type="text" name="name_ar" value="{{ $menu->name_ar }}" required></div>
            <div class="form-field"><label for="ie{{ $menu->id }}-name-en">{{ __('company.name_en') }}</label><input id="ie{{ $menu->id }}-name-en" type="text" name="name_en" value="{{ $menu->name_en }}" required></div>
            <div class="form-field"><label for="ie{{ $menu->id }}-timeout">{{ __('company.ivr_timeout') }}</label><input id="ie{{ $menu->id }}-timeout" type="number" name="timeout" min="3" max="30" value="{{ $menu->timeout }}" required></div>
            <div class="form-field"><label for="ie{{ $menu->id }}-attempts">{{ __('company.max_attempts') }}</label><input id="ie{{ $menu->id }}-attempts" type="number" name="max_attempts" min="0" max="3" value="{{ $menu->max_attempts }}" required></div>
            <div class="form-field full"><label for="ie{{ $menu->id }}-greeting-ar">{{ __('company.greeting_ar') }}</label><textarea id="ie{{ $menu->id }}-greeting-ar" name="greeting_ar" rows="2">{{ $menu->greeting_ar }}</textarea></div>
            <div class="form-field full"><label for="ie{{ $menu->id }}-greeting-en">{{ __('company.greeting_en') }}</label><textarea id="ie{{ $menu->id }}-greeting-en" name="greeting_en" rows="2">{{ $menu->greeting_en }}</textarea></div>
            <div class="form-field"><label for="ie{{ $menu->id }}-invalid">{{ __('company.invalid_destination') }}</label>
                <select id="ie{{ $menu->id }}-invalid" name="invalid_destination">
                    @foreach ($destOptions as $destOption)
                        <option value="{{ $destOption['value'] }}" @selected($invalidValue === $destOption['value'])>{{ $destOption['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-field"><label for="ie{{ $menu->id }}-noinput">{{ __('company.no_input_destination') }}</label>
                <select id="ie{{ $menu->id }}-noinput" name="no_input_destination">
                    @foreach ($destOptions as $destOption)
                        <option value="{{ $destOption['value'] }}" @selected($noInputValue === $destOption['value'])>{{ $destOption['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-field full"><label class="checkbox-line"><input type="checkbox" name="is_active" value="1" @checked($menu->is_active)> {{ __('company.active') }}</label></div>
        </div>
        <div class="card-head"><div><h2>{{ __('company.ivr_options') }}</h2></div>
            <button type="button" class="btn-secondary" data-company-add="ivr-options-{{ $menu->id }}" data-company-template="tpl-ivr-option">{{ __('company.add_option') }}</button>
        </div>
        <div class="comm-stack" id="ivr-options-{{ $menu->id }}" data-company-rows>
            @foreach ($menu->options as $index => $option)
                @php
                    $optionValue = $option->destination_type.($option->destination_id ? ':'.$option->destination_id : '');
                @endphp
                <div class="comm-key" data-company-row>
                    <div class="form-field"><label>{{ __('company.digit') }}</label><input type="text" name="options[{{ $index }}][digit]" value="{{ $option->digit }}" maxlength="1" inputmode="numeric" pattern="[0-9]"></div>
                    <div class="form-field"><label>{{ __('company.destination') }}</label>
                        <select name="options[{{ $index }}][destination]">
                            @foreach ($destOptions as $destOption)
                                <option value="{{ $destOption['value'] }}" @selected($optionValue === $destOption['value'])>{{ $destOption['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="button" class="btn-quiet" data-comm-remove-row>{{ __('company.remove_day') }}</button>
                </div>
            @endforeach
        </div>
    </template>
@endforeach
@endsection
