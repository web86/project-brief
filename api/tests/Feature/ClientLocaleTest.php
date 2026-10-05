<?php

namespace Tests\Feature;

use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ClientLocaleTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_locale_is_session_scoped_and_only_ru_en_are_accepted(): void
    {
        $project = Project::factory()->create();
        $client = $project->clients()->first();
        $other = $project->clients()->create(['name' => 'Anna']);
        $token = $project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', 'locale')]);
        $this->withSession(['client_project_id' => $project->id, 'project_client_id' => $client->id, 'client_access_token_id' => $token->id]);
        $this->getJson('/api/client/me')->assertOk()->assertJsonPath('preferredLocale', null);
        $this->patchJson('/api/client/me/locale', ['locale' => 'en'])->assertOk()->assertJsonPath('preferredLocale', 'en');
        $this->assertSame('en', $client->fresh()->preferred_locale);
        $this->assertNull($other->fresh()->preferred_locale);
        foreach (['tr', 'de', null, ''] as $locale) {
            $this->patchJson('/api/client/me/locale', ['locale' => $locale])->assertUnprocessable();
        }
        $this->patchJson('/api/client/me/locale', ['locale' => 'ru', 'project_client_id' => $other->id])->assertUnprocessable();
        $this->assertSame('en', $client->fresh()->preferred_locale);
        $this->assertNull($other->fresh()->preferred_locale);
    }

    public function test_unauthenticated_locale_change_is_rejected(): void
    {
        $this->patchJson('/api/client/me/locale', ['locale' => 'en'])->assertUnauthorized();
    }
}
