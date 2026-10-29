<?php

namespace App\Http\Controllers;

use App\Services\IntegrationWrites;
use App\Services\ProjectRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectRequestController extends Controller
{
    private function token(Request $request, string $scope): object
    {
        $request->attributes->set('integration_required_scope', $scope);
        $token = $request->attributes->get('integration_token');
        abort_unless(in_array($scope, json_decode($token->scopes, true), true), 403);

        return $token;
    }

    public function index(Request $request, ProjectRequests $requests): JsonResponse
    {
        $token = $this->token($request, 'requests:read');

        return response()->json($requests->listing($request, $token->workspace_id));
    }

    public function show(Request $request, string $uuid, ProjectRequests $requests): JsonResponse
    {
        $token = $this->token($request, 'requests:read');

        return response()->json(['data' => $requests->resource($requests->find($token->workspace_id, $uuid))]);
    }

    public function store(Request $request, ProjectRequests $requests): JsonResponse
    {
        $request->attributes->set('operation_type', 'request.create');
        $token = $this->token($request, 'requests:create');
        $input = $requests->input($request);
        $response = app(IntegrationWrites::class)->run($request, fn () => $requests->create($token, $input), $input);
        $uuid = $response->getData(true)['data']['requestId'];
        $request->attributes->set('project_request_uuid', $uuid);
        $request->attributes->set('provisioning_reused', $response->getStatusCode() === 200 || $response->headers->has('Idempotency-Replayed'));

        return $response->setData(['data' => $requests->creationResource($requests->find($token->workspace_id, $uuid))]);
    }

    public function approve(Request $request, string $uuid, ProjectRequests $requests): JsonResponse
    {
        return $this->decision($request, $uuid, 'approve', $requests);
    }

    public function reject(Request $request, string $uuid, ProjectRequests $requests): JsonResponse
    {
        return $this->decision($request, $uuid, 'reject', $requests);
    }

    private function decision(Request $request, string $uuid, string $action, ProjectRequests $requests): JsonResponse
    {
        $request->attributes->set('operation_type', 'request.'.$action);
        $token = $this->token($request, 'requests:approve');
        $input = $requests->decisionInput($request, $action);
        $request->attributes->set('project_request_uuid', $uuid);
        $response = app(IntegrationWrites::class)->run($request, fn () => $requests->decide($token->workspace_id, $uuid, $action, $input, ['type' => 'integration', 'id' => $token->uuid, 'name' => $token->name]), $input);
        $row = $requests->find($token->workspace_id, $uuid);
        $requests->deliver($row);
        $request->attributes->set('provisioning_uuid', $row->result['operationId'] ?? null);
        $request->attributes->set('provisioning_reused', $response->headers->has('Idempotency-Replayed') || $request->attributes->get('project_request_reused', false));

        return $response->setData(['data' => $requests->resource($row)]);
    }
}
