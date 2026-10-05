<?php

namespace App\Http\Requests;

use App\Services\TaskAccess;
use App\Services\TaskAudit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class TaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $rules = ['title' => [$required, 'string', 'max:160'], 'location' => [$required, 'string', 'max:2048'],
            'description' => [$required, 'string', 'max:10000'], 'expectedResult' => ['nullable', 'string', 'max:10000'],
            'priority' => [$required, Rule::in(['low', 'normal', 'high'])]];
        if (TaskAccess::isAdmin($this)) {
            $rules += ['status' => ['sometimes', Rule::in(array_keys(TaskAudit::STATUSES))],
                'estimateHours' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'], 'price' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
                'developerNotes' => ['nullable', 'string', 'max:10000']];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $key) {
                $validator->errors()->add($key, 'Это поле нельзя изменять.');
            }
        });
    }

    public function taskData(): array
    {
        $map = ['expectedResult' => 'expected_result', 'estimateHours' => 'estimate_hours', 'developerNotes' => 'developer_notes'];
        $result = [];
        foreach ($this->validated() as $key => $value) {
            $result[$map[$key] ?? $key] = $value;
        }
        if (isset($result['location'])) {
            $result['section'] = $result['location'];
        }

        return $result;
    }
}
