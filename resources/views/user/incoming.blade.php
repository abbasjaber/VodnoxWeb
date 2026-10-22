@extends('layouts.user')

@section('title', __('portal.agent.incoming_title'))
@section('crumb', __('portal.agent.incoming_title'))

@push('styles')
<link rel="stylesheet" href="{{ $assetv('assets/vodnox/incoming.css') }}">
<link rel="stylesheet" href="{{ $assetv('assets/vodnox/reception.css') }}">
@endpush

@section('content')
@include('user._workspace')

<div class="page-head">
    <div>
        <h1>{{ __('portal.agent.incoming_title') }}</h1>
        <p>{{ __('portal.agent.incoming_sub') }}</p>
    </div>
    <span class="agent-status"><i></i><span data-phone-state
            data-label-unconnected="{{ __('portal.agent.not_connected') }}"
            data-label-connecting="{{ __('portal.agent.telephony_connecting') }}"
            data-label-registered="{{ __('portal.agent.telephony_registered') }}"
            data-label-failed="{{ __('portal.agent.telephony_failed') }}">{{ __('portal.agent.not_connected') }}</span></span>
</div>

@include('user._call_workspace', ['storeRoute' => route('user.incoming.store')])
@endsection

@push('scripts')
<script src="{{ $assetv('assets/vodnox/incoming.js') }}"></script>
@endpush
