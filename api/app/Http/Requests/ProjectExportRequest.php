<?php

namespace App\Http\Requests;

use App\Services\ProjectExportData;
use App\Services\SupportedLocales;
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
            'scope' => ['required', Rule::in(['all', 'approved', 'done'])],
            'locale' => ['required', Rule::in(SupportedLocales::codes())],
            'documentTitle' => ['sometimes', 'nullable', 'string', 'max:160', 'regex:/\A[^\p{Cc}\p{Cf}]*\z/u'],
            'pageBreakBySection' => ['sometimes', $this->booleanOption()],
            'fields' => ['sometimes', 'array:'.implode(',', array_keys(ProjectExportData::DEFAULT_FIELDS))],
        ];
        foreach (ProjectExportData::DEFAULT_FIELDS as $field => $default) {
            $rules['fields.'.$field] = ['sometimes', $this->booleanOption()];
        }

        return $rules;
    }

    private function booleanOption(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_bool($value)) {
                $fail('The option must be a boolean.');
            }
        };
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['format', 'scope', 'locale', 'fields', 'documentTitle', 'pageBreakBySection'])) {
                $validator->errors()->add('options', 'Unknown export options.');
            }
        }];
    }
}
