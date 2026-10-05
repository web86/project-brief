<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LegacyProjectStructure
{
    public static function run(): void
    {
        DB::table('projects')->orderBy('id')->chunkById(100, function ($projects): void {
            foreach ($projects as $project) {
                DB::transaction(function () use ($project): void {
                    DB::table('projects')->where('id', $project->id)->lockForUpdate()->first();
                    $sections = DB::table('project_sections')->where('project_id', $project->id)->orderBy('position')->orderBy('id')->get();
                    $byName = [];
                    $position = $sections->max('position') ?? 0;
                    foreach ($sections as $section) {
                        $byName[$section->name] ??= $section->id;
                    }
                    DB::table('tasks')->where('project_id', $project->id)->whereNull('project_section_id')->orderBy('id')->chunkById(200, function ($tasks) use ($project, &$byName, &$position): void {
                        foreach ($tasks as $task) {
                            // Location is intentionally never used to build the brief structure.
                            $name = trim($task->section ?? '') ?: 'Общее';
                            if (! isset($byName[$name])) {
                                $byName[$name] = DB::table('project_sections')->insertGetId(['uuid' => (string) Str::uuid(), 'project_id' => $project->id, 'name' => $name, 'position' => ++$position, 'created_at' => now(), 'updated_at' => now()]);
                            }
                            $sectionId = $byName[$name];
                            $next = (DB::table('tasks')->where('project_section_id', $sectionId)->max('position') ?? 0) + 1;
                            DB::table('tasks')->where('id', $task->id)->update(['project_section_id' => $sectionId, 'position' => $next]);
                        }
                    });
                    // Keep configured names for empty projects; tasks always determine legacy order first.
                    $configured = json_decode($project->sections ?? '[]', true);
                    foreach (is_array($configured) ? $configured : [] as $name) {
                        if (! is_string($name) || trim($name) === '' || isset($byName[trim($name)])) {
                            continue;
                        }
                        $name = trim($name);
                        $byName[$name] = DB::table('project_sections')->insertGetId(['uuid' => (string) Str::uuid(), 'project_id' => $project->id, 'name' => $name, 'position' => ++$position, 'created_at' => now(), 'updated_at' => now()]);
                    }
                    $client = DB::table('project_clients')->where('project_id', $project->id)->orderBy('id')->first();
                    if (! $client && ($project->client_name || $project->client_email || DB::table('project_access_tokens')->where('project_id', $project->id)->exists())) {
                        $id = DB::table('project_clients')->insertGetId(['uuid' => (string) Str::uuid(), 'project_id' => $project->id, 'name' => trim($project->client_name ?? '') ?: 'Клиент', 'email' => $project->client_email, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
                    } else {
                        $id = $client?->id;
                    }
                    if ($id) {
                        DB::table('project_access_tokens')->where('project_id', $project->id)->whereNull('project_client_id')->update(['project_client_id' => $id]);
                    }
                }, 3);
            }
        });
    }
}
