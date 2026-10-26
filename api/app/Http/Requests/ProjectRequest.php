<?php

namespace App\Http\Requests;

use App\Services\CurrentWorkspace;
use App\Services\ProjectPermissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && (! $this->isMethod('POST') || ProjectPermissions::canCreateProjects(app(CurrentWorkspace::class)->resolve($this)));
    }

    public function rules(): array
    {
        return self::fieldRules($this->isMethod('POST'));
    }

    public static function fieldRules(bool $creating): array
    {
        return ['workspace_id' => ['prohibited'], 'workspaceId' => ['prohibited'], 'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:160'],
            'website' => ['nullable', 'url:http,https', 'max:2048'], 'clientName' => [$creating ? 'nullable' : 'prohibited', 'string', 'max:255'],
            'clientEmail' => [$creating ? 'nullable' : 'prohibited', 'email', 'max:255'], 'currency' => ['sometimes', Rule::in(['RUB', 'USD', 'EUR', 'TRY'])],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])], 'sections' => ['prohibited']];
    }

    public function attributes(): array
    {
        return ['title' => trans('interface.title'), 'website' => trans('interface.website'), 'clientEmail' => trans('interface.client_email')];
    }

    public function projectData(): array
    {
        $map = ['website' => 'website_url', 'clientName' => 'client_name', 'clientEmail' => 'client_email'];
        $result = [];
        foreach (array_diff_key($this->validated(), array_flip(['clientName', 'clientEmail', 'sections', 'workspace_id', 'workspaceId'])) as $key => $value) {
            $result[$map[$key] ?? $key] = $value;
        }

        return $result;
    }
}
