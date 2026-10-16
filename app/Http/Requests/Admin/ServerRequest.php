<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ServerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:190'],
            'host' => ['nullable', 'string', 'max:190'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'connection_method' => ['required', Rule::in(['ami', 'ari', 'http'])],
            'ami_username' => ['nullable', 'string', 'max:190'],
            'ami_secret' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(['connected', 'disconnected', 'error'])],
        ];
    }
}
