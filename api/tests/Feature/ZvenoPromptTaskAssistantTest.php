<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

class ZvenoPromptTaskAssistantTest extends AiTaskAssistantTest
{
    protected const PROVIDER = 'zveno';

    protected const MODEL = 'nvidia/nemotron-3-super-120b-a12b:free';

    protected const OUTPUT_MODE = 'prompt_json';

    protected const URL = 'https://api.zveno.ai/v1/chat/completions';

    public static function invalidPromptResponses(): array
    {
        $json = json_encode(['title' => 'Название', 'description' => 'Описание', 'expectedResult' => '', 'questions' => [], 'suggestedIdeas' => []]);

        return [
            'markdown fences' => ["```json\n".$json."\n```"],
            'commentary' => ['Here is the result: '.$json],
            'reasoning in content' => ['<think>Thinking</think>'.$json],
            'two objects' => [$json.$json],
        ];
    }

    #[DataProvider('invalidPromptResponses')]
    public function test_prompt_mode_rejects_wrappers_and_commentary_without_extracting_or_retrying(string $content): void
    {
        $project = Project::factory()->create();
        $this->actingAs(User::factory()->owner($project->workspace)->create());
        Http::preventStrayRequests();
        Http::fake([self::URL => Http::sequence()
            ->push(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => $content]]]])
            ->push(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => '{}']]]])]);

        $this->postJson('/api/admin/projects/'.$project->uuid.'/ai/task-assistant', ['description' => 'Крупнее фото.', 'consent' => true])
            ->assertStatus(502)->assertJsonPath('code', 'ai_invalid_response');

        Http::assertSentCount(1);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_unknown_output_mode_fails_before_provider_request_and_does_not_consume_quota(): void
    {
        $project = Project::factory()->create();
        $this->actingAs(User::factory()->owner($project->workspace)->create());
        Http::preventStrayRequests();
        Http::fake([self::URL => Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode(['title' => 'Фото', 'description' => 'Крупнее фото.', 'expectedResult' => '', 'questions' => [], 'suggestedIdeas' => []])]]]])]);
        config(['ai.daily_limit' => 1, 'ai.providers.zveno.output_mode' => 'automatic']);

        $url = '/api/admin/projects/'.$project->uuid.'/ai/task-assistant';
        $input = ['description' => 'Крупнее фото.', 'consent' => true];
        $this->postJson($url, $input)->assertServiceUnavailable()->assertJsonPath('code', 'ai_unavailable');
        Http::assertNothingSent();
        config(['ai.providers.zveno.output_mode' => 'prompt_json']);
        $this->postJson($url, $input)->assertOk();

        Http::assertSentCount(1);
    }

    public function test_groq_ignores_zveno_prompt_setting_and_keeps_its_original_request(): void
    {
        $project = Project::factory()->create();
        $this->actingAs(User::factory()->owner($project->workspace)->create());
        config(['ai.provider' => 'groq', 'ai.providers.groq.key' => 'groq-test', 'ai.providers.groq.model' => 'openai/gpt-oss-120b']);
        Http::preventStrayRequests();
        Http::fake(['https://api.groq.com/openai/v1/chat/completions' => Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode(['title' => 'Фото', 'description' => 'Крупнее фото.', 'expectedResult' => '', 'questions' => [], 'suggestedIdeas' => []])]]]])]);

        $this->postJson('/api/admin/projects/'.$project->uuid.'/ai/task-assistant', ['description' => 'Крупнее фото.', 'consent' => true])->assertOk();

        Http::assertSent(function (Request $request): bool {
            $this->assertSame('json_schema', $request['response_format']['type']);
            $this->assertTrue($request['response_format']['json_schema']['strict']);
            $this->assertSame('low', $request['reasoning_effort']);
            $this->assertSame(6000, $request['max_completion_tokens']);
            $this->assertFalse($request['stream']);
            $this->assertArrayNotHasKey('provider', $request->data());
            $this->assertStringNotContainsString('Return exactly one JSON object conforming', $request['messages'][0]['content']);

            return true;
        });
        Http::assertSentCount(1);
    }
}
