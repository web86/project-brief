<?php

namespace App\Services;

use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IntegrationWrites
{
    public function run(Request $request, callable $create): JsonResponse
    {
        $key = $request->header('Idempotency-Key');
        abort_unless(is_string($key) && preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $key), 422, 'A 1–128 character Idempotency-Key is required.');
        $token = $request->attributes->get('integration_token');
        $keyHash = hash('sha256', $token->uuid.'|'.$request->path().'|'.$key);
        $data = $request->all();
        ksort($data);
        $bodyHash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($token, $keyHash, $bodyHash, $create): JsonResponse {
            Workspace::whereKey($token->workspace_id)->lockForUpdate()->firstOrFail();
            $previous = DB::table('integration_requests')->where('workspace_id', $token->workspace_id)->where('key_hash', $keyHash)->first();
            if ($previous) {
                abort_unless(hash_equals($previous->body_hash, $bodyHash), 409, 'Idempotency key was already used with another body.');

                return response()->json(json_decode($previous->response, true, 512, JSON_THROW_ON_ERROR), $previous->status)->header('Idempotency-Replayed', 'true');
            }
            [$body, $status] = $create();
            DB::table('integration_requests')->insert(['workspace_id' => $token->workspace_id, 'key_hash' => $keyHash, 'body_hash' => $bodyHash, 'response' => json_encode($body, JSON_THROW_ON_ERROR), 'status' => $status, 'created_at' => now()]);

            return response()->json($body, $status);
        }, 3);
    }
}
