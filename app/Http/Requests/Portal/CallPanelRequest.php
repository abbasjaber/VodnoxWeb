<?php

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared payload for the incoming-call and reception workspaces.
 *
 * Every field is optional at the transport level; the controller enforces the
 * fields required by the submitted action so a single endpoint can handle
 * starting a session, saving documentation panels and completing the wrap-up.
 */
class CallPanelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'caller_number' => $this->filled('caller_number') ? trim((string) $this->input('caller_number')) : null,
            'customer_id' => $this->filled('customer_id') ? $this->input('customer_id') : null,
            'content' => $this->filled('content') ? trim((string) $this->input('content')) : null,
            'subject' => $this->filled('subject') ? trim((string) $this->input('subject')) : null,
            'description' => $this->filled('description') ? trim((string) $this->input('description')) : null,
            'title' => $this->filled('title') ? trim((string) $this->input('title')) : null,
            'summary' => $this->filled('summary') ? trim((string) $this->input('summary')) : null,
            'department_id' => $this->filled('department_id') ? $this->input('department_id') : null,
            'assigned_to' => $this->filled('assigned_to') ? $this->input('assigned_to') : null,
        ]);
    }

    public function rules(): array
    {
        $organizationId = $this->user()?->organization_id;

        return [
            'action' => ['required', Rule::in(['start', 'note', 'complaint', 'followup', 'wrapup', 'finish'])],
            'caller_number' => ['nullable', 'string', 'max:32'],
            'customer_id' => [
                'nullable', 'integer',
                Rule::exists('customers', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)),
            ],
            'content' => ['nullable', 'string', 'max:5000'],
            'subject' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'title' => ['nullable', 'string', 'max:255'],
            'due_at' => ['nullable', 'date'],
            'priority' => ['nullable', Rule::in(['low', 'medium', 'high'])],
            'department_id' => [
                'nullable', 'integer',
                Rule::exists('departments', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)),
            ],
            'assigned_to' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')->where(
                    fn ($query) => $query->where('organization_id', $organizationId)->where('active', true)
                ),
            ],
            'outcome' => ['nullable', Rule::in(['resolved', 'ticket', 'callback', 'information', 'unanswered'])],
            'summary' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'customer_id.exists' => __('portal.agent.contact_in_org'),
            'department_id.exists' => __('portal.agent.contact_in_org'),
            'assigned_to.exists' => __('portal.agent.contact_in_org'),
        ];
    }

    public function attributes(): array
    {
        return [
            'caller_number' => __('portal.agent.customer_phone'),
            'content' => __('portal.agent.content'),
            'subject' => __('portal.agent.subject'),
            'description' => __('portal.agent.description'),
            'title' => __('portal.agent.title_field'),
            'due_at' => __('portal.agent.due_at'),
            'outcome' => __('portal.agent.outcome'),
            'summary' => __('portal.agent.summary'),
        ];
    }
}
