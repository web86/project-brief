<?php

namespace App\Http\Requests;

use App\Rules\SafeAttachment;
use Illuminate\Foundation\Http\FormRequest;

class AttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public static function fileRules(bool $required = false): array
    {
        return ['attachments' => [$required ? 'required' : 'sometimes', 'array', 'min:1', 'max:10'], 'attachments.*' => ['required', 'file', 'max:20480', new SafeAttachment]];
    }

    public function rules(): array
    {
        return self::fileRules(true) + ['path' => ['prohibited'], 'disk' => ['prohibited'], 'task_id' => ['prohibited'], 'uploaded_by_type' => ['prohibited']];
    }
}
