@extends('layouts.company')

@section('title', __('forms.addon_name'))
@section('crumb', __('forms.addon_name'))

@section('content')
@include('company.forms._assets')

@if (session('status'))
    <span data-forms-flash hidden>{{ session('status') }}</span>
@endif
@if (session('error'))
    <span data-forms-flash hidden>{{ session('error') }}</span>
@endif

@php
    $loc = fn (?string $ar, ?string $en) => app()->getLocale() === 'ar' ? ($ar ?: $en ?: '') : ($en ?: $ar ?: '');
@endphp

@if (! $active)
    {{-- Add-on inactive: render the activation-request state ------------------ --}}
    <div class="forms-locked">
        <small class="crm-kicker">{{ __('forms.activation.kicker') }}</small>
        <div class="forms-icon" style="margin:18px auto;width:65px;height:65px">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 3 9 5-9 5-9-5 9-5Zm-9 9 9 5 9-5M3 16l9 5 9-5"/></svg>
        </div>
        <h1>{{ __('forms.activation.title') }}</h1>
        <span class="crm-pill {{ $pending ? 'amber' : 'red' }}">{{ $pending ? __('forms.activation.pending') : __('forms.addon_name') }}</span>
        <p>{{ __('forms.activation.text') }}</p>

        <form method="POST" action="{{ route('company.subscription.request') }}">
            @csrf
            <input type="hidden" name="feature" value="forms">
            <input type="hidden" name="notes" value="{{ __('forms.addon_name') }}">
            <button type="submit" class="crm-btn primary" {{ $pending ? 'disabled' : '' }}>
                {{ $pending ? __('forms.activation.pending') : __('forms.activation.request') }}
            </button>
        </form>
    </div>

    <div class="forms-feature-list">
        <div>
            <b>{{ __('forms.activation.feature_title') }}</b>
            <p>{{ __('forms.activation.feature_text') }}</p>
        </div>
        <div>
            <b>{{ __('forms.activation.public_title') }}</b>
            <p>{{ __('forms.activation.public_text') }}</p>
        </div>
        <div>
            <b>{{ __('forms.activation.export_title') }}</b>
            <p>{{ __('forms.activation.export_text') }}</p>
        </div>
    </div>

    <div class="forms-note">{{ __('forms.independent_addon') }}</div>
@else
    {{-- Active add-on: hero, metrics, search and the forms grid ---------------- --}}
    <div class="forms-hero">
        <div>
            <small>{{ __('forms.kicker') }}</small>
            <h1>{{ __('forms.heading') }}</h1>
            <p>{{ __('forms.subheading') }}</p>
        </div>
        <button type="button" class="crm-btn primary"
            data-forms-modal
            data-forms-template="forms-create-template"
            data-forms-title="{{ __('forms.start_from') }}"
            data-forms-action="{{ route('company.forms.store') }}">
            {{ __('forms.create') }}
        </button>
    </div>

    <div class="crm-metrics">
        <div class="crm-metric">
            <span>{{ __('forms.limit_title') }}</span>
            <strong>{{ $usage }}<small> / {{ $limit ?? '∞' }}</small></strong>
            @if ($limit)
                <div class="forms-meter"><i style="width:{{ min(100, (int) round($usage / max(1, $limit) * 100)) }}%"></i></div>
            @endif
        </div>
        <div class="crm-metric">
            <span>{{ __('forms.organization_forms') }}</span>
            <strong>{{ $forms->total() }}</strong>
        </div>
        <div class="crm-metric">
            <span>{{ __('forms.status_published') }}</span>
            <strong>{{ $publishedCount }}</strong>
        </div>
        <div class="crm-metric">
            <span>{{ __('forms.submission_count') }}</span>
            <strong>{{ $totalResponses }}</strong>
        </div>
    </div>

    <div class="forms-toolbar">
        <div>
            <h2 style="font-size:16px;color:var(--c-ink);margin:0">{{ __('forms.organization_forms') }}</h2>
        </div>
        <form method="GET" action="{{ route('company.forms.index') }}">
            <input class="forms-filter" type="search" name="q" value="{{ $search }}"
                placeholder="{{ __('forms.search_placeholder') }}" aria-label="{{ __('forms.search_placeholder') }}">
        </form>
    </div>

    @if ($forms->isEmpty())
        <div class="forms-empty">
            <h2>{{ $search !== '' ? __('forms.no_results') : __('forms.no_forms_title') }}</h2>
            <p>{{ __('forms.no_forms_text') }}</p>
            <button type="button" class="crm-btn primary"
                data-forms-modal
                data-forms-template="forms-create-template"
                data-forms-title="{{ __('forms.start_from') }}"
                data-forms-action="{{ route('company.forms.store') }}">
                {{ __('forms.create') }}
            </button>
        </div>
    @else
        <div class="forms-grid">
            @foreach ($forms as $form)
                <article class="forms-card">
                    <div class="forms-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 4h14v17H5V4Zm3-1h8v3H8V3Zm0 8h8m-8 4h8"/></svg>
                    </div>
                    <span class="crm-pill {{ $form->status === 'published' ? 'green' : 'amber' }}">
                        {{ $form->status === 'published' ? __('forms.status_published') : __('forms.status_draft') }}
                    </span>
                    <h2>{{ $loc($form->title_ar, $form->title_en) }}</h2>
                    <p>{{ \Illuminate\Support\Str::limit($loc($form->description_ar, $form->description_en), 130) }}</p>
                    <div class="forms-mini">
                        <span><b>{{ $form->fields_count }}</b>{{ __('forms.field_count') }}</span>
                        <span><b>{{ $form->submissions_count }}</b>{{ __('forms.submission_count') }}</span>
                    </div>
                    <div class="actions">
                        <a class="crm-btn primary" href="{{ route('company.forms.builder', $form) }}">{{ __('forms.open_builder') }}</a>
                        <a class="crm-btn" href="{{ route('company.forms.submissions.index', $form) }}">{{ __('forms.responses') }}</a>
                        <form method="POST" action="{{ route('company.forms.destroy', $form) }}" data-confirm="{{ __('forms.confirm_delete') }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="crm-btn forms-danger">{{ __('forms.delete') }}</button>
                        </form>
                    </div>
                </article>
            @endforeach
        </div>

        <div style="margin-top:24px">
            @include('company.forms._pagination', ['paginator' => $forms])
        </div>
    @endif

    {{-- Create-form template used by the shared project modal ------------------ --}}
    <template id="forms-create-template">
        <div class="crm-fields">
            <div class="crm-field">
                <label for="form-title-ar">{{ __('forms.builder.form_title_ar') }}</label>
                <input id="form-title-ar" type="text" name="title_ar" required maxlength="255" value="{{ old('title_ar') }}">
            </div>
            <div class="crm-field">
                <label for="form-title-en">{{ __('forms.builder.form_title_en') }}</label>
                <input id="form-title-en" type="text" name="title_en" maxlength="255" value="{{ old('title_en') }}">
            </div>
            <div class="crm-field full">
                <label for="form-description-ar">{{ __('forms.builder.description_ar') }}</label>
                <textarea id="form-description-ar" name="description_ar" maxlength="1500">{{ old('description_ar') }}</textarea>
            </div>
            <div class="crm-field full">
                <label for="form-description-en">{{ __('forms.builder.description_en') }}</label>
                <textarea id="form-description-en" name="description_en" maxlength="1500">{{ old('description_en') }}</textarea>
            </div>
        </div>
    </template>
@endif
@endsection
