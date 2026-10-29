<?php

namespace Database\Factories;

use App\Models\ProjectRequest;
use App\Models\Workspace;
use App\Services\IntegrationTokens;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** @extends Factory<ProjectRequest> */
class ProjectRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'integration_source_id' => fn (array $a) => DB::table('integration_sources')->insertGetId(['uuid' => (string) Str::uuid(), 'workspace_id' => $a['workspace_id'], 'name' => 'Website', 'created_at' => now(), 'updated_at' => now()]),
            'integration_token_id' => function (array $a): int {
                $token = app(IntegrationTokens::class)->create($a['workspace_id'], ['name' => 'Form', 'scopes' => ['requests:create'], 'sourceId' => $a['integration_source_id']]);

                return DB::table('integration_tokens')->where('uuid', $token['id'])->value('id');
            },
            'external_id' => fn () => (string) Str::uuid(), 'external_hash' => fn (array $a) => hash('sha256', $a['external_id']), 'body_hash' => hash('sha256', 'factory'),
            'client_name' => 'Ada Client', 'email' => 'ada@example.test', 'company' => 'Example Ltd', 'website' => 'https://example.test',
            'project_name' => 'Company website', 'description' => 'Accessible website', 'language' => 'en', 'status' => 'pending',
        ];
    }
}
