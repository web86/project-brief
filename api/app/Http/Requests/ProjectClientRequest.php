<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ProjectClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return ['name' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:255', 'regex:/\S/u'], 'email' => ['nullable', 'email', 'max:255'], 'active' => ['sometimes', 'boolean']];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $key) {
                $validator->errors()->add($key, 'Это поле нельзя изменять.');
            }
        });
    }
}
