@extends('layouts.public')

@section('title', app()->getLocale() === 'ar'
    ? ($form->title_ar ?: $form->title_en)
    : ($form->title_en ?: $form->title_ar))

@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/vodnox/crm.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/vodnox/forms.css') }}">
@endpush

@section('content')
<script>document.body.setAttribute('data-forms-role', 'public');</script>

@php
    $loc = fn (?string $ar, ?string $en) => app()->getLocale() === 'ar' ? ($ar ?: $en ?: '') : ($en ?: $ar ?: '');
    $reasonKey = match ($reason) {
        'not_published' => 'forms.public.not_published',
        'not_started' => 'forms.public.not_started',
        'full' => 'forms.public.full',
        default => 'forms.public.closed',
    };
@endphp

<div class="forms-public" style="--form-accent:#ff9f35">
    <main class="forms-public-main">
        @if ($submitted)
            <div class="thanks">
                <h1>{{ __('forms.public.thanks_title') }}</h1>
                <p>{{ $loc($form->thank_you_message_ar, $form->thank_you_message_en) }}</p>
            </div>
        @elseif (! $available)
            <div class="thanks">
                <h1>{{ __('forms.public.unavailable_title') }}</h1>
                <p>{{ __($reasonKey) }}</p>
                <p>{{ __('forms.public.unavailable_text') }}</p>
            </div>
        @else
            <div class="forms-sheet-head">
                <small class="crm-kicker">{{ __('forms.addon_name') }}</small>
                <h1>{{ $loc($form->title_ar, $form->title_en) }}</h1>
                <p>{{ $loc($form->description_ar, $form->description_en) }}</p>
                @if ($form->ends_at)
                    <p class="forms-status-note">{{ __('forms.public.deadline') }}: {{ $form->ends_at->timezone('Asia/Baghdad')->format('Y-m-d H:i') }}</p>
                @endif
            </div>

            @if ($errors->any())
                <div class="error-message" role="alert">
                    <ul style="margin:0;padding-inline-start:18px">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('forms.public.submit', $form->public_token) }}" id="forms-public-form">
                @csrf

                @foreach ($schema['fields'] ?? [] as $field)
                    @php
                        $id = (int) $field['id'];
                        $type = $field['type'] ?? 'short_text';
                        $label = $loc($field['label_ar'] ?? null, $field['label_en'] ?? null);
                        $help = $loc($field['help_text_ar'] ?? null, $field['help_text_en'] ?? null);
                        $placeholder = $loc($field['placeholder_ar'] ?? null, $field['placeholder_en'] ?? null);
                        $required = (bool) ($field['is_required'] ?? false);
                        $conditions = json_encode($field['conditions'] ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                    @endphp

                    @if ($type === 'section')
                        <div class="public-field">
                            <h2>{{ $label }}</h2>
                            @if ($help)<p class="help">{{ $help }}</p>@endif
                        </div>
                        @continue
                    @endif

                    <div class="public-field"
                        data-forms-field="{{ $id }}"
                        data-forms-conditions='{!! $conditions !!}'>
                        <h2>
                            {{ $label }}
                            @if ($required)<span class="required" aria-hidden="true">*</span>@endif
                        </h2>
                        @if ($help)<p class="help">{{ $help }}</p>@endif
                        @error((string) $id)<p class="help" style="color:#c45160">{{ $message }}</p>@enderror

                        @switch($type)
                            @case('long_text')
                                <textarea name="answers[{{ $id }}]" @if($required) required @endif
                                    @if(! empty($field['min_length'])) minlength="{{ $field['min_length'] }}" @endif
                                    @if(! empty($field['max_length'])) maxlength="{{ $field['max_length'] }}" @endif
                                    placeholder="{{ $placeholder }}">{{ old('answers.'.$id) }}</textarea>
                                @break

                            @case('email')
                                <input type="email" name="answers[{{ $id }}]" value="{{ old('answers.'.$id) }}"
                                    placeholder="{{ $placeholder }}" @if($required) required @endif>
                                @break

                            @case('phone')
                                <input type="tel" name="answers[{{ $id }}]" value="{{ old('answers.'.$id) }}"
                                    placeholder="{{ $placeholder }}" @if($required) required @endif>
                                @break

                            @case('number')
                                <input type="number" name="answers[{{ $id }}]" value="{{ old('answers.'.$id) }}"
                                    @if(isset($field['min_value']) && $field['min_value'] !== null) min="{{ $field['min_value'] }}" @endif
                                    @if(isset($field['max_value']) && $field['max_value'] !== null) max="{{ $field['max_value'] }}" @endif
                                    @if($required) required @endif>
                                @break

                            @case('date')
                                <input type="date" class="forms-date" name="answers[{{ $id }}]" value="{{ old('answers.'.$id) }}"
                                    @if($required) required @endif>
                                @break

                            @case('select')
                                <select name="answers[{{ $id }}]" @if($required) required @endif>
                                    <option value="">{{ __('forms.public.choose') }}</option>
                                    @foreach ($field['options'] ?? [] as $option)
                                        <option value="{{ $option['value'] }}" @selected(old('answers.'.$id) === $option['value'])>
                                            {{ $loc($option['label_ar'] ?? null, $option['label_en'] ?? null) }}
                                        </option>
                                    @endforeach
                                </select>
                                @break

                            @case('radio')
                                @foreach ($field['options'] ?? [] as $option)
                                    <label class="choice">
                                        <input type="radio" name="answers[{{ $id }}]" value="{{ $option['value'] }}"
                                            @checked(old('answers.'.$id) === $option['value']) @if($required) required @endif>
                                        {{ $loc($option['label_ar'] ?? null, $option['label_en'] ?? null) }}
                                    </label>
                                @endforeach
                                @break

                            @case('checkbox')
                                @foreach ($field['options'] ?? [] as $option)
                                    <label class="choice">
                                        <input type="checkbox" name="answers[{{ $id }}][]" value="{{ $option['value'] }}"
                                            @checked(in_array($option['value'], (array) old('answers.'.$id, []), true))>
                                        {{ $loc($option['label_ar'] ?? null, $option['label_en'] ?? null) }}
                                    </label>
                                @endforeach
                                @break

                            @case('rating')
                                <div class="rating-options">
                                    @for ($score = 1; $score <= (int) ($field['max_rating'] ?: 5); $score++)
                                        <label>
                                            <input type="radio" name="answers[{{ $id }}]" value="{{ $score }}"
                                                @checked((string) old('answers.'.$id) === (string) $score) @if($required) required @endif>
                                            {{ $score }}
                                        </label>
                                    @endfor
                                </div>
                                @break

                            @default
                                <input type="text" name="answers[{{ $id }}]" value="{{ old('answers.'.$id) }}"
                                    placeholder="{{ $placeholder }}" @if($required) required @endif
                                    @if(! empty($field['min_length'])) minlength="{{ $field['min_length'] }}" @endif
                                    @if(! empty($field['max_length'])) maxlength="{{ $field['max_length'] }}" @endif>
                        @endswitch
                    </div>
                @endforeach

                <p class="help">{{ __('forms.public.required_mark') }}</p>
                <button type="submit" class="crm-btn forms-submit">{{ __('forms.public.submit') }}</button>
                <p class="forms-note">{{ __('forms.public.privacy_note') }}</p>
            </form>
        @endif

        <p class="public-footer">{{ __('forms.public.powered_by') }}</p>
    </main>
</div>
@endsection

@push('scripts')
<script>
(function () {
    'use strict';

    var wraps = Array.prototype.slice.call(document.querySelectorAll('[data-forms-field]'));
    if (!wraps.length) { return; }

    function fieldValue(id) {
        var wrap = document.querySelector('[data-forms-field="' + id + '"]');
        if (!wrap) { return null; }
        var checks = [];
        var scalar = null;
        wrap.querySelectorAll('input, select, textarea').forEach(function (node) {
            if (node.type === 'checkbox') {
                if (node.checked) { checks.push(node.value); }
            } else if (node.type === 'radio') {
                if (node.checked) { scalar = node.value; }
            } else if (scalar === null) {
                scalar = node.value;
            }
        });
        return checks.length ? checks : scalar;
    }

    function evaluate(operator, actual, expected) {
        if (Array.isArray(actual)) {
            var values = actual.map(String);
            if (operator === 'equals' || operator === 'contains') { return values.indexOf(expected) !== -1; }
            if (operator === 'not_equals') { return values.indexOf(expected) === -1; }
            return false;
        }
        var a = actual === null || actual === undefined ? '' : String(actual);
        if (operator === 'equals') { return a === expected; }
        if (operator === 'not_equals') { return a !== expected; }
        if (operator === 'contains') { return expected !== '' && a.indexOf(expected) !== -1; }
        if (operator === 'greater_than') { return a !== '' && !isNaN(a) && parseFloat(a) > parseFloat(expected); }
        if (operator === 'less_than') { return a !== '' && !isNaN(a) && parseFloat(a) < parseFloat(expected); }
        return false;
    }

    function applyVisibility() {
        var visible = {};
        wraps.forEach(function (wrap) {
            var id = parseInt(wrap.getAttribute('data-forms-field'), 10);
            var conditions = [];
            try { conditions = JSON.parse(wrap.getAttribute('data-forms-conditions') || '[]'); } catch (error) { conditions = []; }

            var show = true;
            conditions.forEach(function (condition) {
                var dependsOn = parseInt(condition.depends_on_field_id, 10);
                var satisfied = visible[dependsOn] === true
                    && evaluate(condition.operator, fieldValue(dependsOn), String(condition.value));
                var action = condition.action || 'show';
                if (action === 'show' && !satisfied) { show = false; }
                if (action === 'hide' && satisfied) { show = false; }
            });

            visible[id] = show;
            wrap.hidden = !show;
            wrap.querySelectorAll('input, select, textarea').forEach(function (node) {
                node.disabled = !show;
            });
        });
    }

    var form = document.getElementById('forms-public-form');
    if (form) {
        form.addEventListener('change', applyVisibility);
        form.addEventListener('input', applyVisibility);
    }
    applyVisibility();
})();
</script>
@endpush
