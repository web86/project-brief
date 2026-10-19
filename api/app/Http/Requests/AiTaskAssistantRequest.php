<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AiTaskAssistantRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:10000'],
            'expectedResult' => ['nullable', 'string', 'max:10000'],
            'section' => ['nullable', 'string', 'max:2048'],
            'location' => ['nullable', 'string', 'max:2048'],
            'consent' => ['required', 'accepted'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['title', 'description', 'expectedResult', 'section', 'location', 'consent'])) {
                $validator->errors()->add('fields', 'Only task form text is accepted.');
            }
            if (! collect($this->only(['title', 'description', 'expectedResult']))->contains(fn ($value) => is_string($value) && trim($value) !== '')) {
                $validator->errors()->add('description', 'Task text is required.');
            }
        }];
    }
}
