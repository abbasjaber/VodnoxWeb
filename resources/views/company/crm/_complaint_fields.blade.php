@php($complaint = $complaint ?? null)
<div class="crm-fields">
    <div class="crm-field">
        <label for="crm-customer_id">{{ __('crm.fields.customer') }}</label>
        <select id="crm-customer_id" name="customer_id">
            <option value="">{{ __('crm.fields.none') }}</option>
            @foreach ($customers as $option)
                <option value="{{ $option->id }}" @selected((string) old('customer_id', $complaint?->customer_id) === (string) $option->id)>{{ $option->name_ar }}</option>
            @endforeach
        </select>
    </div>
    <div class="crm-field">
        <label for="crm-department_id">{{ __('crm.fields.department') }}</label>
        <select id="crm-department_id" name="department_id">
            <option value="">{{ __('crm.fields.none') }}</option>
            @foreach ($departments as $department)
                <option value="{{ $department->id }}" @selected((string) old('department_id', $complaint?->department_id) === (string) $department->id)>{{ $department->name_ar }}</option>
            @endforeach
        </select>
    </div>
    <div class="crm-field">
        <label for="crm-assigned_to">{{ __('crm.fields.assigned_to') }}</label>
        <select id="crm-assigned_to" name="assigned_to">
            <option value="">{{ __('crm.fields.unassigned') }}</option>
            @foreach ($users as $user)
                <option value="{{ $user->id }}" @selected((string) old('assigned_to', $complaint?->assigned_to) === (string) $user->id)>{{ $user->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="crm-field">
        <label for="crm-priority">{{ __('crm.fields.priority') }} *</label>
        <select id="crm-priority" name="priority" required>
            @foreach (['low', 'medium', 'high'] as $priority)
                <option value="{{ $priority }}" @selected(old('priority', $complaint?->priority ?? 'medium') === $priority)>{{ __('crm.priority.' . $priority) }}</option>
            @endforeach
        </select>
    </div>
    <div class="crm-field full">
        <label for="crm-subject">{{ __('crm.fields.subject') }} *</label>
        <input id="crm-subject" name="subject" type="text" maxlength="255" required value="{{ old('subject', $complaint?->subject) }}">
    </div>
    <div class="crm-field">
        <label for="crm-status">{{ __('crm.fields.status') }} *</label>
        <select id="crm-status" name="status" required>
            @foreach (['open', 'in_progress', 'resolved', 'closed'] as $status)
                <option value="{{ $status }}" @selected(old('status', $complaint?->status ?? 'open') === $status)>{{ __('crm.status.' . $status) }}</option>
            @endforeach
        </select>
    </div>
    <div class="crm-field full">
        <label for="crm-description">{{ __('crm.fields.description') }} *</label>
        <textarea id="crm-description" name="description" maxlength="5000" required>{{ old('description', $complaint?->description) }}</textarea>
    </div>
</div>
