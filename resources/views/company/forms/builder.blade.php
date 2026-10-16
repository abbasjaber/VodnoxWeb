@extends('layouts.company')

@section('title', __('forms.builder.title'))
@section('crumb', __('forms.addon_name'))

@section('content')
@include('company.forms._assets')

@if (session('status'))
    <span data-forms-flash hidden>{{ session('status') }}</span>
@endif
@if ($errors->any())
    <span data-forms-flash hidden>{{ $errors->first() }}</span>
@endif

@php
    $loc = fn (?string $ar, ?string $en) => app()->getLocale() === 'ar' ? ($ar ?: $en ?: '') : ($en ?: $ar ?: '');
    $symbols = [
        'short_text' => 'Aa', 'long_text' => '¶', 'email' => '@', 'phone' => '☎', 'number' => '#',
        'select' => '▾', 'radio' => '◉', 'checkbox' => '☑', 'date' => '▦', 'rating' => '☆', 'section' => 'T',
    ];
    $dependencyFields = $fields->where('type', '!=', 'section');
@endphp

<div class="forms-toolbar">
    <div>
        <div class="crm-kicker">{{ __('forms.addon_name') }} / {{ __('forms.builder.title') }}</div>
        <h1>{{ $loc($form->title_ar, $form->title_en) }}</h1>
        <span class="crm-pill {{ $form->status === 'published' ? 'green' : 'amber' }}">
            {{ $form->status === 'published' ? __('forms.status_published') : __('forms.status_draft') }}
        </span>
        @if ($publishedVersion)
            <span class="forms-status-note">{{ __('forms.builder.version') }} {{ $publishedVersion->version }}</span>
        @endif
    </div>
    <div class="actions">
        <a class="crm-btn" href="{{ route('company.forms.index') }}">{{ __('forms.builder.all_forms') }}</a>
        <a class="crm-btn" href="{{ route('company.forms.submissions.index', $form) }}">{{ __('forms.responses') }}</a>
        @if ($form->status === 'published')
            <a class="crm-btn" href="{{ $publicUrl }}" target="_blank" rel="noopener">{{ __('forms.builder.open_public') }}</a>
        @endif
        <form method="POST" action="{{ route('company.forms.publish', $form) }}">
            @csrf
            <button type="submit" class="crm-btn primary">
                {{ $publishedVersion ? __('forms.builder.republish') : __('forms.builder.publish') }}
            </button>
        </form>
    </div>
</div>

<div class="crm-tabs" data-forms-tabs>
    <button type="button" data-forms-tab="build" aria-pressed="true">{{ __('forms.builder.title') }}</button>
    <button type="button" data-forms-tab="settings" aria-pressed="false">{{ __('forms.builder.settings') }}</button>
</div>

{{-- Build panel ------------------------------------------------------------- --}}
<div data-forms-panel="build">
    <div class="forms-builder">
        <aside class="forms-tools">
            <h2>{{ __('forms.builder.field_palette') }}</h2>
            <div class="forms-palette">
                @foreach ($types as $type)
                    <button type="button" data-forms-pick="{{ $type }}">
                        <span>{{ $symbols[$type] ?? '•' }}</span>{{ __('forms.type.'.$type) }}
                    </button>
                @endforeach
            </div>
        </aside>

        <div class="forms-canvas">
            <div class="forms-sheet-head">
                <small class="crm-kicker">{{ __('forms.addon_name') }}</small>
                <h2>{{ $loc($form->title_ar, $form->title_en) }}</h2>
                <p>{{ $loc($form->description_ar, $form->description_en) }}</p>
            </div>

            @forelse ($fields as $index => $field)
                <article class="forms-field-card">
                    <div class="forms-field-top">
                        <b>
                            {{ $index + 1 }}. {{ $loc($field->label_ar, $field->label_en) }}
                            @if ($field->is_required)<span class="forms-danger">*</span>@endif
                        </b>
                        <div class="forms-field-actions">
                            <form method="POST" action="{{ route('company.forms.fields.destroy', [$form, $field]) }}"
                                data-confirm="{{ __('forms.confirm_delete') }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" aria-label="{{ __('forms.delete') }}">&times;</button>
                            </form>
                        </div>
                    </div>

                    @if ($field->type === 'section')
                        <p class="forms-status-note">{{ $field->help_text_ar ?: $field->help_text_en ?: __('forms.builder.section_note') }}</p>
                    @elseif ($field->type === 'rating')
                        <div class="forms-rating-mock">{{ str_repeat('☆', (int) ($field->max_rating ?: 5)) }}</div>
                    @else
                        <div class="forms-mock">
                            @if (in_array($field->type, $choiceTypes, true))
                                {{ $field->options->map(fn ($option) => $loc($option->label_ar, $option->label_en))->implode(' · ') }}
                            @else
                                {{ $loc($field->placeholder_ar, $field->placeholder_en) ?: __('forms.type.'.$field->type) }}
                            @endif
                        </div>
                    @endif

                    @foreach ($field->conditions as $condition)
                        <div class="forms-conditional">
                            {{ __('forms.builder.condition_note') }}:
                            {{ $loc(optional($condition->dependsOnField)->label_ar, optional($condition->dependsOnField)->label_en) }}
                            {{ __('forms.operator.'.$condition->operator) }} {{ $condition->value }}
                        </div>
                    @endforeach
                </article>
            @empty
                <div class="forms-empty">{{ __('forms.builder.no_fields') }}</div>
            @endforelse
        </div>

        <aside class="forms-properties">
            <h2>{{ __('forms.builder.properties') }}</h2>
            <form method="POST" action="{{ route('company.forms.fields.store', $form) }}" data-forms-field-form>
                @csrf

                <div class="prop">
                    <label for="forms-field-type">{{ __('forms.builder.field_palette') }}</label>
                    <select id="forms-field-type" name="type">
                        @foreach ($types as $type)
                            <option value="{{ $type }}">{{ __('forms.type.'.$type) }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="prop">
                    <label for="ff-label-ar">{{ __('forms.builder.field_label_ar') }}</label>
                    <input id="ff-label-ar" type="text" name="label_ar" required maxlength="255">
                </div>

                <div class="prop">
                    <label for="ff-label-en">{{ __('forms.builder.field_label_en') }}</label>
                    <input id="ff-label-en" type="text" name="label_en" maxlength="255">
                </div>

                <div class="prop" data-types="!section">
                    <label class="checkbox-row">
                        <input type="checkbox" name="is_required" value="1"> {{ __('forms.builder.required') }}
                    </label>
                </div>

                <div class="prop" data-types="short_text long_text email phone">
                    <label for="ff-placeholder-ar">{{ __('forms.builder.placeholder') }}</label>
                    <input id="ff-placeholder-ar" type="text" name="placeholder_ar" maxlength="255">
                </div>

                <div class="prop" data-types="short_text long_text email phone">
                    <label for="ff-help-ar">{{ __('forms.builder.help_text') }}</label>
                    <textarea id="ff-help-ar" name="help_text_ar" maxlength="500"></textarea>
                </div>

                <div class="prop" data-types="short_text long_text email phone">
                    <label for="ff-min-length">{{ __('forms.builder.min_length') }}</label>
                    <input id="ff-min-length" type="number" name="min_length" min="0" max="10000">
                </div>

                <div class="prop" data-types="short_text long_text email phone">
                    <label for="ff-max-length">{{ __('forms.builder.max_length') }}</label>
                    <input id="ff-max-length" type="number" name="max_length" min="1" max="10000">
                </div>

                <div class="prop" data-types="number">
                    <label for="ff-min-value">{{ __('forms.builder.min_value') }}</label>
                    <input id="ff-min-value" type="number" name="min_value">
                </div>

                <div class="prop" data-types="number">
                    <label for="ff-max-value">{{ __('forms.builder.max_value') }}</label>
                    <input id="ff-max-value" type="number" name="max_value">
                </div>

                <div class="prop" data-types="rating">
                    <label for="ff-max-rating">{{ __('forms.builder.max_rating') }}</label>
                    <input id="ff-max-rating" type="number" name="max_rating" min="2" max="10" value="5">
                </div>

                <div class="prop" data-types="checkbox">
                    <label for="ff-max-selections">{{ __('forms.builder.max_selections') }}</label>
                    <input id="ff-max-selections" type="number" name="max_selections" min="1" max="30">
                </div>

                <div class="prop" data-types="select radio checkbox">
                    <label for="ff-options">{{ __('forms.builder.options') }}</label>
                    <textarea id="ff-options" data-forms-options maxlength="3000"></textarea>
                </div>

                <div class="prop" data-types="!section" data-forms-condition>
                    <label for="ff-condition-depends">{{ __('forms.builder.condition') }}</label>
                    <select id="ff-condition-depends" name="conditions[0][depends_on_field_id]" data-forms-condition-depends>
                        <option value="">{{ __('forms.builder.condition_always') }}</option>
                        @foreach ($dependencyFields as $dependency)
                            <option value="{{ $dependency->id }}">{{ $loc($dependency->label_ar, $dependency->label_en) }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="prop" data-types="!section" data-forms-condition>
                    <label for="ff-condition-operator">{{ __('forms.builder.operator') }}</label>
                    <select id="ff-condition-operator" name="conditions[0][operator]">
                        @foreach ($operators as $operator)
                            <option value="{{ $operator }}">{{ __('forms.operator.'.$operator) }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="prop" data-types="!section" data-forms-condition>
                    <label for="ff-condition-value">{{ __('forms.builder.condition_value') }}</label>
                    <input id="ff-condition-value" type="text" name="conditions[0][value]" maxlength="255">
                </div>

                <div class="prop" data-types="!section" data-forms-condition>
                    <label for="ff-condition-action">{{ __('forms.builder.action') }}</label>
                    <select id="ff-condition-action" name="conditions[0][action]">
                        @foreach ($actions as $action)
                            <option value="{{ $action }}">{{ __('forms.condition_action.'.$action) }}</option>
                        @endforeach
                    </select>
                </div>

                <button type="submit" class="crm-btn primary" style="width:100%">{{ __('forms.builder.save_field') }}</button>
            </form>
        </aside>
    </div>
</div>

{{-- Settings panel --------------------------------------------------------- --}}
<div data-forms-panel="settings" hidden>
    <section class="crm-panel" style="margin-bottom:20px">
        <div class="crm-panel-head"><h2>{{ __('forms.builder.settings') }}</h2></div>
        <div class="crm-body" style="padding:22px">
            <form method="POST" action="{{ route('company.forms.update', $form) }}">
                @csrf
                @method('PUT')
                <div class="crm-fields">
                    <div class="crm-field">
                        <label for="set-title-ar">{{ __('forms.builder.form_title_ar') }}</label>
                        <input id="set-title-ar" type="text" name="title_ar" required maxlength="255" value="{{ $form->title_ar }}">
                    </div>
                    <div class="crm-field">
                        <label for="set-title-en">{{ __('forms.builder.form_title_en') }}</label>
                        <input id="set-title-en" type="text" name="title_en" maxlength="255" value="{{ $form->title_en }}">
                    </div>
                    <div class="crm-field full">
                        <label for="set-description-ar">{{ __('forms.builder.description_ar') }}</label>
                        <textarea id="set-description-ar" name="description_ar" maxlength="1500">{{ $form->description_ar }}</textarea>
                    </div>
                    <div class="crm-field full">
                        <label for="set-description-en">{{ __('forms.builder.description_en') }}</label>
                        <textarea id="set-description-en" name="description_en" maxlength="1500">{{ $form->description_en }}</textarea>
                    </div>
                    <div class="crm-field full">
                        <label for="set-thanks-ar">{{ __('forms.builder.thank_you_ar') }}</label>
                        <textarea id="set-thanks-ar" name="thank_you_message_ar" maxlength="800">{{ $form->thank_you_message_ar }}</textarea>
                    </div>
                    <div class="crm-field full">
                        <label for="set-thanks-en">{{ __('forms.builder.thank_you_en') }}</label>
                        <textarea id="set-thanks-en" name="thank_you_message_en" maxlength="800">{{ $form->thank_you_message_en }}</textarea>
                    </div>
                    <div class="crm-field">
                        <label for="set-starts-at">{{ __('forms.builder.starts_at') }}</label>
                        <input id="set-starts-at" type="date" name="starts_at" value="{{ optional($form->starts_at)->format('Y-m-d') }}">
                    </div>
                    <div class="crm-field">
                        <label for="set-ends-at">{{ __('forms.builder.ends_at') }}</label>
                        <input id="set-ends-at" type="date" name="ends_at" value="{{ optional($form->ends_at)->format('Y-m-d') }}">
                    </div>
                    <div class="crm-field">
                        <label for="set-max-submissions">{{ __('forms.builder.max_submissions') }}</label>
                        <input id="set-max-submissions" type="number" name="max_submissions" min="1" max="1000000" value="{{ $form->max_submissions }}">
                    </div>
                </div>
                <div class="crm-admin-actions">
                    <button type="submit" class="crm-btn primary">{{ __('forms.builder.save_settings') }}</button>
                </div>
            </form>
        </div>
    </section>

    <section class="crm-panel">
        <div class="crm-panel-head"><h2>{{ __('forms.builder.public_link') }}</h2></div>
        <div class="crm-body" style="padding:22px">
            @if ($form->status === 'published')
                <div class="forms-link">{{ $publicUrl }}</div>
                <div class="crm-admin-actions">
                    <button type="button" class="crm-btn"
                        data-forms-copy="{{ $publicUrl }}"
                        data-forms-copied="{{ __('forms.builder.copied') }}">{{ __('forms.builder.copy_link') }}</button>
                    <a class="crm-btn primary" href="{{ $publicUrl }}" target="_blank" rel="noopener">{{ __('forms.builder.open_public') }}</a>
                </div>
            @else
                <div class="forms-note">{{ __('forms.builder.not_published_notice') }}</div>
            @endif
            <p class="forms-status-note">{{ __('forms.builder.publish_hint') }}</p>
        </div>
    </section>
</div>
@endsection
