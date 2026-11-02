<?php

namespace App\Services;

use Illuminate\Http\JsonResponse;

/** Presentation only: stored credentials remain expired, endpoints and SSO remain disabled. */
class DemoIntegrationPreview
{
    public static function decorate(JsonResponse $response, string $path): JsonResponse
    {
        if (! DemoEnvironment::enabled()) {
            return $response;
        }
        $body = $response->getData(true);
        if ($path === 'api/admin/integrations') {
            foreach ($body['tokens'] ?? [] as $i => $token) {
                $body['tokens'][$i] = [...$token, 'expiresAt' => null, 'demoMaskedCredential' => 'pb_demo_••••••••'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT), 'synthetic' => true];
            }
            foreach ($body['sources'] ?? [] as $i => $source) {
                $body['sources'][$i] = [...$source, 'ssoEnabled' => $i < 2, 'synthetic' => true];
            }
            foreach ($body['webhooks'] ?? [] as $i => $endpoint) {
                $body['webhooks'][$i] = [...$endpoint, 'enabled' => true, 'synthetic' => true];
            }
        }

        if ($path === 'api/admin/integrations/agents') {
            foreach ($body['data'] ?? [] as $i => $agent) {
                foreach ($agent['tokens'] ?? [] as $j => $token) {
                    $body['data'][$i]['tokens'][$j] = [...$token, 'expiresAt' => null, 'synthetic' => true];
                }
            }
        }
        if (preg_match('~^api/admin/(?:tasks/[^/]+|projects/[^/]+/tasks)$~D', $path) && isset($body['data'])) {
            if (array_is_list($body['data'])) {
                $body['data'] = array_map(self::task(...), $body['data']);
            } else {
                $body['data'] = self::task($body['data']);
            }
        }

        return $response->setData($body);
    }

    private static function task(array $task): array
    {
        foreach ($task['agentAssignments'] ?? [] as $i => $assignment) {
            $task['agentAssignments'][$i]['agent'] = [...$assignment['agent'], 'hasCredentials' => true, 'synthetic' => true];
        }

        return $task;
    }
}
