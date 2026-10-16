@extends('layouts.company')

@section('title', __('forms.submissions.title'))
@section('crumb', __('forms.addon_name'))

@section('content')
@include('company.forms._assets')

@php
    $loc = fn (?string $ar, ?string $en) => app()->getLocale() === 'ar' ? ($ar ?: $en ?: '') : ($en ?: $ar ?: '');
    $shown = $fields->take(3);
@endphp

<div class="forms-toolbar">
    <div>
        <div class="crm-kicker">{{ __('forms.addon_name') }} / {{ __('forms.submissions.title') }}</div>
        <h1>{{ $loc($form->title_ar, $form->title_en) }}</h1>
        <span class="forms-status-note">{{ __('forms.submissions.received') }}: {{ $submissions->total() }}</span>
    </div>
    <div class="actions">
        <a class="crm-btn" href="{{ route('company.forms.builder', $form) }}">{{ __('forms.submissions.back') }}</a>
        <a class="crm-btn primary" href="{{ route('company.forms.submissions.export', $form) }}">{{ __('forms.submissions.export') }}</a>
    </div>
</div>

<form method="GET" action="{{ route('company.forms.submissions.index', $form) }}" class="forms-toolbar">
    <div class="actions">
        <label for="filter-from" class="forms-status-note">{{ __('forms.submissions.filter_from') }}</label>
        <input id="filter-from" class="forms-filter" type="date" name="from" value="{{ $from }}">
        <label for="filter-to" class="forms-status-note">{{ __('forms.submissions.filter_to') }}</label>
        <input id="filter-to" class="forms-filter" type="date" name="to" value="{{ $to }}">
    </div>
    <div class="actions">
        <button type="submit" class="crm-btn">{{ __('forms.submissions.filter') }}</button>
        <a class="crm-btn" href="{{ route('company.forms.submissions.index', $form) }}">{{ __('forms.submissions.reset') }}</a>
    </div>
</form>

<section class="crm-panel">
    <div class="forms-response-table">
        <table>
            <thead>
                <tr>
                    <th>{{ __('forms.submissions.id') }}</th>
                    <th>{{ __('forms.submissions.received') }}</th>
                    @foreach ($shown as $field)
                        <th>{{ $loc($field->label_ar, $field->label_en) }}</th>
                    @endforeach
                    <th>{{ __('forms.submissions.details') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($submissions as $submission)
                    <tr>
                        <td>#{{ $submission->getKey() }}</td>
                        <td>{{ $submission->created_at?->timezone('Asia/Baghdad')->format('Y-m-d H:i') }}</td>
                        @foreach ($shown as $field)
                            @php
                                $value = $submission->answers[$field->id] ?? '';
                                $value = is_array($value) ? implode(' | ', $value) : (string) $value;
                            @endphp
                            <td>{{ $value !== '' ? $value : __('forms.submissions.empty_answer') }}</td>
                        @endforeach
                        <td>
                            <a class="crm-link" href="{{ route('company.forms.submissions.show', [$form, $submission]) }}">{{ __('forms.submissions.view') }}</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ 3 + $shown->count() }}">{{ __('forms.submissions.no_submissions') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<div style="margin-top:24px">
    @include('company.forms._pagination', ['paginator' => $submissions])
</div>
@endsection
