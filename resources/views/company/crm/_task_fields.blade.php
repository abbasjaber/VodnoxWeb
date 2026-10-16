@php($task = $task ?? null)
<div class="crm-fields">
    <div class="crm-field">
        <label for="crm-customer_id">{{ __('crm.fields.customer') }}</label>
        <select id="crm-customer_id" name="customer_id">
            <option value="">{{ __('crm.fields.none') }}</option>
            @foreach ($customers as $option)
                <option value="{{ $option->id }}" @selected((string) old('customer_id', $task?->customer_id) === (string) $option->id)>{{ $option->name_ar }}</option>
            @endforeach
        </select>
    </div>
    <div class="crm-field">
        <label for="crm-assigned_to">{{ __('crm.fields.assigned_to') }}</label>
        <select id="crm-assigned_to" name="assigned_to">
            <option value="">{{ __('crm.fields.unassigned') }}</option>
            @foreach ($users as $user)
                <option value="{{ $user->id }}" @selected((string) old('assigned_to', $task?->assigned_to) === (string) $user->id)>{{ $user->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="crm-field full">
        <label for="crm-title">{{ __('crm.fields.title') }} *</label>
        <input id="crm-title" name="title" type="text" maxlength="255" required value="{{ old('title', $task?->title) }}">
    </div>
    <div class="crm-field">
        <label for="crm-due_at">{{ __('crm.fields.due_at') }}</label>
        <input id="crm-due_at" name="due_at" type="datetime-local" value="{{ old('due_at', optional($task?->due_at)->format('Y-m-d\TH:i')) }}">
    </div>
    <div class="crm-field">
        <label for="crm-status">{{ __('crm.fields.status') }} *</label>
        <select id="crm-status" name="status" required>
            @foreach (['pending', 'completed', 'cancelled'] as $status)
                <option value="{{ $status }}" @selected(old('status', $task?->status ?? 'pending') === $status)>{{ __('crm.status.' . $status) }}</option>
            @endforeach
        </select>
    </div>
    <div class="crm-field">
        <label for="crm-priority">{{ __('crm.fields.priority') }} *</label>
        <select id="crm-priority" name="priority" required>
            @foreach (['low', 'medium', 'high'] as $priority)
                <option value="{{ $priority }}" @selected(old('priority', $task?->priority ?? 'medium') === $priority)>{{ __('crm.priority.' . $priority) }}</option>
            @endforeach
        </select>
    </div>
    <div class="crm-field full">
        <label for="crm-description">{{ __('crm.fields.description') }}</label>
        <textarea id="crm-description" name="description" maxlength="5000">{{ old('description', $task?->description) }}</textarea>
    </div>
</div>
