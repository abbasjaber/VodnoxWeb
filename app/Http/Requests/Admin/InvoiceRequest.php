<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'organization_id' => ['required', 'exists:organizations,id'],
            'subscription_id' => ['nullable', 'exists:subscriptions,id'],
            'invoice_number' => ['nullable', 'string', 'max:50', Rule::unique('invoices', 'invoice_number')->ignore($this->route('invoice')?->id)],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'max:8'],
            'status' => ['required', Rule::in(['draft', 'unpaid', 'paid', 'overdue', 'cancelled'])],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
