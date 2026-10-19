<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

class GroqBridgeTaskAssistantTest extends AiTaskAssistantTest
{
    protected const PROVIDER = 'groq_bridge';

    protected const URL = 'https://projectbrief-ai-bridge.web86.deno.net/ai';

    protected const KEY = 'fake-bridge-secret-at-least-32-characters';

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.providers.groq_bridge.url' => self::URL]);
    }

    public static function providerFailures(): array
    {
        $cases = parent::providerFailures();
        $cases['gateway timeout'] = [504, ['error' => 'upstream_timeout_or_network_error'], 504, 'ai_timeout'];
        $cases['bad gateway'] = [502, ['error' => 'groq_request_failed'], 503, 'ai_unavailable'];
        $cases['bridge unavailable'] = [503, ['error' => 'not_configured'], 503, 'ai_unavailable'];
        $cases['upstream forbidden'] = [502, ['error' => 'groq_request_failed', 'providerStatus' => 403], 503, 'ai_unavailable'];

        return $cases;
    }

    public function test_bridge_receives_identical_groq_payload_and_only_its_own_secret(): void
    {
        $project = Project::factory()->create();
        $this->actingAs(User::factory()->owner($project->workspace)->create());
        $response = ['choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode(['title' => 'Фото', 'description' => 'Увеличить фото.', 'expectedResult' => '', 'questions' => [], 'suggestedIdeas' => []])]]]];
        Http::preventStrayRequests();
        Http::fake(['https://api.groq.com/openai/v1/chat/completions' => Http::response($response), self::URL => Http::response($response)]);
        config(['ai.providers.groq.key' => 'private-groq-key', 'ai.providers.groq.model' => 'openai/gpt-oss-120b', 'ai.providers.zveno.output_mode' => 'prompt_json']);
        $url = '/api/admin/projects/'.$project->uuid.'/ai/task-assistant';
        $input = ['description' => 'Крупнее фото.', 'consent' => true];

        config(['ai.provider' => 'groq']);
        $this->postJson($url, $input)->assertOk();
        config(['ai.provider' => 'groq_bridge', 'ai.providers.groq.key' => '']);
        $this->postJson($url, $input)->assertOk();

        $sent = Http::recorded();
        $this->assertSame($sent[0][0]->data(), $sent[1][0]->data());
        $this->assertTrue($sent[1][0]->hasHeader('Authorization', 'Bearer '.self::KEY));
        $this->assertStringNotContainsString('private-groq-key', json_encode($sent[1][0]->headers()).json_encode($sent[1][0]->data()));
        $this->assertStringNotContainsString(self::KEY, json_encode($sent[1][0]->data()));
        $this->assertDatabaseCount('tasks', 0);
        Http::assertSentCount(2);
    }

    public function test_wrong_bridge_secret_returns_safe_error_without_session_logout_or_retry(): void
    {
        $project = Project::factory()->create();
        $owner = User::factory()->owner($project->workspace)->create();
        $this->actingAs($owner);
        config(['ai.providers.groq_bridge.key' => str_repeat('x', 32)]);
        Http::preventStrayRequests();
        Http::fake([self::URL => function (Request $request) {
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer '.str_repeat('x', 32)));

            return Http::response(['error' => 'unauthorized'], 401);
        }]);

        $this->postJson('/api/admin/projects/'.$project->uuid.'/ai/task-assistant', ['description' => 'Крупнее фото.', 'consent' => true])
            ->assertServiceUnavailable()->assertExactJson(['code' => 'ai_unavailable', 'message' => 'AI request could not be completed.']);

        $this->assertAuthenticatedAs($owner);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('tasks', 0);
    }

    public static function invalidUrls(): array
    {
        return [
            'wrong host' => ['https://untrusted.invalid/ai'],
            'plain HTTP' => ['http://projectbrief-ai-bridge.web86.deno.net/ai'],
            'wrong path' => ['https://projectbrief-ai-bridge.web86.deno.net/other'],
            'query' => [self::URL.'?destination=https://untrusted.invalid'],
            'credentials' => ['https://secret@projectbrief-ai-bridge.web86.deno.net/ai'],
        ];
    }

    #[DataProvider('invalidUrls')]
    public function test_bridge_cannot_send_secret_to_unapproved_url(string $url): void
    {
        $project = Project::factory()->create();
        $this->actingAs(User::factory()->owner($project->workspace)->create());
        Http::preventStrayRequests();
        Http::fake([self::URL => Http::response([])]);
        config(['ai.providers.groq_bridge.url' => $url]);

        $this->postJson('/api/admin/projects/'.$project->uuid.'/ai/task-assistant', ['description' => 'Крупнее фото.', 'consent' => true])
            ->assertServiceUnavailable()->assertJsonPath('code', 'ai_unavailable');

        Http::assertNothingSent();
    }

    public function test_short_secret_is_rejected_before_network_or_quota_consumption(): void
    {
        $project = Project::factory()->create();
        $this->actingAs(User::factory()->owner($project->workspace)->create());
        Http::preventStrayRequests();
        Http::fake([self::URL => Http::response([])]);
        config(['ai.providers.groq_bridge.key' => 'short']);

        $this->postJson('/api/admin/projects/'.$project->uuid.'/ai/task-assistant', ['description' => 'Крупнее фото.', 'consent' => true])
            ->assertServiceUnavailable()->assertJsonPath('code', 'ai_disabled');

        Http::assertNothingSent();
    }
}
