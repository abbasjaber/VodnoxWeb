@extends('layouts.admin')

@section('title', __('admin.team_title'))
@section('crumb', __('admin.team_title'))

@section('content')
@include('admin.partials.flash')

<div class="page-head">
    <div>
        <h1>{{ __('admin.team_title') }}</h1>
        <p>{{ __('admin.team_subtitle') }}</p>
    </div>
    <div class="head-actions">
        <button type="button" class="btn-primary" data-admin-create
            data-admin-title="{{ __('admin.team_new') }}"
            data-admin-action="{{ route('admin.team.store') }}"
            data-admin-method="POST"
            data-admin-template="#tplTeamCreate">{{ __('admin.team_new') }}</button>
    </div>
</div>

<div class="card">
    <div class="card-head"><div><h2>{{ __('admin.team_title') }}</h2></div></div>

    <form class="filters" method="GET" action="{{ route('admin.team.index') }}">
        <label class="search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input type="search" name="q" value="{{ $q }}" placeholder="{{ __('admin.search_placeholder') }}">
        </label>
        <button type="submit" class="btn-secondary">{{ __('admin.search') }}</button>
        <a class="btn-quiet" href="{{ route('admin.team.index') }}">{{ __('admin.reset') }}</a>
    </form>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>{{ __('admin.name') }}</th>
                    <th>{{ __('admin.username') }}</th>
                    <th>{{ __('admin.email') }}</th>
                    <th>{{ __('admin.phone') }}</th>
                    <th>{{ __('admin.team_position') }}</th>
                    <th>{{ __('admin.role') }}</th>
                    <th>{{ __('admin.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($members as $member)
                    <tr>
                        <td>
                            <div class="company-cell">
                                <span class="company-avatar">{{ mb_substr($member->name ?? 'A', 0, 1) }}</span>
                                <div><strong>{{ $member->name }}</strong></div>
                            </div>
                        </td>
                        <td class="code-note">{{ $member->username }}</td>
                        <td>{{ $member->email ?: '—' }}</td>
                        <td>{{ $member->phone ?: '—' }}</td>
                        <td>{{ $member->position ?: '—' }}</td>
                        <td>{{ $member->role?->label_ar ?? $member->role?->name ?? '—' }}</td>
                        <td>
                            <div class="detail-actions" style="padding:0">
                                <button type="button" class="row-link" data-admin-edit
                                    data-admin-title="{{ __('admin.edit') }}"
                                    data-admin-action="{{ route('admin.team.update', $member) }}"
                                    data-admin-method="PUT"
                                    data-admin-template="#tplTeamEdit"
                                    data-admin-values="{{ json_encode([
                                        'name' => $member->name,
                                        'username' => $member->username,
                                        'email' => $member->email,
                                        'phone' => $member->phone,
                                        'position' => $member->position,
                                        'password' => '',
                                    ]) }}">{{ __('admin.edit') }}</button>
                                @if (auth()->id() !== $member->id)
                                    <form method="POST" action="{{ route('admin.team.destroy', $member) }}" data-admin-confirm="{{ __('admin.confirm_delete') }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="row-link" style="color:#c55b61">{{ __('admin.delete') }}</button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="empty-row">{{ __('admin.no_data') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="table-foot">
        <span>{{ $members->total() }}</span>
        <span>{{ $members->links('pagination::bootstrap-5') }}</span>
    </div>
</div>

@include('admin.partials.dialog')

<template id="tplTeamCreate">
    <div class="form-grid">
        <div class="form-field"><label>{{ __('admin.name') }} <span class="required">*</span></label><input type="text" name="name" required></div>
        <div class="form-field"><label>{{ __('admin.username') }} <span class="required">*</span></label><input type="text" name="username" required></div>
        <div class="form-field"><label>{{ __('admin.email') }}</label><input type="email" name="email"></div>
        <div class="form-field"><label>{{ __('admin.phone') }}</label><input type="text" name="phone"></div>
        <div class="form-field"><label>{{ __('admin.team_position') }}</label><input type="text" name="position"></div>
        <div class="form-field"><label>{{ __('admin.password') }} <span class="required">*</span></label><input type="password" name="password" autocomplete="new-password" required></div>
    </div>
</template>

<template id="tplTeamEdit">
    <div class="form-grid">
        <div class="form-field"><label>{{ __('admin.name') }} <span class="required">*</span></label><input type="text" name="name" required></div>
        <div class="form-field"><label>{{ __('admin.username') }} <span class="required">*</span></label><input type="text" name="username" required></div>
        <div class="form-field"><label>{{ __('admin.email') }}</label><input type="email" name="email"></div>
        <div class="form-field"><label>{{ __('admin.phone') }}</label><input type="text" name="phone"></div>
        <div class="form-field"><label>{{ __('admin.team_position') }}</label><input type="text" name="position"></div>
        <div class="form-field"><label>{{ __('admin.password') }}</label><input type="password" name="password" autocomplete="new-password" placeholder="{{ __('admin.team_password_hint') }}"></div>
    </div>
</template>

@push('scripts')
@include('admin.partials.scripts')
@endpush
@endsection
