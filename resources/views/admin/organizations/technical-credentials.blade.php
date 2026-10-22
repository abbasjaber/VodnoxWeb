@extends('layouts.admin')

@section('title', __('admin.tech_credentials_title'))
@section('crumb', $organization->name_ar ?? $organization->name_en)

{{--
    The ONLY response that ever carries the clear technical password.

    It is returned directly by the POST (never redirected to, never flashed
    through the session), it is served no-store, and it offers copy buttons so
    the operator can hand the credentials over. Reloading this page cannot
    recover the password: a fresh GET shows the organization page instead.
--}}
@section('content')
<div class="account-header">
    <span class="company-avatar">{{ mb_substr($organization->name_ar ?? $organization->name_en, 0, 1) }}</span>
    <div>
        <h1>{{ __('admin.tech_credentials_title') }}</h1>
        <div class="account-meta">
            <span class="code-note">{{ $organization->code }}</span>
            <span>{{ $organization->name_ar ?? $organization->name_en }}</span>
        </div>
    </div>
    <div class="head-actions">
        <a class="btn-secondary" href="{{ route('admin.organizations.show', ['organization' => $organization, 'tab' => 'technical']) }}">{{ __('admin.back') }}</a>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <div>
            <h2>{{ $created ? __('admin.tech_created_title') : __('admin.tech_reset_title') }}</h2>
            <p>{{ __('admin.tech_once_note') }}</p>
        </div>
    </div>

    <div class="table-wrap">
        <table>
            <tbody>
                <tr>
                    <th>{{ __('admin.tech_username') }}</th>
                    <td class="code-note">
                        <span data-copy-value>{{ $account->username }}</span>
                        <button type="button" class="row-link" data-copy>{{ __('admin.copy') }}</button>
                    </td>
                </tr>
                <tr>
                    <th>{{ __('admin.tech_password') }}</th>
                    <td class="code-note">
                        <span data-copy-value>{{ $password }}</span>
                        <button type="button" class="row-link" data-copy>{{ __('admin.copy') }}</button>
                    </td>
                </tr>
                <tr>
                    <th>{{ __('admin.tech_signin_url') }}</th>
                    <td class="code-note">
                        <span data-copy-value>{{ route('company.gateways.index') }}</span>
                        <button type="button" class="row-link" data-copy>{{ __('admin.copy') }}</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="section-note" role="alert">{{ __('admin.tech_secrets_warning') }}</div>
    <p class="section-note">{{ __('admin.tech_independent_note') }}</p>
</div>
@endsection

@push('scripts')
<script>
(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        var copy = event.target.closest('[data-copy]');
        if (!copy) { return; }
        event.preventDefault();
        var holder = copy.closest('td');
        var source = holder ? holder.querySelector('[data-copy-value]') : null;
        var value = source ? source.textContent.trim() : '';
        if (value && navigator.clipboard) {
            navigator.clipboard.writeText(value).then(function () {
                copy.textContent = @json(__('admin.copied'));
                window.setTimeout(function () { copy.textContent = @json(__('admin.copy')); }, 1500);
            });
        }
    });
})();
</script>
@endpush
