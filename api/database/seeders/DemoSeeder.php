<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Demo seeding is only available locally.');
        }
        $password = env('DEMO_ADMIN_PASSWORD');
        if ($password) {
            User::firstOrCreate(['email' => 'admin@example.test'], ['name' => 'Администратор', 'password' => Hash::make($password)]);
        }
        if (Project::exists()) {
            return;
        }
        $demo = json_decode(file_get_contents(__DIR__.'/demo.json'), true, 512, JSON_THROW_ON_ERROR);
        $project = Project::create(['title' => $demo['project']['name'], 'website_url' => 'https://example.com', 'currency' => $demo['project']['currency'] ?? 'RUB', 'client_name' => 'Демо-клиент', 'sections' => $demo['sections']]);
        foreach ($demo['tasks'] as $item) {
            $task = $project->tasks()->create(['title' => $item['title'], 'location' => $item['location'] ?? $item['section'], 'section' => $item['location'] ?? $item['section'],
                'description' => $item['description'], 'expected_result' => $item['expectedResult'], 'priority' => $item['priority'], 'status' => $item['status'], 'client_approved' => $item['clientApproved'],
                'client_approved_at' => $item['clientApproved'] ? now() : null, 'estimate_hours' => $item['estimateHours'], 'price' => $item['price'], 'developer_notes' => $item['developerNotes']]);
            $task->history()->create(['actor_type' => 'system', 'event_type' => 'created', 'meta' => ['text' => 'Добавлена демонстрационная идея'], 'created_at' => now()]);
            foreach ($item['comments'] as $comment) {
                $task->comments()->create(['author_type' => $comment['author'] === 'developer' ? 'admin' : 'client', 'text' => $comment['text']]);
            }
        }
    }
}
