<?php

namespace App\Http\Requests;

use App\Services\ProjectExportData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProjectExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'format' => ['required', Rule::in(['pdf', 'docx'])],
            'scope' => ['required', Rule::in(['all', 'approved'])],
            'locale' => ['required', Rule::in(['ru', 'en'])],
            'fields' => ['sometimes', 'array:'.implode(',', array_keys(ProjectExportData::DEFAULT_FIELDS))],
        ];
        foreach (ProjectExportData::DEFAULT_FIELDS as $field => $default) {
            $rules['fields.'.$field] = ['sometimes', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_bool($value)) {
                    $fail('The option must be a boolean.');
                }
            }];
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['format', 'scope', 'locale', 'fields'])) {
                $validator->errors()->add('options', 'Unknown export options.');
            }
        }];
    }
}
