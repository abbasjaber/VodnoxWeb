@extends('layouts.company')

@section('title', __('company.smart_ivr_title'))
@section('crumb', __('company.smart_ivr_title'))

@section('content')
@include('company._assets')
@include('company._alerts')

<div class="page-head">
    <div>
        <h1>{{ __('company.smart_ivr_title') }}</h1>
        <p>{{ __('company.smart_ivr_subtitle') }}</p>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <div><h2>{{ __('company.smart_ivr_coming_soon') }}</h2></div>
        <span class="status active">{{ __('company.feature_active') }}</span>
    </div>
    <p class="section-note">{{ __('company.smart_ivr_active_note') }}</p>
</div>
@endsection
