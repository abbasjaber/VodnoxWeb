@extends('layouts.company')

@section('title', __('telephony_technical.title'))
@section('crumb', __('telephony_technical.title'))

{{--
    The entry of the "Telephony Technical Settings" section.

    The organization administrator may reach this page, but it shows the
    technical sign-in screen ONLY: no device, port, SIP account or setup value
    is loaded into this response, and no placeholder holds one either (a hidden
    field would still be readable by anyone who can open the page).

    The sign-in below posts to a real Laravel guard (`technical`); the server
    re-checks it on every protected request.
--}}
@section('content')
@include('company._assets')
@include('company._alerts')

<div class="page-head">
    <div>
        <h1>{{ __('telephony_technical.title') }}</h1>
        <p>{{ __('telephony_technical.subtitle') }}</p>
    </div>
</div>

<div class="card" style="max-width:640px">
    <div class="card-head">
        <div>
            <h2>{{ __('telephony_technical.gate_heading') }}</h2>
            <p>{{ __('telephony_technical.gate_intro') }}</p>
        </div>
    </div>

    <div class="table-wrap">
        <table>
            <tbody>
                <tr>
                    <th>{{ __('telephony_technical.gate_org') }}</th>
                    <td>{{ $org->name_ar ?? $org->name_en }} <span class="code-note">({{ $org->code }})</span></td>
                </tr>
            </tbody>
        </table>
    </div>

    @if (! $hasAccount)
        <div class="section-note" role="alert">{{ __('telephony_technical.gate_no_account') }}</div>
    @else
        <form method="POST" action="{{ route('company.telephony-technical.login') }}">
            @csrf
            <div class="form-grid">
                <div class="form-field">
                    <label for="tech-username">{{ __('telephony_technical.gate_username') }}</label>
                    <input id="tech-username" type="text" name="username" value="{{ old('username') }}"
                           autocomplete="off" autocapitalize="none" spellcheck="false" required>
                    @error('username')<small class="section-note" role="alert">{{ $message }}</small>@enderror
                </div>
                <div class="form-field">
                    <label for="tech-password">{{ __('telephony_technical.gate_password') }}</label>
                    <input id="tech-password" type="password" name="password" value=""
                           autocomplete="current-password" required>
                    @error('password')<small class="section-note" role="alert">{{ $message }}</small>@enderror
                </div>
            </div>
            <div class="head-actions">
                <button type="submit" class="btn-primary">{{ __('telephony_technical.gate_submit') }}</button>
            </div>
        </form>
    @endif

    <p class="section-note">{{ __('telephony_technical.gate_separation') }}</p>
    <p class="section-note">{{ __('telephony_technical.gate_no_ui_protection') }}</p>
</div>
@endsection
