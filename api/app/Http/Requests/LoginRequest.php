<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['invitation_token' => ['sometimes', 'string', 'regex:/^[a-f0-9]{64}$/'], 'email' => ['required', 'email', 'max:255'], 'password' => ['required', 'string', 'max:1024']];
    }
}
