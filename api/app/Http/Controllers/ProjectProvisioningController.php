<?php

namespace App\Http\Controllers;

use App\Services\DeveloperInvitations;
use App\Services\IntegrationWrites;
use App\Services\ProjectProvisioning;
use App\Services\SafeExceptionReporter;
use App\Services\SupportedLocales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectProvisioningController extends Controller
{
    public function store(Request $request, ProjectProvisioning $provisioning): JsonResponse
    {
        $request->attributes->set('integration_required_scope', 'projects:provision');
        $token = $request->attributes->get('integration_token');
        abort_unless(in_array('projects:provision', json_decode($token->scopes, true), true) && $token->provisioning_enabled, 403);
        abort_unless($request->isJson(), 415);
        abort_if(strlen($request->getContent()) > 32768, 413);
        abort_if(count(array_diff(array_keys($request->all()), ['externalId', 'client', 'project', 'sendInvitation', 'templateId'])) > 0, 422);
        $data = $request->validate([
            'externalId' => ['required', 'string', 'max:160', 'regex:/\S/u'],
            'client' => ['required', 'array:name,email,company,website'],
            'client.name' => ['required', 'string', 'max:255', 'regex:/\S/u'],
            'client.email' => ['required', 'string', 'email', 'max:255'],
            'client.company' => ['nullable', 'string', 'max:255'],
            'client.website' => ['nullable', 'url:http,https', 'max:2048'],
            'project' => ['required', 'array:name,description,language'],
            'project.name' => ['required', 'string', 'max:160', 'regex:/\S/u'],
            'project.description' => ['nullable', 'string', 'max:10000'],
            'project.language' => ['required', Rule::in(SupportedLocales::codes())],
            'sendInvitation' => ['required', 'boolean'],
            'templateId' => ['prohibited'],
        ]);
        $input = [
            'externalId' => trim($data['externalId']),
            'client' => ['name' => trim($data['client']['name']), 'email' => DeveloperInvitations::normalizeEmail($data['client']['email']),
                'company' => $data['client']['company'] ?? null, 'website' => $data['client']['website'] ?? null],
            'project' => ['name' => trim($data['project']['name']), 'description' => $data['project']['description'] ?? null, 'language' => $data['project']['language']],
            'sendInvitation' => (bool) $data['sendInvitation'],
        ];
        $response = app(IntegrationWrites::class)->run($request, fn () => $provisioning->create($token, $input), $input);
        if ($response->getStatusCode() >= 400) {
            return $response;
        }
        $body = $response->getData(true);
        $uuid = $body['data']['operationId'];
        $request->attributes->set('provisioning_uuid', $uuid);
        // Delivery runs only after IntegrationWrites has committed the complete operation.
        try {
            $provisioning->deliver($uuid, $token->workspace_id);
        } catch (\Throwable $exception) {
            app(SafeExceptionReporter::class)->report($exception);
        }
        $body['data']['invitation'] = $provisioning->status($uuid, $token->workspace_id);
        $request->attributes->set('provisioning_reused', ! $body['data']['created'] || $response->headers->has('Idempotency-Replayed'));

        return $response->setData($body);
    }
}
