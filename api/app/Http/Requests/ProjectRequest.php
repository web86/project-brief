<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return ['title' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:160'],
            'website' => ['nullable', 'url:http,https', 'max:2048'], 'clientName' => [$this->isMethod('POST') ? 'nullable' : 'prohibited', 'string', 'max:255'],
            'clientEmail' => [$this->isMethod('POST') ? 'nullable' : 'prohibited', 'email', 'max:255'], 'currency' => ['sometimes', Rule::in(['RUB', 'USD', 'EUR', 'TRY'])],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])], 'sections' => [$this->isMethod('POST') ? 'sometimes' : 'prohibited', 'array', 'max:30'], 'sections.*' => ['string', 'max:160', 'distinct']];
    }

    public function attributes(): array
    {
        return ['title' => 'название', 'website' => 'адрес сайта', 'clientEmail' => 'email клиента'];
    }

    public function projectData(): array
    {
        $map = ['website' => 'website_url', 'clientName' => 'client_name', 'clientEmail' => 'client_email'];
        $result = [];
        foreach (array_diff_key($this->validated(), array_flip(['clientName', 'clientEmail', 'sections'])) as $key => $value) {
            $result[$map[$key] ?? $key] = $value;
        }

        return $result;
    }
}
