<?php

use App\Models\Comment;
use App\Models\DeveloperInvitation;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'sqlite' || ! str_contains(config('database.connections.sqlite.database'), 'milestone60-e2e-')) {
    throw new RuntimeException('Disposable Milestone 6.0 SQLite fixture required.');
}
$workspace = Workspace::factory()->create(['name' => 'Web86 Studio']);
$owner = User::factory()->owner($workspace)->create(['name' => 'ipek ışık', 'email' => 'owner@example.test', 'password' => 'Local-e2e-password!', 'preferred_locale' => null]);
$developer = User::factory()->memberOf($workspace)->create(['name' => 'Özgür Şule', 'email' => 'developer@example.test', 'password' => 'Local-e2e-password!', 'preferred_locale' => 'tr']);
$project = Project::factory()->for($workspace)->create(['title' => 'Çığ — Website / исходный проект', 'currency' => 'USD', 'sections' => ['Ana sayfa', 'Ürünler', 'İletişim']]);
$project->members()->create(['workspace_id' => $workspace->id, 'workspace_member_id' => $developer->workspaceMembers()->first()->id]);
$task = Task::factory()->for($project)->create(['project_section_id' => $project->briefSections()->first()->id, 'position' => 1, 'location' => 'Ürünler / исходный адрес', 'title' => 'İpek için ürün fotoğrafları — исходный текст', 'description' => 'Çığ, ışık, İpek, Özgür, Şule: ç ğ ı İ ö ş ü. Описание клиента остаётся оригинальным. 这是客户输入的原始需求，请保留原文，不要自动翻译。这是客户输入的原始需求，请保留原文，不要自动翻译。这是客户输入的原始需求，请保留原文，不要自动翻译。这是客户输入的原始需求，请保留原文，不要自动翻译。这是客户输入的原始需求，请保留原文，不要自动翻译。', 'expected_result' => 'Görselleri tam boyutta açın.', 'status' => 'new', 'estimate_hours' => '1234.50', 'price' => '1234567.89', 'developer_notes' => 'Teknik notlar — özgün içerik']);
$task->history()->create(['actor_type' => 'client', 'event_type' => 'created', 'meta' => [], 'created_at' => now()]);
Comment::create(['project_id' => $project->id, 'author_type' => 'admin', 'author_user_id' => $owner->id, 'text' => 'Yorum: ç ğ ı İ ö ş ü. Исходный комментарий.']);
Comment::create(['project_id' => $project->id, 'author_type' => 'admin', 'text' => 'Legacy comment without a named author']);
$client = $project->clients()->first();
$client->update(['name' => 'Şule Özgür', 'email' => 'client@example.test', 'preferred_locale' => null]);
$token = bin2hex(random_bytes(32));
$project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', $token)]);
$invitationToken = bin2hex(random_bytes(32));
DeveloperInvitation::create(['workspace_id' => $workspace->id, 'invited_by' => $owner->id, 'email' => 'new@example.test', 'project_ids' => [$project->id], 'locale' => 'tr', 'token_hash' => hash('sha256', $invitationToken), 'expires_at' => now()->addDays(7)]);
echo json_encode(['project' => $project->uuid, 'task' => $task->uuid, 'token' => $token, 'invitation' => $invitationToken]);
