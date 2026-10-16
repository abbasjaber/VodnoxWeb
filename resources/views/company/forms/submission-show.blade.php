@extends('layouts.company')

@section('title', __('forms.submissions.title'))
@section('crumb', __('forms.addon_name'))

@section('content')
@include('company.forms._assets')

@php
    $loc = fn (?string $ar, ?string $en) => app()->getLocale() === 'ar' ? ($ar ?: $en ?: '') : ($en ?: $ar ?: '');
    $answers = $submission->answers ?? [];

    $optionLabel = function (array $field, string $value) use ($loc) {
        foreach ($field['options'] ?? [] as $option) {
            if ((string) ($option['value'] ?? '') === $value) {
                return $loc($option['label_ar'] ?? null, $option['label_en'] ?? null) ?: $value;
            }
        }
        return $value;
    };
@endphp

<div class="forms-toolbar">
    <div>
        <div class="crm-kicker">{{ __('forms.addon_name') }} / {{ __('forms.submissions.title') }}</div>
        <h1>#{{ $submission->getKey() }}</h1>
        <span class="forms-status-note">{{ __('forms.submissions.received') }}: {{ $submission->created_at?->timezone('Asia/Baghdad')->format('Y-m-d H:i') }}</span>
    </div>
    <div class="actions">
        <a class="crm-btn" href="{{ route('company.forms.submissions.index', $form) }}">{{ __('forms.submissions.back') }}</a>
        <a class="crm-btn" href="{{ route('company.forms.builder', $form) }}">{{ __('forms.open_builder') }}</a>
    </div>
</div>

<section class="crm-panel" style="margin-bottom:20px">
    <div class="crm-panel-head"><h2>{{ __('forms.submissions.answers') }}</h2></div>
    <div class="crm-body" style="padding:22px">
        @foreach ($schemaFields as $field)
            @continue(($field['type'] ?? '') === 'section')
            @php
                $id = $field['id'];
                $raw = $answers[$id] ?? ($answers[(string) $id] ?? null);
                if (is_array($raw)) {
                    $display = implode(' | ', array_map(fn ($item) => $optionLabel($field, (string) $item), $raw));
                } elseif ($raw === null || $raw === '') {
                    $display = __('forms.submissions.empty_answer');
                } elseif (in_array($field['type'] ?? '', ['select', 'radio', 'checkbox'], true)) {
                    $display = $optionLabel($field, (string) $raw);
                } else {
                    $display = (string) $raw;
                }
            @endphp
            <div class="forms-response">
                <small>{{ $loc($field['label_ar'] ?? null, $field['label_en'] ?? null) }}</small>
                <p>{{ $display }}</p>
            </div>
        @endforeach
    </div>
</section>

<section class="crm-panel">
    <div class="crm-panel-head"><h2>{{ __('forms.submissions.meta') }}</h2></div>
    <div class="crm-body" style="padding:22px">
        <div class="forms-response">
            <small>{{ __('forms.submissions.ip') }}</small>
            <p>{{ $submission->ip_address ?: __('forms.submissions.empty_answer') }}</p>
        </div>
        <div class="forms-response">
            <small>{{ __('forms.submissions.agent') }}</small>
            <p>{{ $submission->user_agent ?: __('forms.submissions.empty_answer') }}</p>
        </div>
        <div class="forms-response">
            <small>{{ __('forms.submissions.version') }}</small>
            <p>{{ $submission->version?->version ?? __('forms.submissions.empty_answer') }}</p>
        </div>
    </div>
</section>
@endsection
