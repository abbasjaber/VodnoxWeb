@php($customer = $customer ?? null)
<div class="crm-fields">
    <div class="crm-field">
        <label for="crm-name_ar">{{ __('crm.fields.name_ar') }} *</label>
        <input id="crm-name_ar" name="name_ar" type="text" maxlength="255" required value="{{ old('name_ar', $customer?->name_ar) }}">
    </div>
    <div class="crm-field">
        <label for="crm-name_en">{{ __('crm.fields.name_en') }}</label>
        <input id="crm-name_en" name="name_en" type="text" maxlength="255" value="{{ old('name_en', $customer?->name_en) }}">
    </div>
    <div class="crm-field">
        <label for="crm-company_name">{{ __('crm.fields.company_name') }}</label>
        <input id="crm-company_name" name="company_name" type="text" maxlength="255" value="{{ old('company_name', $customer?->company_name) }}">
    </div>
    <div class="crm-field">
        <label for="crm-phone">{{ __('crm.fields.phone') }}</label>
        <input id="crm-phone" name="phone" type="tel" maxlength="50" value="{{ old('phone', $customer?->phone) }}">
    </div>
    <div class="crm-field">
        <label for="crm-email">{{ __('crm.fields.email') }}</label>
        <input id="crm-email" name="email" type="email" maxlength="255" value="{{ old('email', $customer?->email) }}">
    </div>
    <div class="crm-field">
        <label for="crm-assigned_to">{{ __('crm.fields.assigned_to') }}</label>
        <select id="crm-assigned_to" name="assigned_to">
            <option value="">{{ __('crm.fields.unassigned') }}</option>
            @foreach ($users as $user)
                <option value="{{ $user->id }}" @selected((string) old('assigned_to', $customer?->assigned_to) === (string) $user->id)>{{ $user->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="crm-field">
        <label for="crm-status">{{ __('crm.fields.status') }} *</label>
        <select id="crm-status" name="status" required>
            <option value="active" @selected(old('status', $customer?->status ?? 'active') === 'active')>{{ __('crm.status.active') }}</option>
            <option value="inactive" @selected(old('status', $customer?->status) === 'inactive')>{{ __('crm.status.inactive') }}</option>
        </select>
    </div>
    <div class="crm-field full">
        <label for="crm-address">{{ __('crm.fields.address') }}</label>
        <input id="crm-address" name="address" type="text" maxlength="255" value="{{ old('address', $customer?->address) }}">
    </div>
    <div class="crm-field full">
        <label for="crm-notes">{{ __('crm.fields.notes') }}</label>
        <textarea id="crm-notes" name="notes" maxlength="5000">{{ old('notes', $customer?->notes) }}</textarea>
    </div>
</div>
