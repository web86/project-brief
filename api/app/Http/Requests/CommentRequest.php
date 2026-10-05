<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['text' => ['required', 'string', 'max:5000'], 'author' => ['prohibited'], 'author_type' => ['prohibited'], 'author_user_id' => ['prohibited'], 'task_id' => ['prohibited'], 'project_client_id' => ['prohibited'], 'projectClientId' => ['prohibited']];
    }
}
