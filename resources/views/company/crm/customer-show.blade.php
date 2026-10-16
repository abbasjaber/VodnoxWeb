@extends('layouts.company')

@section('title', __('crm.title.customer_profile'))
@section('crumb', __('crm.title.customer_profile'))

@section('content')
@include('company.crm._assets')
@include('company.crm._alerts')
@include('company.crm._nav')

<div class="crm-head">
    <div>
        <div class="crm-kicker">VODNOX / CUSTOMER EXPERIENCE</div>
        <h1>{{ $customer->name_ar }}</h1>
        <p>{{ $customer->company_name ?? $customer->name_en ?? __('crm.title.customer_profile') }}</p>
    </div>
    <div class="crm-admin-actions">
        <a class="crm-btn" href="{{ route('company.crm.customers.index') }}">{{ __('crm.actions.back') }}</a>
        <button type="button" class="crm-btn primary" data-crm-modal
                data-crm-title="{{ __('crm.actions.edit') }}"
                data-crm-action="{{ route('company.crm.customers.update', $customer) }}"
                data-crm-template="tpl-customer-edit">{{ __('crm.actions.edit') }}</button>
    </div>
</div>

<section class="crm-panel crm-profile">
    <div class="crm-panel-head">
        <h2>{{ __('crm.title.customer_profile') }}</h2>
        @if ($customer->status === 'active')
            <span class="crm-pill green">{{ __('crm.status.active') }}</span>
        @else
            <span class="crm-pill">{{ __('crm.status.inactive') }}</span>
        @endif
    </div>
    <div class="crm-body">
        <div class="crm-profile-top">
            <span class="crm-initial">{{ mb_substr($customer->name_ar, 0, 1) }}</span>
            <div>
                <h3>{{ $customer->name_ar }}</h3>
                <small>{{ $customer->name_en ?? '-' }} · {{ $customer->company_name ?? '-' }}</small>
            </div>
        </div>

        <dl class="crm-details">
            <div><dt>{{ __('crm.fields.phone') }}</dt><dd>{{ $customer->phone ?? '-' }}</dd></div>
            <div><dt>{{ __('crm.fields.email') }}</dt><dd>{{ $customer->email ?? '-' }}</dd></div>
            <div><dt>{{ __('crm.fields.address') }}</dt><dd>{{ $customer->address ?? '-' }}</dd></div>
            <div><dt>{{ __('crm.fields.assigned_to') }}</dt><dd>{{ $customer->assignedTo?->name ?? __('crm.fields.unassigned') }}</dd></div>
            <div><dt>{{ __('crm.fields.created_at') }}</dt><dd>{{ $customer->created_at?->format('Y-m-d') }}</dd></div>
        </dl>

        @if ($customer->notes)
            <div class="crm-timeline">
                <div class="crm-event">
                    <b>{{ __('crm.fields.notes') }}</b>
                    <p>{{ $customer->notes }}</p>
                </div>
            </div>
        @endif
    </div>
</section>

<div class="crm-tabs" data-crm-tabgroup>
    <button type="button" data-crm-tab="notes" aria-pressed="true">{{ __('crm.fields.notes') }}</button>
    <button type="button" data-crm-tab="interactions" aria-pressed="false">{{ __('crm.type.note') }}</button>
    <button type="button" data-crm-tab="contacts" aria-pressed="false">{{ __('crm.fields.contact') }}</button>
    <button type="button" data-crm-tab="opportunities" aria-pressed="false">{{ __('crm.title.opportunities') }}</button>
    <button type="button" data-crm-tab="tasks" aria-pressed="false">{{ __('crm.title.tasks') }}</button>
</div>

<section class="crm-panel" data-crm-panel="notes">
    <div class="crm-panel-head">
        <h2>{{ __('crm.fields.notes') }}</h2>
        <button type="button" class="crm-btn primary" data-crm-modal
                data-crm-title="{{ __('crm.actions.add_note') }}"
                data-crm-action="{{ route('company.crm.customers.notes.store', $customer) }}"
                data-crm-template="tpl-note">{{ __('crm.actions.add_note') }}</button>
    </div>
    <div class="crm-body">
        @if ($customer->customerNotes->isEmpty())
            <div class="crm-empty">{{ __('crm.empty.notes') }}</div>
        @else
            <div class="crm-timeline">
                @foreach ($customer->customerNotes as $note)
                    <div class="crm-event">
                        <time>{{ $note->created_at?->format('Y-m-d H:i') }}</time>
                        <b>{{ $note->user?->name ?? __('crm.fields.unassigned') }}</b>
                        <p>{{ $note->content }}</p>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</section>

<section class="crm-panel" data-crm-panel="interactions" hidden>
    <div class="crm-panel-head">
        <h2>{{ __('crm.kpi.recent_interactions') }}</h2>
        <button type="button" class="crm-btn primary" data-crm-modal
                data-crm-title="{{ __('crm.actions.add_interaction') }}"
                data-crm-action="{{ route('company.crm.customers.interactions.store', $customer) }}"
                data-crm-template="tpl-interaction">{{ __('crm.actions.add_interaction') }}</button>
    </div>
    <div class="crm-body">
        @if ($customer->interactions->isEmpty())
            <div class="crm-empty">{{ __('crm.empty.interactions') }}</div>
        @else
            <div class="crm-timeline">
                @foreach ($customer->interactions as $interaction)
                    <div class="crm-event">
                        <time>{{ optional($interaction->occurred_at)->format('Y-m-d H:i') }}</time>
                        <b>{{ __('crm.type.' . $interaction->type) }}</b>
                        <p>{{ $interaction->user?->name ?? __('crm.fields.unassigned') }}</p>
                        @if ($interaction->content)
                            <p>{{ $interaction->content }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</section>

<section class="crm-panel" data-crm-panel="contacts" hidden>
    <div class="crm-panel-head">
        <h2>{{ __('crm.fields.contact') }}</h2>
    </div>
    @if ($customer->contacts->isEmpty())
        <div class="crm-body"><div class="crm-empty">{{ __('crm.empty.contacts') }}</div></div>
    @else
        <div class="crm-table">
            <table>
                <thead>
                    <tr>
                        <th>{{ __('crm.fields.contact') }}</th>
                        <th>{{ __('crm.fields.role') }}</th>
                        <th>{{ __('crm.fields.email') }}</th>
                        <th>{{ __('crm.fields.phone') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($customer->contacts as $contact)
                        <tr>
                            <td>
                                {{ $contact->name }}
                                @if ($contact->is_primary)
                                    <span class="crm-pill green">{{ __('crm.fields.is_primary') }}</span>
                                @endif
                            </td>
                            <td>{{ $contact->role ?? '-' }}</td>
                            <td>{{ $contact->email ?? '-' }}</td>
                            <td>{{ $contact->phone ?? '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

<section class="crm-panel" data-crm-panel="opportunities" hidden>
    <div class="crm-panel-head">
        <h2>{{ __('crm.title.opportunities') }}</h2>
    </div>
    <div class="crm-body">
        @if ($customer->opportunities->isEmpty())
            <div class="crm-empty">{{ __('crm.empty.opportunities') }}</div>
        @else
            <div class="crm-rows">
                @foreach ($customer->opportunities as $opportunity)
                    <article class="crm-item">
                        <header>
                            <small>{{ $opportunity->stage?->name_ar ?? __('crm.reports.unassigned_stage') }}</small>
                            <span class="crm-pill {{ $opportunity->status === 'open' ? 'amber' : ($opportunity->status === 'won' ? 'green' : 'red') }}">{{ __('crm.status.' . $opportunity->status) }}</span>
                        </header>
                        <h3>{{ $opportunity->title }}</h3>
                        <p>{{ number_format((float) $opportunity->value, 2) }} {{ $opportunity->currency }} · {{ $opportunity->assignedTo?->name ?? __('crm.fields.unassigned') }}</p>
                    </article>
                @endforeach
            </div>
        @endif
    </div>
</section>

<section class="crm-panel" data-crm-panel="tasks" hidden>
    <div class="crm-panel-head">
        <h2>{{ __('crm.title.tasks') }}</h2>
    </div>
    <div class="crm-body">
        @if ($customer->tasks->isEmpty())
            <div class="crm-empty">{{ __('crm.empty.tasks') }}</div>
        @else
            <div class="crm-rows">
                @foreach ($customer->tasks as $task)
                    <article class="crm-item">
                        <header>
                            <small>{{ __('crm.priority.' . $task->priority) }}</small>
                            <span class="crm-pill {{ $task->status === 'completed' ? 'green' : ($task->status === 'cancelled' ? 'red' : 'amber') }}">{{ __('crm.status.' . $task->status) }}</span>
                        </header>
                        <h3>{{ $task->title }}</h3>
                        <p>{{ $task->due_at?->format('Y-m-d H:i') ?? __('crm.fields.none') }} · {{ $task->assignedTo?->name ?? __('crm.fields.unassigned') }}</p>
                    </article>
                @endforeach
            </div>
        @endif
    </div>
</section>

<template id="tpl-customer-edit">
    @csrf
    @method('PUT')
    @include('company.crm._customer_fields', ['customer' => $customer])
</template>

<template id="tpl-note">
    @csrf
    <div class="crm-fields">
        <div class="crm-field full">
            <label for="crm-content">{{ __('crm.fields.content') }} *</label>
            <textarea id="crm-content" name="content" maxlength="5000" required></textarea>
        </div>
    </div>
</template>

<template id="tpl-interaction">
    @csrf
    <div class="crm-fields">
        <div class="crm-field">
            <label for="crm-type">{{ __('crm.fields.type') }} *</label>
            <select id="crm-type" name="type" required>
                @foreach (['call', 'email', 'sms', 'meeting', 'note'] as $type)
                    <option value="{{ $type }}">{{ __('crm.type.' . $type) }}</option>
                @endforeach
            </select>
        </div>
        <div class="crm-field">
            <label for="crm-occurred_at">{{ __('crm.fields.occurred_at') }}</label>
            <input id="crm-occurred_at" name="occurred_at" type="datetime-local">
        </div>
        <div class="crm-field full">
            <label for="crm-content">{{ __('crm.fields.content') }}</label>
            <textarea id="crm-content" name="content" maxlength="5000"></textarea>
        </div>
    </div>
</template>
@endsection
