@extends('layouts.company')

@section('title', __('company.team_title'))
@section('crumb', __('company.team_title'))

@section('content')
@include('company._assets')
@include('company._alerts')

<div class="page-head">
    <div>
        <h1>{{ __('company.team_title') }}</h1>
        <p>{{ __('company.team_subtitle') }}</p>
    </div>
    <div class="head-actions">
        <button type="button" class="btn-primary"
                data-company-modal
                data-company-title="{{ __('company.add_member') }}"
                data-company-action="{{ route('company.team.store') }}"
                data-company-submit="{{ __('company.save') }}"
                data-company-template="tpl-member-create">{{ __('company.add_member') }}</button>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <div><h2>{{ __('company.all_members') }}</h2><p>{{ $members->total() }}</p></div>
    </div>

    <form class="filters" method="GET" action="{{ route('company.team.index') }}">
        <div class="search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.6-3.6"/></svg>
            <input type="search" name="q" value="{{ $q }}" placeholder="{{ __('company.search_member') }}" aria-label="{{ __('company.search') }}">
        </div>
        <select name="status" aria-label="{{ __('company.status') }}">
            <option value="">{{ __('company.all') }}</option>
            <option value="active" @selected($status === 'active')>{{ __('company.active') }}</option>
            <option value="inactive" @selected($status === 'inactive')>{{ __('company.inactive') }}</option>
        </select>
        <button type="submit" class="btn-primary">{{ __('company.search') }}</button>
        @if ($q !== '' || $status !== '')
            <a class="btn-secondary" href="{{ route('company.team.index') }}">{{ __('company.all') }}</a>
        @endif
    </form>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>{{ __('company.name') }}</th>
                    <th>{{ __('company.username') }}</th>
                    <th>{{ __('company.role') }}</th>
                    <th>{{ __('company.position') }}</th>
                    <th>{{ __('company.status') }}</th>
                    <th>{{ __('company.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($members as $member)
                    <tr>
                        <td>
                            <div class="company-cell">
                                <span class="company-avatar">{{ mb_substr($member->name, 0, 1) }}</span>
                                <div><strong>{{ $member->name }}</strong><small>{{ $member->email }}</small></div>
                            </div>
                        </td>
                        <td><span class="number">{{ $member->username }}</span></td>
                        <td>{{ $member->role ? __('company.role_'.$member->role->name) : '—' }}</td>
                        <td>{{ $member->position ?: '—' }}</td>
                        <td><span class="status {{ $member->active ? 'active' : 'inactive' }}">{{ $member->active ? __('company.active') : __('company.inactive') }}</span></td>
                        <td>
                            <div class="table-actions">
                                <button type="button" class="row-link"
                                        data-company-modal
                                        data-company-title="{{ __('company.edit_member') }}"
                                        data-company-action="{{ route('company.team.update', $member) }}"
                                        data-company-submit="{{ __('company.save') }}"
                                        data-company-template="tpl-member-edit-{{ $member->id }}">{{ __('company.edit') }}</button>
                                @if ($member->id !== auth()->id())
                                    <form method="POST" action="{{ route('company.team.destroy', $member) }}" data-confirm="{{ __('company.confirm_delete') }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="row-link">{{ __('company.delete') }}</button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="empty-row">{{ __('company.no_data') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="table-foot">
        <span>{{ __('company.showing') }} {{ $members->firstItem() ?? 0 }}–{{ $members->lastItem() ?? 0 }} / {{ $members->total() }}</span>
        @include('company._pagination', ['paginator' => $members])
    </div>
</div>

<template id="tpl-member-create">
    @csrf
    <div class="form-grid">
        <div class="form-field"><label for="mc-name">{{ __('company.name') }}</label><input id="mc-name" type="text" name="name" value="{{ old('name') }}" required></div>
        <div class="form-field"><label for="mc-username">{{ __('company.username') }}</label><input id="mc-username" type="text" name="username" value="{{ old('username') }}" required></div>
        <div class="form-field"><label for="mc-email">{{ __('company.email') }}</label><input id="mc-email" type="email" name="email" value="{{ old('email') }}" required></div>
        <div class="form-field"><label for="mc-role">{{ __('company.role') }}</label>
            <select id="mc-role" name="role_id" required>
                @foreach ($roles as $role)
                    <option value="{{ $role->id }}" @selected(old('role_id') == $role->id)>{{ __('company.role_'.$role->name) }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-field"><label for="mc-position">{{ __('company.position') }}</label><input id="mc-position" type="text" name="position" value="{{ old('position') }}"></div>
        <div class="form-field"><label for="mc-phone">{{ __('company.phone') }}</label><input id="mc-phone" type="text" name="phone" value="{{ old('phone') }}"></div>
        <div class="form-field"><label for="mc-extension">{{ __('company.extension') }}</label><input id="mc-extension" type="text" name="extension" value="{{ old('extension') }}"></div>
        <div class="form-field full"><label class="checkbox-line"><input type="checkbox" name="active" value="1" @checked(old('active', true))> {{ __('company.member_active') }}</label></div>
        <div class="form-field"><label for="mc-password">{{ __('company.password') }}</label><input id="mc-password" type="password" name="password" required></div>
        <div class="form-field"><label for="mc-password2">{{ __('company.password') }}</label><input id="mc-password2" type="password" name="password_confirmation" required></div>
    </div>
</template>

@foreach ($members as $member)
    <template id="tpl-member-edit-{{ $member->id }}">
        @csrf
        @method('PUT')
        <div class="form-grid">
            <div class="form-field"><label for="me{{ $member->id }}-name">{{ __('company.name') }}</label><input id="me{{ $member->id }}-name" type="text" name="name" value="{{ $member->name }}" required></div>
            <div class="form-field"><label for="me{{ $member->id }}-username">{{ __('company.username') }}</label><input id="me{{ $member->id }}-username" type="text" name="username" value="{{ $member->username }}" required></div>
            <div class="form-field"><label for="me{{ $member->id }}-email">{{ __('company.email') }}</label><input id="me{{ $member->id }}-email" type="email" name="email" value="{{ $member->email }}" required></div>
            <div class="form-field"><label for="me{{ $member->id }}-role">{{ __('company.role') }}</label>
                <select id="me{{ $member->id }}-role" name="role_id" required>
                    @foreach ($roles as $role)
                        <option value="{{ $role->id }}" @selected($member->role_id === $role->id)>{{ __('company.role_'.$role->name) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-field"><label for="me{{ $member->id }}-position">{{ __('company.position') }}</label><input id="me{{ $member->id }}-position" type="text" name="position" value="{{ $member->position }}"></div>
            <div class="form-field"><label for="me{{ $member->id }}-phone">{{ __('company.phone') }}</label><input id="me{{ $member->id }}-phone" type="text" name="phone" value="{{ $member->phone }}"></div>
            <div class="form-field"><label for="me{{ $member->id }}-extension">{{ __('company.extension') }}</label><input id="me{{ $member->id }}-extension" type="text" name="extension" value="{{ $member->extension }}"></div>
            <div class="form-field full"><label class="checkbox-line"><input type="checkbox" name="active" value="1" @checked($member->active)> {{ __('company.member_active') }}</label></div>
            <div class="form-field full"><small>{{ __('company.password_hint') }}</small></div>
            <div class="form-field"><label for="me{{ $member->id }}-password">{{ __('company.password') }}</label><input id="me{{ $member->id }}-password" type="password" name="password"></div>
            <div class="form-field"><label for="me{{ $member->id }}-password2">{{ __('company.password') }}</label><input id="me{{ $member->id }}-password2" type="password" name="password_confirmation"></div>
        </div>
    </template>
@endforeach
@endsection
