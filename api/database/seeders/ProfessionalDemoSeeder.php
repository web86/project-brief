<?php

namespace Database\Seeders;

use App\Models\Comment;
use App\Models\ExternalAgent;
use App\Models\Project;
use App\Models\ProjectFeedback;
use App\Models\ProjectRequest;
use App\Models\Task;
use App\Models\TaskAgentAssignment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentPermissions;
use App\Services\DemoEnvironment;
use App\Services\ProjectPermissions;
use App\Services\ProjectStructure;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Ramsey\Uuid\Uuid;

/** Synthetic, terminal integration history. No services that dispatch network work are called. */
class ProfessionalDemoSeeder extends Seeder
{
    public static function uuid(string $key): string
    {
        return (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'https://harborline.test/demo/v1/'.$key);
    }

    public function run(): void
    {
        DemoEnvironment::assertIsolated();
        if (User::exists() || Workspace::exists() || Project::withTrashed()->exists()) {
            throw new \RuntimeException('Professional demo requires an empty database. Use the guarded demo:reset command.');
        }
        if (! config('demo.password')) {
            throw new \RuntimeException('Set a private DEMO_ADMIN_PASSWORD before creating the demo.');
        }
        Carbon::setTestNow(Carbon::parse('2026-10-30 16:00:00', 'UTC'));
        try {
            DB::transaction(fn () => $this->dataset());
        } finally {
            Carbon::setTestNow();
        }
    }

    private function dataset(): void
    {
        $workspace = Workspace::create(['uuid' => self::uuid('workspace'), 'name' => 'Harborline Digital Studio']);
        $workspace->forceFill(['uuid' => self::uuid('workspace'), 'branding' => $this->branding()])->save();
        $people = [
            ['Morgan Ellis', 'morgan', 'Studio owner & creative director'],
            ['Avery Chen', 'avery', 'Project manager'],
            ['Jamie Brooks', 'jamie', 'Frontend developer'],
            ['Riley Bennett', 'riley', 'Backend developer'],
            ['Alex Rivera', 'alex', 'UX designer'],
            ['Jordan Quinn', 'jordan', 'QA & accessibility engineer'],
            ['Taylor Reed', 'taylor', 'Integration specialist'],
            ['Casey Parker', 'casey', 'Content & CMS developer'],
        ];
        $members = [];
        $users = [];
        foreach ($people as $index => [$name, $handle, $role]) {
            $user = User::create(['name' => $name, 'email' => $handle.'@harborline.test', 'password' => config('demo.password'), 'preferred_locale' => 'en']);
            $user->forceFill(['security_recommendations_seen_at' => now(), 'notification_preferences' => ['email' => false, 'push' => true, 'telegram' => false], 'email_verified_at' => now()])->save();
            if ($index === 0) {
                $user->forceFill(['two_factor_secret' => Fortify::currentEncrypter()->encrypt(env('DEMO_OWNER_TOTP_SECRET') ?: app(Google2FA::class)->generateSecretKey(32)), 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(array_map(fn () => bin2hex(random_bytes(12)), range(1, 8)))), 'recovery_codes_acknowledged_at' => now()])->save();
            }
            $members[] = $workspace->members()->create(['user_id' => $user->id, 'role' => $index === 0 ? 'owner' : 'admin', 'status' => 'active', 'joined_at' => now()->subDays(90), 'is_project_superadmin' => $index === 1]);
            $users[] = $user;
        }
        $definitions = json_decode(file_get_contents(__DIR__.'/professional-demo.json'), true, flags: JSON_THROW_ON_ERROR);
        $clientNames = ['Quinn Foster', 'Emery Lawson', 'Rowan Blake', 'Reese Morgan', 'Cameron Vale', 'Skyler Hayes', 'Finley Harper', 'Drew Sullivan', 'Sage Collins', 'Peyton Lane', 'Arden Walsh', 'River Monroe', 'Blair Sutton', 'Kendall Avery', 'Elliot Grey', 'Dakota Bell', 'Robin Hale', 'Shiloh Wells', 'Remy West', 'Charlie Linden'];
        $reviews = [
            'The new collection pages finally reflect the quality of our products. We launched on the agreed date, and the editor handover was practical enough for our small team to use straight away.',
            'Harborline made the review process straightforward. Every decision had context, the weekly updates were clear, and the finished site feels considered on a phone as well as a large screen.',
            'We particularly valued the care around accessibility and content structure. Our team can now publish new articles without asking a developer to repair the layout.',
            'The project stayed focused from discovery to launch. A couple of content decisions took longer on our side, but the team made the impact visible early and kept the handover calm.',
            'The membership comparison is much easier to explain to new visitors. The team listened closely to our questions and gave us a site that our staff feel confident maintaining.',
            'Clear estimates, thoughtful design and a very organised acceptance review. The timetable on mobile is a particular improvement over our previous experience.',
            'We came with a complicated set of services and left with a much clearer structure. The work on plain-language navigation was as valuable as the visual design.',
            'Communication was consistent throughout. The final checklist caught the small details, and the documentation has made our first month after launch much easier.',
        ];
        $projects = [];
        $tasksByProject = [];
        foreach ($definitions as $i => $definition) {
            $start = now()->subDays(80 - $i * 4);
            $project = $workspace->projects()->create(['title' => $definition['name'], 'description' => $definition['description'].' '.$definition['goal'], 'website_url' => 'https://'.strtolower(explode(' ', $definition['name'])[0]).'.example.test', 'currency' => 'USD', 'language' => 'en', 'status' => $i === 9 ? 'inactive' : 'active', 'created_at' => $start, 'updated_at' => now()->subHours($i * 7)]);
            $project->forceFill(['uuid' => self::uuid('project/'.$i), 'created_at' => $start, 'updated_at' => now()->subHours($i * 7)])->save();
            $projects[] = $project;
            $sections = [];
            foreach (['Discovery & design', 'Core experience', 'Delivery & quality'] as $j => $title) {
                $section = $project->briefSections()->create(['name' => $title, 'position' => $i === 0 ? [2, 1, 3][$j] : $j + 1]);
                $section->forceFill(['uuid' => self::uuid('section/'.$i.'/'.$j), 'created_at' => $start, 'updated_at' => $start])->save();
                $sections[] = $section;
            }
            foreach (array_unique([1, 2 + $i % 3, 4 + $i % 4]) as $m) {
                $project->members()->create(['workspace_id' => $workspace->id, 'workspace_member_id' => $members[$m]->id, 'permissions' => $m === 1 ? array_fill_keys(array_keys(ProjectPermissions::DEFAULTS), true) : ProjectPermissions::DEFAULTS]);
            }
            $clients = [];
            for ($c = 0; $c < 2; $c++) {
                $number = $i * 2 + $c;
                $email = strtolower(str_replace(' ', '.', $clientNames[$number])).'@client.example.test';
                $directory = DB::table('workspace_clients')->insertGetId(['uuid' => self::uuid('client/'.$number), 'workspace_id' => $workspace->id, 'name' => $clientNames[$number], 'email' => $email, 'email_hash' => hash('sha256', $email), 'company' => $definition['name'], 'website' => $project->website_url, 'created_at' => $start, 'updated_at' => $start]);
                $client = $project->clients()->create(['name' => $clientNames[$number], 'email' => $email, 'active' => true, 'preferred_locale' => 'en', 'workspace_client_id' => $directory, 'notification_preferences' => ['email' => false, 'push' => true, 'telegram' => false]]);
                $client->forceFill(['uuid' => self::uuid('project-client/'.$number)])->save();
                $clients[] = $client;
                // Unknown random preimage, never exposed as a signed access URL.
                $project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', random_bytes(64))]);
            }
            $tasks = [];
            foreach ($definition['tasks'] as $j => $item) {
                $status = $this->status($i, $j);
                $approved = ! in_array($status, ['new', 'clarification'], true);
                $section = $sections[$j < 2 ? 0 : ($j < 8 ? 1 : 2)];
                $created = $start->copy()->addHours($j * 3);
                $task = $project->tasks()->create(['project_section_id' => $section->id, 'position' => $j + 1, 'section' => $section->name, 'location' => $section->name, 'title' => $item['title'], 'description' => $item['description'], 'expected_result' => $item['expectedResult'], 'developer_notes' => $item['developerNotes'], 'estimate_hours' => [4, 8, 12, 16, 24][$j % 5], 'price' => [440, 880, 1320, 1760, 2640][$j % 5], 'priority' => $j % 5 === 0 ? 'high' : ($j % 7 === 0 ? 'low' : 'normal'), 'status' => $status, 'client_approved' => $approved, 'client_approved_at' => $approved ? $created->copy()->addDays(2) : null, 'created_at' => $created, 'updated_at' => $created->copy()->addDays(8)]);
                $task->forceFill(['uuid' => self::uuid('task/'.$i.'/'.$j), 'created_at' => $created, 'updated_at' => $created->copy()->addDays(8)])->save();
                $tasks[] = $task;
                $task->history()->create(['event_type' => 'created', 'actor_type' => 'client', 'project_client_id' => $clients[0]->id, 'meta' => ['text' => 'Client proposed the task.'], 'created_at' => $created]);
                $chain = $status === 'clarification' ? ['clarification'] : ($status === 'new' ? [] : array_slice(['approved', 'in_progress', 'review', 'done'], 0, array_search($status, ['approved', 'in_progress', 'review', 'done'], true) + 1));
                $previous = 'new';
                foreach ($chain as $step => $state) {
                    $clientAction = in_array($state, ['approved', 'done'], true);
                    $task->history()->create(['event_type' => $state === 'approved' ? 'approved' : 'status', 'actor_type' => $clientAction ? 'client' : 'admin', 'actor_user_id' => $clientAction ? null : $users[1]->id, 'project_client_id' => $clientAction ? $clients[0]->id : null, 'old_value' => $previous, 'new_value' => $state, 'meta' => ['text' => 'Status changed from '.$previous.' to '.$state.'.'], 'created_at' => $created->copy()->addDays(2 + $step)]);
                    $previous = $state;
                }
                foreach ([['client', 'Could we confirm the mobile behaviour before the next review? The primary action should remain easy to find on a small screen.'], ['admin', 'Yes. The responsive states and keyboard interaction are included in the review checklist. We will attach the handoff notes here.']] as $n => [$author, $text]) {
                    $comment = $task->comments()->create(['project_id' => $project->id, 'author_type' => $author, 'author_user_id' => $author === 'admin' ? $users[2]->id : null, 'project_client_id' => $author === 'client' ? $clients[0]->id : null, 'text' => $j % 3 === 0 ? $text : ($n === 0 ? 'The scope for '.$item['title'].' looks right. Please flag any content decisions we need to make before acceptance.' : 'The next review will cover '.$item['title'].'. We have recorded the edge cases and will share the verification notes with the team.'), 'created_at' => $created->copy()->addDays(6)->addHours($n), 'updated_at' => $created->copy()->addDays(6)->addHours($n)]);
                    $comment->forceFill(['created_at' => $created->copy()->addDays(6)->addHours($n), 'updated_at' => $created->copy()->addDays(6)->addHours($n)])->saveQuietly();
                    $task->history()->create(['actor_type' => $author, 'actor_user_id' => $comment->author_user_id, 'project_client_id' => $comment->project_client_id, 'event_type' => 'comment', 'meta' => ['commentId' => $comment->id], 'created_at' => $comment->created_at]);
                }
                if ($j === 2 || $j === 9) {
                    $this->attachment($task, $definition['name'], $j);
                }
            }
            ProjectStructure::normalizeProject($project);
            $tasksByProject[] = $tasks;
            $discussion = ['We have reviewed the discovery notes and would like the first release to focus on the primary customer journey. The additional content modules can follow after launch.', 'That matches the proposed scope. We will keep the weekly review focused on the next decision and record any changes to the acceptance criteria here.', 'For the visual direction, please keep the interface calm and readable. Our editors need enough flexibility to add new content without breaking the layout.', 'The component library includes a small set of reusable page sections. We will demonstrate the editing workflow during the next walkthrough.', 'Could we move the content review earlier? We have two people available to review the first batch this week.', 'Yes. We have split the review into two batches and added a checklist for headings, images, metadata and links.', 'The first batch is approved. We have left a few wording notes on the remaining pages and will send the final copy before the next milestone.', 'Thank you. We will apply the wording notes and include the responsive and accessibility results in the handover.', 'The updated direction is much clearer. The primary action is easier to find and the mobile layout has the right balance.', 'We will use this thread as the acceptance record. Any outstanding items will have a named owner and an agreed review date.'];
            foreach (array_slice($discussion, 0, $i < 4 ? 10 : 6) as $d => $text) {
                $entry = Comment::create(['project_id' => $project->id, 'author_type' => $d % 2 ? 'admin' : 'client', 'author_user_id' => $d % 2 ? $users[1]->id : null, 'project_client_id' => $d % 2 ? null : $clients[$d % 4 === 0 ? 0 : 1]->id, 'text' => $text, 'created_at' => $start->copy()->addDays($d + 4), 'updated_at' => $start->copy()->addDays($d + 4)]);
                $entry->forceFill(['created_at' => $start->copy()->addDays($d + 4), 'updated_at' => $start->copy()->addDays($d + 4)])->saveQuietly();
            }
            foreach (['Internal review: keep the approved scope visible and collect content decisions before the next client call.', 'QA has checked keyboard navigation and mobile form errors. The remaining findings are attached to the relevant tasks.', 'Please avoid publishing implementation notes in client discussions. Share only the reviewed handoff summary.'] as $d => $text) {
                $entry = Comment::create(['project_id' => $project->id, 'team_only' => true, 'author_type' => 'admin', 'author_user_id' => $users[$d + 1]->id, 'text' => $text, 'created_at' => $start->copy()->addDays($d + 10), 'updated_at' => $start->copy()->addDays($d + 10)]);
                $entry->forceFill(['created_at' => $start->copy()->addDays($d + 10), 'updated_at' => $start->copy()->addDays($d + 10)])->saveQuietly();
            }
            if (in_array($i, [3, 6, 9], true)) {
                foreach ($clients as $c => $client) {
                    ProjectFeedback::create(['workspace_id' => $workspace->id, 'project_id' => $project->id, 'project_client_id' => $client->id, 'rating' => $i === 6 && $c === 1 ? 4 : 5, 'text' => $reviews[($i === 3 ? 0 : ($i === 6 ? 2 : 4)) + $c], 'requested_at' => now()->subDays(12), 'shown_at' => now()->subDays(11), 'submitted_at' => now()->subDays(10 - $c)]);
                }
            }
        }
        // Two further reviewers on completed projects; distinct identities, not duplicate testimonials.
        foreach ([3, 6] as $r => $index) {
            $project = $projects[$index];
            $client = $project->clients()->create(['name' => ['Emerson Ash', 'Noel Rowan'][$r], 'email' => ['emerson.ash', 'noel.rowan'][$r].'@client.example.test', 'active' => true, 'preferred_locale' => 'en']);
            ProjectFeedback::create(['workspace_id' => $workspace->id, 'project_id' => $project->id, 'project_client_id' => $client->id, 'rating' => 5, 'text' => $reviews[6 + $r], 'requested_at' => now()->subDays(8), 'submitted_at' => now()->subDays(7)]);
        }
        $this->integrations($workspace, $projects, $tasksByProject, $users);
        // Browsing starts with a realistic spread of activity dates, independent of insertion order.
        foreach ($projects as $i => $project) {
            $project->forceFill(['updated_at' => now()->subHours($i * 7)])->saveQuietly();
        }
    }

    private function status(int $project, int $task): string
    {
        if (in_array($project, [3, 6, 9], true)) {
            return 'done';
        }
        $stages = [0 => ['done', 'done', 'review', 'in_progress', 'approved'], 1 => ['approved', 'new', 'clarification'], 2 => ['done', 'in_progress', 'in_progress', 'review', 'approved'], 4 => ['done', 'review', 'review', 'approved'], 5 => ['approved', 'approved', 'in_progress', 'new'], 7 => ['done', 'in_progress', 'review', 'approved'], 8 => ['new', 'clarification', 'approved']];

        return $stages[$project][$task % count($stages[$project])];
    }

    public function branding(): array
    {
        $assets = [];
        foreach (['logo', 'favicon'] as $kind) {
            $token = substr(hash('sha256', 'harborline/dev2done-v2/'.$kind), 0, 48);
            $svg = file_get_contents(resource_path('dev2done-mark.svg'));
            $png = file_get_contents(resource_path('dev2done-mark.png'));
            $path = 'demo/branding/'.$kind;
            Storage::disk('local')->put($path.'.svg', $svg);
            Storage::disk('local')->put($path.'.png', $png);
            $assets[$kind] = ['path' => $path.'.png', 'svgPath' => $path.'.svg', 'token' => $token, 'extension' => 'png', 'mime' => 'image/png', 'bytes' => strlen($png), 'browserBytes' => strlen($svg), 'originalBytes' => strlen($svg)];
        }

        return ['visual' => ['companyName' => 'Harborline Digital Studio', 'primaryColor' => '#5b4bd6'], 'contact' => ['email' => 'studio@harborline.test', 'website' => 'https://harborline.test', 'extra' => 'Independent design and engineering for thoughtful digital experiences.'], 'business' => ['legalName' => 'Harborline Digital Studio — fictional demo company', 'note' => 'Synthetic demonstration. No legal or payment details.'], 'assets' => $assets, 'email' => ['enabled' => true, 'logo' => true, 'companyName' => true, 'contact' => true, 'accent' => true], 'footer' => ['support' => false, 'website' => false]];
    }

    private function attachment(Task $task, string $project, int $index): void
    {
        $name = $index === 2 ? 'Experience-handoff.txt' : 'Accessibility-review.txt';
        $text = $project."\n".$task->title."\n\nReview notes\n- Responsive layouts: 375 / 768 / 1440 px\n- Focus order and validation messages checked\n- Content decisions recorded in the task discussion\n\nPrepared by Harborline Digital Studio. Synthetic demonstration file.\n";
        $path = 'demo/attachments/'.$task->uuid.'.txt';
        Storage::disk('local')->put($path, $text);
        $attachment = $task->attachments()->create(['uuid' => self::uuid('attachment/'.$task->uuid), 'uploaded_by_type' => 'admin', 'original_name' => $name, 'stored_name' => basename($path), 'mime_type' => 'text/plain', 'size' => strlen($text), 'disk' => 'local', 'path' => $path]);
        $attachment->forceFill(['uuid' => self::uuid('attachment/'.$task->uuid)])->saveQuietly();
    }

    private function integrations(Workspace $workspace, array $projects, array $tasks, array $users): void
    {
        $sources = [];
        $credentials = [];
        foreach (['Studio enquiry desk', 'Client portal preview', 'Delivery workspace'] as $i => $name) {
            $source = DB::table('integration_sources')->insertGetId(['uuid' => self::uuid('source/'.$i), 'workspace_id' => $workspace->id, 'name' => $name, 'sso_enabled' => false, 'sso_origin' => 'https://portal.example.test', 'created_at' => now()->subDays(30), 'updated_at' => now()]);
            $sources[] = $source;
            $credentials[] = DB::table('integration_tokens')->insertGetId(['uuid' => self::uuid('credential/'.$i), 'workspace_id' => $workspace->id, 'integration_source_id' => $source, 'name' => ['Enquiry intake · demo', 'Client SSO · demo', 'Read-only reporting · demo'][$i], 'secret_hash' => hash('sha256', random_bytes(64)), 'scopes' => json_encode([['requests:create', 'requests:read'], ['clients:sso'], ['projects:read', 'comments:read']][$i]), 'expires_at' => '2000-01-01 00:00:00', 'last_used_at' => now()->subHours(8 + $i), 'created_at' => now()->subDays(30), 'updated_at' => now()]);
            foreach (array_slice($projects, $i * 2, 3) as $project) {
                DB::table('integration_source_projects')->insert(['workspace_id' => $workspace->id, 'integration_source_id' => $source, 'project_id' => $project->id]);
            }
        }
        $requestNames = ['Cedar Market storefront', 'Brightwell clinic refresh', 'Fieldwork careers portal', 'Juniper product launch', 'Coastline events website', 'Noble Studio portfolio', 'Meadow learning hub', 'Orchard wholesale portal', 'Lantern booking flow', 'Stonebridge services site', 'Bloom membership area', 'Foundry reporting dashboard', 'Solace guest experience', 'Fern editorial platform'];
        foreach ($requestNames as $i => $title) {
            $state = ['pending', 'pending', 'approved', 'rejected'][$i % 4];
            $result = null;
            $provisioning = null;
            if ($state === 'approved') {
                $target = $projects[$i % 10];
                $title = $target->title;
                $client = $target->clients()->first();
                $operation = self::uuid('provisioning/'.$i);
                $provisioning = DB::table('project_provisionings')->insertGetId(['uuid' => $operation, 'workspace_id' => $workspace->id, 'integration_token_id' => $credentials[$i % 3], 'external_hash' => hash('sha256', 'request-project/'.$i), 'body_hash' => hash('sha256', $title), 'workspace_client_id' => $client->workspace_client_id, 'project_id' => $target->id, 'project_client_id' => $client->id, 'invitation_status' => 'not_requested', 'created_at' => now()->subDays($i + 1), 'updated_at' => now()->subDays($i + 1)]);
                $result = ['operationId' => $operation, 'projectId' => $target->uuid, 'clientId' => $client->uuid, 'projectUrl' => '/admin/projects/'.$target->uuid.'/brief', 'invitation' => 'not_requested'];
            }
            $request = ProjectRequest::create(['uuid' => self::uuid('request/'.$i), 'workspace_id' => $workspace->id, 'integration_source_id' => $sources[$i % 3], 'integration_token_id' => $credentials[$i % 3], 'external_id' => 'DEMO-'.(1000 + $i), 'external_hash' => hash('sha256', 'demo-request/'.$i), 'body_hash' => hash('sha256', $title), 'client_name' => ['Ellis Ward', 'Marin Clarke', 'Sydney Rowan', 'Aubrey Finch'][$i % 4], 'email' => 'enquiry'.($i + 1).'@client.example.test', 'company' => explode(' ', $title)[0].' Collective', 'website' => 'https://brief.example.test', 'project_name' => $title, 'description' => 'We are looking for a focused discovery phase, a responsive design system and an accessible content workflow. Please outline a staged delivery plan, the client review points and the information needed for a reliable estimate.', 'language' => 'en', 'status' => $state, 'reviewed_at' => $state === 'pending' ? null : now()->subDays($i + 1), 'decided_by_type' => $state === 'pending' ? null : 'admin', 'decided_by_name' => $state === 'pending' ? null : $users[0]->name, 'rejection_reason' => $state === 'rejected' ? 'The requested launch window is outside our current capacity. We suggested a later discovery slot.' : null, 'send_invitation' => false, 'project_provisioning_id' => $provisioning, 'result' => $result, 'created_at' => now()->subDays($i + 2), 'updated_at' => now()->subDays($i + 1)]);
            DB::table('provisioning_operations')->insert(['workspace_id' => $workspace->id, 'integration_token_id' => $credentials[$i % 3], 'provisioning_uuid' => $result['operationId'] ?? null, 'operation_type' => 'project_request.created', 'project_request_uuid' => $request->uuid, 'outcome' => 'created', 'http_status' => 201, 'created_at' => $request->created_at]);
        }
        $shared = DB::table('integration_tokens')->insertGetId(['uuid' => self::uuid('shared-credential'), 'workspace_id' => $workspace->id, 'name' => 'Delivery agents · shared demo credential', 'agent_shared' => true, 'secret_hash' => hash('sha256', random_bytes(64)), 'scopes' => json_encode(['agent:profile', 'agent:tasks:read', 'agent:tasks:report', 'agent:messages:create', 'agent:runs:read', 'agent:runs:write']), 'expires_at' => '2000-01-01 00:00:00', 'created_at' => now()->subDays(20), 'updated_at' => now()]);
        foreach (['Frontend Implementation Agent', 'QA & Accessibility Agent', 'API Integration Agent'] as $i => $name) {
            $agent = ExternalAgent::create(['workspace_id' => $workspace->id, 'name' => $name, 'description' => ['Responsive components and interaction states. Human review required before publication.', 'Keyboard navigation, accessible names and reproducible QA findings.', 'Integration contracts, request validation and safe error handling.'][$i], 'kind' => 'ai', 'active' => true, 'permissions' => AgentPermissions::DEFAULTS, 'instructions' => 'Synthetic demonstration history. No provider execution. Return internal findings for a developer to review.']);
            $agent->forceFill(['uuid' => self::uuid('agent/'.$i), 'created_at' => now()->subDays(20), 'updated_at' => now()->subHours(4 + $i), 'last_success_at' => now()->subHours(4 + $i), 'successful_requests' => 18 + $i * 7, 'failed_requests' => $i])->save();
            DB::table('agent_credential_links')->insert(['uuid' => self::uuid('credential-link/'.$i), 'workspace_id' => $workspace->id, 'external_agent_id' => $agent->id, 'integration_token_id' => $shared, 'created_at' => now()]);
            foreach ([$i, ($i + 1) % 3] as $p) {
                DB::table('agent_project_grants')->insert(['workspace_id' => $workspace->id, 'external_agent_id' => $agent->id, 'project_id' => $projects[$p]->id]);
                $projects[$p]->members()->create(['workspace_id' => $workspace->id, 'external_agent_id' => $agent->id]);
            }
            $task = $tasks[$i][2 + $i];
            $assignment = TaskAgentAssignment::create(['workspace_id' => $workspace->id, 'task_id' => $task->id, 'external_agent_id' => $agent->id, 'state' => $i === 2 ? 'cancelled' : 'submitted']);
            $assignment->forceFill(['uuid' => self::uuid('assignment/'.$i), 'created_at' => now()->subDays(5), 'updated_at' => now()->subHours(3), 'revoked_at' => $i === 2 ? now()->subHours(3) : null])->save();
            $task->history()->create(['actor_type' => 'admin', 'actor_user_id' => $users[1]->id, 'event_type' => 'agent_assigned', 'meta' => ['agentId' => $agent->uuid, 'agentName' => $name], 'created_at' => now()->subDays(5)]);
            for ($r = 0; $r < 2; $r++) {
                $state = $i === 2 ? 'cancelled' : ($r === 0 ? 'failed' : 'submitted');
                $time = now()->subDays(4 - $r);
                $runUuid = self::uuid('run/'.$i.'/'.$r);
                $instruction = $r === 0 ? 'Review '.$task->title.'. Return implementation notes and a short verification checklist for internal review.' : 'Please address the review notes, check the mobile edge cases and submit the revised result. Do not publish to the client.';
                $run = DB::table('agent_runs')->insertGetId(['uuid' => $runUuid, 'task_agent_assignment_id' => $assignment->id, 'initiated_by' => $users[1]->id, 'instruction' => $instruction, 'context' => json_encode(['task' => ['id' => $task->uuid, 'title' => $task->title], 'instruction' => $instruction, 'synthetic' => true]), 'context_version' => hash('sha256', $instruction), 'request_hash' => hash('sha256', $instruction), 'key_hash' => hash('sha256', $runUuid), 'state' => $state, 'delivered_at' => $time, 'started_at' => $time->copy()->addMinutes(1), 'completed_at' => $time->copy()->addMinutes(12), 'error_code' => $state === 'failed' ? 'review_revision_required' : null, 'created_at' => $time, 'updated_at' => $time->copy()->addMinutes(12)]);
                $report = Comment::create(['project_id' => $task->project_id, 'task_id' => $task->id, 'team_only' => true, 'author_type' => 'agent', 'external_agent_id' => $agent->id, 'text' => $state === 'submitted' ? 'The revised result is ready for developer review. Mobile layout, validation messages and keyboard states have been checked. Please review the acceptance checklist before sharing a client summary.' : ($state === 'cancelled' ? 'Execution cancelled after the scope changed. No output was published.' : 'The first pass found an unresolved edge case. A revised instruction is needed before the next review.'), 'created_at' => $time->copy()->addMinutes(12), 'updated_at' => $time->copy()->addMinutes(12)]);
                $report->forceFill(['created_at' => $time->copy()->addMinutes(12), 'updated_at' => $time->copy()->addMinutes(12)])->saveQuietly();
                DB::table('agent_runs')->where('id', $run)->update(['result_comment_id' => $report->id]);
                DB::table('agent_reports')->insert(['uuid' => self::uuid('report/'.$i.'/'.$r), 'task_agent_assignment_id' => $assignment->id, 'agent_run_id' => $run, 'comment_id' => $report->id, 'state' => $state, 'created_at' => $report->created_at]);
                foreach ([[$users[1]->id, null, $instruction], [null, $agent->id, 'I have started the review. I will keep intermediate findings in this internal conversation.'], [$users[1]->id, null, $r === 0 ? 'Please revisit the empty state and focus return before resubmitting.' : 'Reviewed. The checklist is complete; I will prepare a concise client-facing summary.']] as $m => [$userId, $agentId, $text]) {
                    DB::table('agent_messages')->insert(['uuid' => self::uuid('message/'.$i.'/'.$r.'/'.$m), 'agent_run_id' => $run, 'user_id' => $userId, 'external_agent_id' => $agentId, 'text' => $text, 'created_at' => $time->copy()->addMinutes($r === 1 && $m === 2 ? 20 : $m * 4)]);
                }
                foreach (['agent_run_requested', 'agent_run_started', 'agent_reports'] as $e => $event) {
                    $task->history()->create(['actor_type' => $e === 0 ? 'admin' : 'agent', 'actor_user_id' => $e === 0 ? $users[1]->id : null, 'event_type' => $event, 'meta' => ['teamOnly' => true, 'runId' => $runUuid, 'agentName' => $name, 'state' => match ($e) {
                        0 => 'queued', 1 => 'running', default => $state
                    }], 'created_at' => $time->copy()->addMinutes([0, 1, 12][$e])]);
                }
                if ($state === 'submitted') {
                    $public = $task->comments()->create(['project_id' => $task->project_id, 'author_type' => 'admin', 'author_user_id' => $users[1]->id, 'text' => 'The team has completed the responsive and accessibility review for this item. The agreed edge cases are covered and the result is ready for your acceptance review.', 'created_at' => $time->copy()->addHours(2), 'updated_at' => $time->copy()->addHours(2)]);
                    $public->forceFill(['created_at' => $time->copy()->addHours(2), 'updated_at' => $time->copy()->addHours(2)])->saveQuietly();
                    $task->history()->create(['actor_type' => 'admin', 'actor_user_id' => $users[1]->id, 'event_type' => 'comment', 'meta' => ['commentId' => $public->id, 'agentSourceCommentId' => $report->id, 'text' => 'Developer shared an agent result'], 'created_at' => $public->created_at]);
                }
            }
            if ($i === 2) {
                $task->history()->create(['actor_type' => 'admin', 'actor_user_id' => $users[1]->id, 'event_type' => 'agent_unassigned', 'meta' => ['agentName' => $name], 'created_at' => now()->subHours(3)]);
            }
            DB::table('agent_operations')->insert(['workspace_id' => $workspace->id, 'external_agent_id' => $agent->id, 'integration_token_id' => $shared, 'project_uuid' => $projects[$i]->uuid, 'task_uuid' => $task->uuid, 'assignment_uuid' => $assignment->uuid, 'operation' => 'POST api/v1/agent/tasks/{task}/reports', 'http_status' => 201, 'created_at' => now()->subHours(4 + $i)]);
        }
        foreach (['Project activity archive', 'Client portal notifications'] as $i => $name) {
            $endpoint = DB::table('webhook_endpoints')->insertGetId(['uuid' => self::uuid('webhook/'.$i), 'workspace_id' => $workspace->id, 'name' => $name, 'url' => 'https://hooks.example.test/projectbrief/'.($i + 1), 'events' => json_encode(['task.updated', 'project.created']), 'enabled' => false, 'secret' => 'demo-unusable-signing-material', 'created_at' => now()->subDays(24), 'updated_at' => now()]);
            for ($d = 0; $d < 5; $d++) {
                $time = now()->subDays($d + 1);
                $event = DB::table('webhook_events')->insertGetId(['uuid' => self::uuid('webhook-event/'.$i.'/'.$d), 'workspace_id' => $workspace->id, 'type' => 'task.updated', 'body' => json_encode(['projectId' => $projects[$i]->uuid, 'taskId' => $tasks[$i][$d]->uuid, 'synthetic' => true]), 'created_at' => $time]);
                $delivery = DB::table('webhook_deliveries')->insertGetId(['uuid' => self::uuid('delivery/'.$i.'/'.$d), 'webhook_endpoint_id' => $endpoint, 'webhook_event_id' => $event, 'secret_version' => 1, 'status' => $d === 3 ? 'failed' : 'succeeded', 'attempts' => 1, 'available_at' => $time, 'http_status' => $d === 3 ? 400 : 204, 'error_code' => $d === 3 ? 'remote_rejected' : null, 'created_at' => $time, 'updated_at' => $time]);
                DB::table('webhook_attempts')->insert(['webhook_delivery_id' => $delivery, 'attempt' => 1, 'status' => $d === 3 ? 'failed' : 'succeeded', 'http_status' => $d === 3 ? 400 : 204, 'error_code' => $d === 3 ? 'remote_rejected' : null, 'duration_ms' => 84 + $d * 19, 'created_at' => $time]);
            }
        }
    }
}
