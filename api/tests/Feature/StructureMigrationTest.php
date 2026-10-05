<?php

namespace Tests\Feature;

use App\Services\LegacyProjectStructure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class StructureMigrationTest extends TestCase
{
    public function test_existing_schema_is_upgraded_without_losing_content_and_backfill_is_repeatable(): void
    {
        $original = config('database.default');
        config(['database.connections.legacy_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true], 'database.default' => 'legacy_fixture']);
        Schema::clearResolvedInstance('db.schema');
        try {
            (require database_path('migrations/0001_01_01_000000_create_users_table.php'))->up();
            (require database_path('migrations/2026_10_05_000001_create_brief_tables.php'))->up();
            $project = DB::table('projects')->insertGetId(['uuid' => Str::uuid(), 'title' => 'Existing', 'client_name' => 'John', 'client_email' => 'john@example.com', 'sections' => '[]', 'created_at' => now(), 'updated_at' => now()]);
            $token = DB::table('project_access_tokens')->insertGetId(['project_id' => $project, 'token_hash' => hash('sha256', 'legacy'), 'last_used_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            foreach (['Главная', 'Главная', 'Каталог', null] as $index => $section) {
                DB::table('tasks')->insert(['uuid' => Str::uuid(), 'project_id' => $project, 'title' => 'Task '.$index, 'description' => 'Original description', 'section' => $section, 'location' => 'https://example.com/not-a-section/'.$index, 'status' => $index === 0 ? 'done' : 'new', 'client_approved' => true, 'client_approved_at' => now(), 'developer_notes' => 'Keep secret', 'created_at' => now(), 'updated_at' => now()]);
            }
            $task = DB::table('tasks')->first()->id;
            DB::table('comments')->insert(['uuid' => Str::uuid(), 'task_id' => $task, 'author_type' => 'client', 'text' => 'Old author unknown', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('task_history')->insert(['task_id' => $task, 'actor_type' => 'client', 'event_type' => 'created', 'meta' => '{"text":"Original history"}', 'created_at' => now()]);
            DB::table('attachments')->insert(['uuid' => Str::uuid(), 'task_id' => $task, 'uploaded_by_type' => 'client', 'original_name' => 'original.txt', 'stored_name' => 'stored.txt', 'mime_type' => 'text/plain', 'size' => 5, 'disk' => 'local', 'path' => 'private/original', 'created_at' => now(), 'updated_at' => now()]);
            $before = [];
            foreach (['projects', 'tasks', 'comments', 'task_history', 'attachments', 'project_access_tokens'] as $table) {
                $before[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
            }
            $migration = require database_path('migrations/2026_10_05_204121_add_project_structure_and_client_identity.php');
            $migration->up();
            LegacyProjectStructure::run();
            $this->assertSame(['Главная', 'Каталог', 'Общее'], DB::table('project_sections')->orderBy('position')->pluck('name')->all());
            $this->assertSame([1, 2, 1, 1], DB::table('tasks')->orderBy('id')->pluck('position')->all());
            $client = DB::table('project_clients')->first();
            $this->assertSame('John', $client->name);
            $this->assertSame('john@example.com', $client->email);
            $this->assertSame($client->id, DB::table('project_access_tokens')->where('id', $token)->value('project_client_id'));
            foreach ($before as $table => $rows) {
                $after = DB::table($table)->orderBy('id')->get()->map(function ($row) {
                    $row = (array) $row;
                    unset($row['project_section_id'], $row['position'], $row['project_client_id']);

                    return $row;
                })->all();
                $this->assertSame($rows, $after, $table.' legacy values must stay unchanged');
            }
            $snapshot = DB::table('tasks')->orderBy('id')->get()->toJson();
            $migration->up();
            LegacyProjectStructure::run();
            $this->assertSame($snapshot, DB::table('tasks')->orderBy('id')->get()->toJson());
            $this->assertSame(3, DB::table('project_sections')->count());
            $this->assertSame(1, DB::table('project_clients')->count());
            $this->assertNull(DB::table('comments')->value('project_client_id'));
            $this->assertNull(DB::table('attachments')->value('project_client_id'));
            $this->assertNull(DB::table('task_history')->value('project_client_id'));
            $anonymous = DB::table('projects')->insertGetId(['uuid' => Str::uuid(), 'title' => 'Has old link only']);
            DB::table('project_access_tokens')->insert(['project_id' => $anonymous, 'token_hash' => hash('sha256', 'anonymous')]);
            $empty = DB::table('projects')->insertGetId(['uuid' => Str::uuid(), 'title' => 'Empty']);
            LegacyProjectStructure::run();
            $this->assertSame('Клиент', DB::table('project_clients')->where('project_id', $anonymous)->value('name'));
            $this->assertFalse(DB::table('project_clients')->where('project_id', $empty)->exists());
        } finally {
            config(['database.default' => $original]);
            DB::purge('legacy_fixture');
            Schema::clearResolvedInstance('db.schema');
        }
    }
}
