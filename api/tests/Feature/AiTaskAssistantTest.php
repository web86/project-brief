<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AiTaskAssistantTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const URL = 'https://api.groq.com/openai/v1/chat/completions';

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.enabled' => true, 'ai.key' => 'test-secret-key', 'ai.model' => 'openai/gpt-oss-120b', 'ai.per_minute' => 5, 'ai.daily_limit' => 50]);
    }

    private function input(): array
    {
        return ['title' => 'Крупнее фото', 'description' => 'Хочу увеличить фото товара.', 'expectedResult' => 'Фото хорошо видно.', 'section' => 'Каталог', 'location' => '/catalog', 'consent' => true];
    }

    private static function suggestion(): array
    {
        return ['title' => 'Увеличить фотографии товара', 'description' => 'Увеличить размер фотографий на странице товара.', 'expectedResult' => 'Фотографии товара хорошо видны.', 'questions' => ['Какой размер нужен?'], 'suggestedIdeas' => []];
    }

    private function fakeSuccess(?array $suggestion = null): void
    {
        Http::preventStrayRequests();
        Http::fake([self::URL => Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode($suggestion ?? self::suggestion(), JSON_UNESCAPED_UNICODE)]]]])]);
    }

    private function admin(Project $project): void
    {
        $this->actingAs(User::factory()->owner($project->workspace)->create());
        $this->withSession(['workspace_id' => $project->workspace_id]);
    }

    private function client(Project $project, array $tokenFields = []): void
    {
        $client = $project->clients()->first();
        $token = $project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', 'ai-test-link'), ...$tokenFields]);
        $this->withSession(['client_project_id' => $project->id, 'project_client_id' => $client->id, 'client_access_token_id' => $token->id]);
    }

    private function url(Project $project, string $actor = 'admin'): string
    {
        return $actor === 'client' ? '/api/client/project/'.$project->uuid.'/ai/task-assistant' : '/api/admin/projects/'.$project->uuid.'/ai/task-assistant';
    }

    public static function actors(): array
    {
        return ['owner' => ['owner'], 'assigned admin' => ['assigned'], 'client' => ['client']];
    }

    #[DataProvider('actors')]
    public function test_authorized_users_receive_structured_suggestion_without_saving_or_leaking_private_data(string $actor): void
    {
        $project = Project::factory()->create(['title' => 'PRIVATE-PROJECT', 'client_email' => 'private@example.test']);
        $task = Task::factory()->for($project)->create(['developer_notes' => 'PRIVATE-NOTES', 'description' => 'PRIVATE-OTHER-TASK']);
        $task->comments()->create(['author_type' => 'admin', 'text' => 'PRIVATE-COMMENT']);
        $this->fakeSuccess();
        Log::spy();
        if ($actor === 'client') {
            $this->client($project);
        } elseif ($actor === 'assigned') {
            $admin = User::factory()->memberOf($project->workspace)->create();
            $project->members()->create(['workspace_id' => $project->workspace_id, 'workspace_member_id' => $admin->workspaceMembers()->first()->id]);
            $this->actingAs($admin);
        } else {
            $this->admin($project);
        }

        $this->postJson($this->url($project, $actor === 'client' ? 'client' : 'admin'), $this->input(), ['Accept-Language' => 'en'])
            ->assertOk()->assertExactJson(self::suggestion())->assertHeader('Cache-Control', 'no-store, private');

        Http::assertSent(function (Request $request): bool {
            $fields = $this->input();
            unset($fields['consent']);
            $this->assertSame($fields, json_decode($request['messages'][1]['content'], true));
            $this->assertSame('openai/gpt-oss-120b', $request['model']);
            $this->assertSame('json_schema', $request['response_format']['type']);
            $this->assertTrue($request['response_format']['json_schema']['strict']);
            $this->assertFalse($request['response_format']['json_schema']['schema']['additionalProperties']);
            $this->assertCount(2, $request['messages']);
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer test-secret-key'));
            foreach (['PRIVATE-', 'private@example.test', 'consent', 'locale'] as $private) {
                $this->assertStringNotContainsString($private, json_encode($request->data()));
            }

            return $request->url() === self::URL;
        });
        Http::assertSentCount(1);
        $this->assertDatabaseCount('tasks', 1);
        $this->assertDatabaseCount('task_history', 0);
        $this->assertDatabaseCount('comments', 1);
        $this->assertSame('PRIVATE-OTHER-TASK', $task->fresh()->description);
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('info');
    }

    public function test_split_suggestions_are_returned_without_creating_tasks_and_empty_expected_result_is_allowed(): void
    {
        $project = Project::factory()->create();
        $this->admin($project);
        $suggestion = [...self::suggestion(), 'expectedResult' => '', 'suggestedIdeas' => [['title' => 'Фото', 'description' => 'Увеличить фото.', 'expectedResult' => '']]];
        $this->fakeSuccess($suggestion);

        $this->postJson($this->url($project), ['description' => 'Увеличить фото и поменять меню.', 'consent' => true])
            ->assertOk()->assertExactJson($suggestion);

        Http::assertSentCount(1);
        $this->assertDatabaseCount('tasks', 0);
    }

    public static function invalidInputs(): array
    {
        return [
            'no text' => [['title' => '', 'description' => '', 'expectedResult' => ''], 'description'],
            'context alone' => [['title' => null, 'description' => null, 'expectedResult' => null], 'description'],
            'no consent' => [['consent' => false], 'consent'],
            'long title' => [['title' => str_repeat('я', 161)], 'title'],
            'long description' => [['description' => str_repeat('x', 10001)], 'description'],
            'long result' => [['expectedResult' => str_repeat('x', 10001)], 'expectedResult'],
            'long section' => [['section' => str_repeat('x', 2049)], 'section'],
            'long location' => [['location' => str_repeat('x', 2049)], 'location'],
            'wrong type' => [['description' => ['secret']], 'description'],
            'private field' => [['developerNotes' => 'PRIVATE-NOTES'], 'fields'],
            'project dump' => [['project' => ['comments' => 'PRIVATE']], 'fields'],
            'forged workspace' => [['workspace_id' => 1234], 'fields'],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_input_returns_422_without_contacting_provider(array $changes, string $field): void
    {
        $project = Project::factory()->create();
        $this->admin($project);
        $this->fakeSuccess();

        $this->postJson($this->url($project), [...$this->input(), ...$changes])->assertUnprocessable()->assertJsonValidationErrors($field);

        Http::assertNothingSent();
    }

    public static function invalidOutputs(): array
    {
        $valid = self::suggestion();

        return [
            'invalid JSON' => ['not JSON'],
            'array' => ['[]'],
            'null' => ['null'],
            'missing title' => [json_encode(array_diff_key($valid, ['title' => true]))],
            'extra field' => [json_encode([...$valid, 'developerNotes' => 'PRIVATE'])],
            'numeric title' => [json_encode([...$valid, 'title' => 42])],
            'blank description' => [json_encode([...$valid, 'description' => '  '])],
            'too long title' => [json_encode([...$valid, 'title' => str_repeat('я', 161)])],
            'too long description' => [json_encode([...$valid, 'description' => str_repeat('x', 10001)])],
            'too long result' => [json_encode([...$valid, 'expectedResult' => str_repeat('x', 10001)])],
            'too many questions' => [json_encode([...$valid, 'questions' => array_fill(0, 6, 'Question?')])],
            'long question' => [json_encode([...$valid, 'questions' => [str_repeat('x', 1001)]])],
            'object questions' => [json_encode([...$valid, 'questions' => (object) []])],
            'object tasks' => [json_encode([...$valid, 'suggestedIdeas' => (object) []])],
            'too many tasks' => [json_encode([...$valid, 'suggestedIdeas' => array_fill(0, 4, array_intersect_key($valid, array_flip(['title', 'description', 'expectedResult'])))])],
            'invalid task' => [json_encode([...$valid, 'suggestedIdeas' => [['title' => 'Missing fields']]])],
            'non object task' => [json_encode([...$valid, 'suggestedIdeas' => ['Task']])],
        ];
    }

    #[DataProvider('invalidOutputs')]
    public function test_invalid_model_output_returns_safe_502(string $content): void
    {
        $project = Project::factory()->create();
        $this->admin($project);
        Http::preventStrayRequests();
        Http::fake([self::URL => Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => $content]]]])]);

        $this->postJson($this->url($project), $this->input())->assertStatus(502)
            ->assertExactJson(['code' => 'ai_invalid_response', 'message' => 'AI request could not be completed.']);

        Http::assertSentCount(1);
        $this->assertDatabaseCount('tasks', 0);
    }

    public static function providerFailures(): array
    {
        return [
            'rate limit' => [429, ['error' => 'PRIVATE-KEY'], 429, 'ai_provider_limit'],
            'outage' => [500, ['error' => 'PRIVATE-KEY'], 503, 'ai_unavailable'],
            'redirect' => [302, ['error' => 'PRIVATE-KEY'], 503, 'ai_unavailable'],
            'bad key' => [401, ['error' => 'PRIVATE-KEY'], 503, 'ai_unavailable'],
            'unsupported model' => [400, ['error' => 'PRIVATE-KEY'], 503, 'ai_unavailable'],
            'invalid envelope' => [200, 'not JSON', 502, 'ai_invalid_response'],
            'truncated' => [200, ['choices' => [['finish_reason' => 'length', 'message' => ['content' => '{}']]]], 502, 'ai_invalid_response'],
            'oversized' => [200, str_repeat('x', 150001), 502, 'ai_invalid_response'],
        ];
    }

    #[DataProvider('providerFailures')]
    public function test_provider_failures_return_safe_errors_without_logging_content(int $providerStatus, array|string $body, int $status, string $code): void
    {
        $project = Project::factory()->create();
        $this->admin($project);
        Http::preventStrayRequests();
        Http::fake([self::URL => Http::response($body, $providerStatus)]);
        Log::spy();

        $this->postJson($this->url($project), $this->input())->assertStatus($status)
            ->assertExactJson(['code' => $code, 'message' => 'AI request could not be completed.']);

        Http::assertSentCount(1);
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_connection_timeout_returns_safe_504_without_retrying(): void
    {
        $project = Project::factory()->create();
        $this->admin($project);
        Http::preventStrayRequests();
        Http::fake([self::URL => Http::failedConnection('PRIVATE-REQUEST-AND-KEY')]);
        Log::spy();

        $this->postJson($this->url($project), $this->input())->assertStatus(504)
            ->assertExactJson(['code' => 'ai_timeout', 'message' => 'AI request could not be completed.']);

        Http::assertSentCount(1);
        Log::shouldNotHaveReceived('error');
    }

    public static function disabledConfigurations(): array
    {
        return ['disabled' => [false, 'key'], 'missing key' => [true, ''], 'blank key' => [true, '   ']];
    }

    #[DataProvider('disabledConfigurations')]
    public function test_disabled_or_unconfigured_ai_returns_503_without_external_request(bool $enabled, string $key): void
    {
        $project = Project::factory()->create();
        $this->admin($project);
        $this->fakeSuccess();
        config(['ai.enabled' => $enabled, 'ai.key' => $key]);

        $this->postJson($this->url($project), $this->input())->assertServiceUnavailable()->assertJsonPath('code', 'ai_disabled');

        Http::assertNothingSent();
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_minute_limit_is_shared_across_clients_and_admins_and_projects_of_workspace(): void
    {
        $this->freezeTime();
        $project = Project::factory()->create();
        $sibling = Project::factory()->for($project->workspace)->create();
        $this->admin($project);
        $this->client($sibling);
        $this->fakeSuccess();
        config(['ai.per_minute' => 1]);

        $this->postJson($this->url($project), $this->input())->assertOk();
        $this->postJson($this->url($sibling, 'client'), $this->input())->assertTooManyRequests()->assertJsonPath('code', 'ai_rate_limit')->assertHeader('Retry-After', '60');
        $this->travel(61)->seconds();
        $this->postJson($this->url($sibling, 'client'), $this->input())->assertOk();

        Http::assertSentCount(2);
    }

    public function test_daily_limit_is_isolated_by_workspace_and_resets_at_midnight_utc(): void
    {
        $this->travelTo(now('UTC')->setTime(23, 58));
        $workspace = Workspace::factory()->create();
        $first = Project::factory()->for($workspace)->create();
        $second = Project::factory()->for($workspace)->create();
        $other = Project::factory()->create();
        $this->admin($first);
        $this->fakeSuccess();
        config(['ai.daily_limit' => 2]);

        $this->postJson($this->url($first), $this->input())->assertOk();
        $this->postJson($this->url($second), $this->input())->assertOk();
        $this->postJson($this->url($first), $this->input())->assertTooManyRequests()->assertJsonPath('code', 'ai_daily_limit')->assertHeader('Retry-After', '120');
        $this->client($other);
        $this->postJson($this->url($other, 'client'), $this->input())->assertOk();
        $this->travel(2)->minutes();
        $this->postJson($this->url($first), $this->input())->assertOk();

        Http::assertSentCount(4);
    }

    public function test_failed_provider_attempts_consume_quota_but_invalid_input_does_not(): void
    {
        $project = Project::factory()->create();
        $this->admin($project);
        config(['ai.daily_limit' => 1]);
        Http::preventStrayRequests();
        Http::fake([self::URL => Http::response([], 500)]);

        $this->postJson($this->url($project), [...$this->input(), 'consent' => false])->assertUnprocessable();
        $this->postJson($this->url($project), $this->input())->assertServiceUnavailable();
        $this->postJson($this->url($project), $this->input())->assertTooManyRequests()->assertJsonPath('code', 'ai_daily_limit');

        Http::assertSentCount(1);
    }

    public function test_contended_quota_lock_returns_429_without_calling_provider(): void
    {
        $project = Project::factory()->create();
        $this->admin($project);
        $this->fakeSuccess();
        $lock = Cache::lock('ai:workspace:'.$project->workspace_id.':quota', 5);
        $lock->get();

        try {
            $this->postJson($this->url($project), $this->input())->assertTooManyRequests()->assertJsonPath('code', 'ai_rate_limit');
        } finally {
            $lock->release();
        }

        Http::assertNothingSent();
    }

    public function test_guests_cannot_call_either_endpoint_and_no_public_endpoint_exists(): void
    {
        $project = Project::factory()->create();
        $this->fakeSuccess();

        $this->postJson($this->url($project), $this->input())->assertUnauthorized();
        $this->postJson($this->url($project, 'client'), $this->input())->assertUnauthorized();
        $this->postJson('/api/ai/task-assistant', $this->input())->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_admins_cannot_reach_foreign_workspace_or_unassigned_projects(): void
    {
        $project = Project::factory()->create();
        $foreign = Project::factory()->create();
        $admin = User::factory()->memberOf($project->workspace)->create();
        $this->actingAs($admin);
        $this->fakeSuccess();

        $this->postJson($this->url($project), $this->input())->assertNotFound();
        $this->postJson($this->url($foreign), $this->input())->assertNotFound();
        $this->admin($project);
        $this->postJson($this->url($foreign), $this->input())->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_inactive_workspace_members_cannot_generate(): void
    {
        $project = Project::factory()->create();
        $this->admin($project);
        User::latest('id')->first()->workspaceMembers()->update(['status' => 'inactive']);
        $this->fakeSuccess();

        $this->postJson($this->url($project), $this->input())->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_client_project_scope_ignores_admin_workspace_selection(): void
    {
        $project = Project::factory()->create();
        $foreign = Project::factory()->create();
        $this->client($project);
        $this->withSession(['workspace_id' => $foreign->workspace_id]);
        $this->fakeSuccess();

        $this->postJson($this->url($foreign, 'client'), $this->input())->assertNotFound();
        $this->postJson($this->url($project), $this->input())->assertUnauthorized();
        $this->postJson($this->url($project, 'client'), $this->input())->assertOk();

        Http::assertSentCount(1);
    }

    public static function invalidTokens(): array
    {
        return ['expired' => [['expires_at' => '2000-01-01']], 'revoked' => [['revoked_at' => '2000-01-01']]];
    }

    #[DataProvider('invalidTokens')]
    public function test_invalid_client_tokens_return_401_without_external_request(array $fields): void
    {
        $project = Project::factory()->create();
        $this->client($project, $fields);
        $this->fakeSuccess();

        $this->postJson($this->url($project, 'client'), $this->input())->assertUnauthorized();

        Http::assertNothingSent();
    }
}
