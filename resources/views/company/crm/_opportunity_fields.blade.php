@php($opportunity = $opportunity ?? null)
<div class="crm-fields">
    <div class="crm-field">
        <label for="crm-customer_id">{{ __('crm.fields.customer') }} *</label>
        <select id="crm-customer_id" name="customer_id" required>
            <option value="">{{ __('crm.fields.none') }}</option>
            @foreach ($customers as $option)
                <option value="{{ $option->id }}" @selected((string) old('customer_id', $opportunity?->customer_id) === (string) $option->id)>{{ $option->name_ar }}</option>
            @endforeach
        </select>
    </div>
    <div class="crm-field">
        <label for="crm-title">{{ __('crm.fields.title') }} *</label>
        <input id="crm-title" name="title" type="text" maxlength="255" required value="{{ old('title', $opportunity?->title) }}">
    </div>
    <div class="crm-field">
        <label for="crm-value">{{ __('crm.fields.value') }}</label>
        <input id="crm-value" name="value" type="number" min="0" step="0.01" value="{{ old('value', $opportunity?->value) }}">
    </div>
    <div class="crm-field">
        <label for="crm-currency">{{ __('crm.fields.currency') }} *</label>
        <select id="crm-currency" name="currency" required>
            <option value="IQD" @selected(old('currency', $opportunity?->currency ?? 'IQD') === 'IQD')>IQD</option>
            <option value="USD" @selected(old('currency', $opportunity?->currency) === 'USD')>USD</option>
        </select>
    </div>
    <div class="crm-field">
        <label for="crm-stage_id">{{ __('crm.fields.stage') }}</label>
        <select id="crm-stage_id" name="stage_id">
            <option value="">{{ __('crm.fields.none') }}</option>
            @foreach ($stages as $stage)
                <option value="{{ $stage->id }}" @selected((string) old('stage_id', $opportunity?->stage_id) === (string) $stage->id)>{{ $stage->name_ar }}</option>
            @endforeach
        </select>
    </div>
    <div class="crm-field">
        <label for="crm-expected_close_date">{{ __('crm.fields.expected_close_date') }}</label>
        <input id="crm-expected_close_date" name="expected_close_date" type="date" value="{{ old('expected_close_date', optional($opportunity?->expected_close_date)->format('Y-m-d')) }}">
    </div>
    <div class="crm-field">
        <label for="crm-status">{{ __('crm.fields.status') }} *</label>
        <select id="crm-status" name="status" required>
            @foreach (['open', 'won', 'lost'] as $status)
                <option value="{{ $status }}" @selected(old('status', $opportunity?->status ?? 'open') === $status)>{{ __('crm.status.' . $status) }}</option>
            @endforeach
        </select>
    </div>
    <div class="crm-field">
        <label for="crm-assigned_to">{{ __('crm.fields.assigned_to') }}</label>
        <select id="crm-assigned_to" name="assigned_to">
            <option value="">{{ __('crm.fields.unassigned') }}</option>
            @foreach ($users as $user)
                <option value="{{ $user->id }}" @selected((string) old('assigned_to', $opportunity?->assigned_to) === (string) $user->id)>{{ $user->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="crm-field full">
        <label for="crm-description">{{ __('crm.fields.description') }}</label>
        <textarea id="crm-description" name="description" maxlength="5000">{{ old('description', $opportunity?->description) }}</textarea>
    </div>
</div>
