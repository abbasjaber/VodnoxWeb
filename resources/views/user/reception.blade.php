@extends('layouts.user')

@section('title', __('portal.agent.reception_title'))
@section('crumb', __('portal.agent.reception_title'))

@push('styles')
<link rel="stylesheet" href="{{ asset('assets/vodnox/reception.css') }}">
@endpush

@section('content')
@include('user._workspace')

<div class="page-head">
    <div>
        <h1>{{ __('portal.agent.reception_title') }}</h1>
        <p>{{ __('portal.agent.reception_sub') }}</p>
    </div>
    <span class="rx-pill" data-phone-state
          data-label-unconnected="{{ __('portal.agent.not_connected') }}"
          data-label-connecting="{{ __('portal.agent.telephony_connecting') }}"
          data-label-registered="{{ __('portal.agent.telephony_registered') }}"
          data-label-failed="{{ __('portal.agent.telephony_failed') }}">{{ __('portal.agent.not_connected') }}</span>
</div>

@include('user._call_workspace', ['storeRoute' => route('user.reception.store')])
@endsection

@push('scripts')
<script src="{{ asset('assets/vodnox/reception.js') }}"></script>
@endpush
