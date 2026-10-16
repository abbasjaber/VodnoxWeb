@extends('layouts.user')

@section('title', __('portal.agent.contacts_title'))
@section('crumb', __('portal.agent.contacts_title'))

@section('content')
@include('user._workspace')

<div class="page-head">
    <div>
        <h1>{{ __('portal.agent.contacts_title') }}</h1>
        <p>{{ __('portal.agent.contacts_sub') }}</p>
    </div>
</div>

<div class="contacts-filters">
    <form class="search" method="GET" action="{{ route('user.contacts.index') }}" role="search">
        <input type="search" name="q" value="{{ $term }}" placeholder="{{ __('portal.agent.search_placeholder') }}"
               aria-label="{{ __('portal.agent.search_placeholder') }}">
        <button class="btn-secondary" type="submit">{{ __('portal.agent.search') }}</button>
    </form>
</div>

<section class="card">
    <div class="card-head">
        <div>
            <h2>{{ __('portal.agent.add_favorite') }}</h2>
            <p>{{ __('portal.agent.or_free_entry') }}</p>
        </div>
    </div>
    <form method="POST" action="{{ route('user.contacts.store') }}" class="form-grid">
        @csrf
        <div class="form-field">
            <label for="contact_id">{{ __('portal.agent.select_contact') }}</label>
            <select id="contact_id" name="contact_id">
                <option value="">{{ __('portal.agent.none') }}</option>
                @foreach ($directory as $employee)
                    <option value="{{ $employee->id }}">
                        {{ $employee->name }}@if ($employee->extension) &middot; {{ __('portal.agent.extension') }} {{ $employee->extension }}@endif
                    </option>
                @endforeach
            </select>
        </div>
        <div class="form-field">
            <label for="favorite_name">{{ __('portal.agent.name') }}</label>
            <input id="favorite_name" type="text" name="name" maxlength="255" value="{{ old('name') }}">
        </div>
        <div class="form-field">
            <label for="favorite_phone">{{ __('portal.agent.phone') }}</label>
            <input id="favorite_phone" type="text" name="phone_number" maxlength="32" value="{{ old('phone_number') }}">
        </div>
        <div>
            <button class="btn-primary" type="submit">{{ __('portal.agent.save_favorite') }}</button>
        </div>
    </form>
</section>

<section class="card">
    <div class="card-head">
        <div>
            <h2>{{ __('portal.agent.my_favorites') }}</h2>
            <p>{{ __('portal.agent.my_favorites_sub') }}</p>
        </div>
    </div>
    <div class="favorites-row">
        @forelse ($favorites as $favorite)
            <div class="favorite">
                <span class="contact-avatar">{{ mb_substr($favorite->name, 0, 2) }}</span>
                <strong>{{ $favorite->name }}</strong>
                <small>{{ $favorite->phone_number ?: '-' }}</small>
                <form method="POST" action="{{ route('user.contacts.destroy', $favorite) }}"
                      data-confirm="{{ __('portal.agent.remove') }}؟">
                    @csrf
                    @method('DELETE')
                    <button class="text-btn" type="submit">{{ __('portal.agent.remove') }}</button>
                </form>
            </div>
        @empty
            <p class="help-note">{{ __('portal.agent.no_favorites') }}</p>
        @endforelse
    </div>
</section>

<div class="page-head">
    <div>
        <h2>{{ __('portal.agent.directory') }}</h2>
        <p>{{ __('portal.agent.directory_sub') }}</p>
    </div>
</div>

<div class="contact-grid">
    @forelse ($directory as $employee)
        <section class="contact-card">
            <span class="contact-avatar">{{ mb_substr($employee->name, 0, 2) }}</span>
            <h2>{{ $employee->name }}</h2>
            <p>{{ $employee->position ?: $employee->username }}</p>
            <span class="extension-chip">
                <span>{{ __('portal.agent.extension') }}</span>
                <b>{{ $employee->extension ?: '-' }}</b>
            </span>
            <div class="call-status-label">{{ __('portal.agent.device_unverified') }}</div>
            <div class="contact-actions">
                <form method="POST" action="{{ route('user.contacts.store') }}">
                    @csrf
                    <input type="hidden" name="contact_id" value="{{ $employee->id }}">
                    <button class="btn-secondary" type="submit">{{ __('portal.agent.add_favorite') }}</button>
                </form>
            </div>
        </section>
    @empty
        <section class="card">
            <div class="empty">
                <h3>{{ __('portal.agent.no_results') }}</h3>
            </div>
        </section>
    @endforelse
</div>
@endsection
