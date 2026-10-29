<?php

namespace App\Http\Middleware;

use App\Services\SafeExceptionReporter;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class RecordProvisioningOperation
{
    public function handle(Request $request, Closure $next): Response
    {
        $status = 500;
        try {
            $response = $next($request);
            $status = $response->getStatusCode();

            return $response;
        } catch (Throwable $exception) {
            $status = $exception instanceof ValidationException ? 422 : ($exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : ($exception instanceof ModelNotFoundException ? 404 : 500));
            throw $exception;
        } finally {
            try {
                $token = $request->attributes->get('integration_token');
                DB::table('provisioning_operations')->insert([
                    'workspace_id' => $token->workspace_id, 'integration_token_id' => $token->id,
                    'operation_type' => $request->attributes->get('operation_type', 'project.provision'),
                    'project_request_uuid' => $request->attributes->get('project_request_uuid'),
                    'provisioning_uuid' => $request->attributes->get('provisioning_uuid'),
                    'outcome' => $status >= 400 ? 'error' : ($request->attributes->get('provisioning_reused') ? 'replayed' : 'success'),
                    'http_status' => $status, 'created_at' => now(),
                ]);
                $cutoff = DB::table('provisioning_operations')->where('workspace_id', $token->workspace_id)->orderByDesc('id')->skip(499)->value('id');
                if ($cutoff) {
                    DB::table('provisioning_operations')->where('workspace_id', $token->workspace_id)->where('id', '<', $cutoff)->delete();
                }
            } catch (Throwable $exception) {
                app(SafeExceptionReporter::class)->report($exception);
            }
        }
    }
}
