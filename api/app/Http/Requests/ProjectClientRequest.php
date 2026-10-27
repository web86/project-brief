<?php

namespace App\Http\Requests;

use App\Services\CurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ProjectClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && (bool) app(CurrentWorkspace::class)->resolve($this);
    }

    public function rules(): array
    {
        return ['name' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:255', 'regex:/\S/u'], 'email' => ['required_if:sendInvitation,true', 'nullable', 'email', 'max:255'], 'active' => ['sometimes', 'boolean'],
            ...($this->isMethod('POST') ? ['sendInvitation' => ['sometimes', 'boolean']] : [])];
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
