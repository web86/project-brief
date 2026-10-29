<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IntegrationWrites
{
    public static function lockWorkspace(int $workspaceId): void
    {
        // SQLite has no row locks; acquire its write reservation before reading dedupe records.
        if (DB::getDriverName() === 'sqlite') {
            DB::table('workspaces')->where('id', $workspaceId)->update(['id' => $workspaceId]);
        }
        Workspace::whereKey($workspaceId)->lockForUpdate()->firstOrFail();
    }

    public static function fingerprint(array $data): string
    {
        ksort($data);
        foreach ($data as &$value) {
            if (is_array($value)) {
                $value = self::fingerprint($value);
            }
        }

        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function run(Request $request, callable $create, ?array $canonicalInput = null): JsonResponse
    {
        $key = $request->header('Idempotency-Key');
        abort_unless(is_string($key) && preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $key), 422, 'A 1–128 character Idempotency-Key is required.');
        $token = $request->attributes->get('integration_token');
        $keyHash = hash('sha256', $token->uuid.'|'.$request->path().'|'.$key);
        $data = $request->all();
        ksort($data);
        $bodyHash = $canonicalInput === null ? hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)) : self::fingerprint($canonicalInput);

        return DB::transaction(function () use ($request, $token, $keyHash, $bodyHash, $create): JsonResponse {
            self::lockWorkspace($token->workspace_id);
            $current = DB::table('integration_tokens')->where('id', $token->id)->whereNull('revoked_at')
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->first();
            abort_unless($current, 401);
            $required = $request->attributes->get('integration_required_scope');
            abort_if($required && ! in_array($required, json_decode($current->scopes, true), true), 403);
            abort_if($required === 'projects:provision' && ! $current->provisioning_enabled, 403);
            $previous = DB::table('integration_requests')->where('workspace_id', $token->workspace_id)->where('key_hash', $keyHash)->first();
            if ($previous) {
                abort_unless(hash_equals($previous->body_hash, $bodyHash), 409, 'Idempotency key was already used with another body.');
                $response = json_decode($previous->response, true, 512, JSON_THROW_ON_ERROR);
                $projectUuid = $response['data']['projectId'] ?? $response['data']['id'] ?? null;
                if ($projectUuid) {
                    Project::where('workspace_id', $token->workspace_id)->where('uuid', $projectUuid)->firstOrFail();
                }

                return response()->json($response, $previous->status)->header('Idempotency-Replayed', 'true');
            }
            [$body, $status] = $create();
            DB::table('integration_requests')->insert(['workspace_id' => $token->workspace_id, 'key_hash' => $keyHash, 'body_hash' => $bodyHash, 'response' => json_encode($body, JSON_THROW_ON_ERROR), 'status' => $status, 'created_at' => now()]);

            return response()->json($body, $status);
        }, 3);
    }
}
