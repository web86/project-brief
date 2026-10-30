<?php

namespace App\Http\Requests;

use App\Rules\SafeAttachment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

class CommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['text' => ['nullable', 'required_without:attachments', 'string', 'max:5000'],
            'submissionId' => ['required_with:attachments', 'nullable', 'uuid'],
            'attachments' => ['sometimes', 'array', 'min:1', 'max:5'],
            'attachments.*' => ['required', 'file', 'max:20480', new SafeAttachment],
            'team_only' => ['prohibited'], 'author' => ['prohibited'], 'author_type' => ['prohibited'], 'author_user_id' => ['prohibited'],
            'task_id' => ['prohibited'], 'project_id' => ['prohibited'], 'comment_id' => ['prohibited'],
            'project_client_id' => ['prohibited'], 'projectClientId' => ['prohibited']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $files = $this->file('attachments', []);
            if (is_array($files) && array_sum(array_map(fn ($file) => $file instanceof UploadedFile ? $file->getSize() : 0, $files)) > 40 * 1024 * 1024) {
                $validator->errors()->add('attachments', 'Суммарный размер файлов комментария не должен превышать 40 МБ.');
            }
        }];
    }
}
