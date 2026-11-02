<?php

namespace App\Services;

use App\Http\Middleware\DemoReadOnly;
use App\Http\Middleware\DemoStaticExportThrottle;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Export real HTTP read contracts, never model serialization or a database dump. */
class DemoStaticSnapshotExport
{
    private array $cookies = [];

    private string $directory;

    private array $assets = [];

    private string $role = 'developer';

    private array $assetAliases = [];

    private string $avatar;

    public function run(): array
    {
        DemoEnvironment::assertIsolated();
        if (! DemoEnvironment::readOnly() || DB::connection()->getConfig('username') !== 'demo_reader') {
            throw new RuntimeException('Static export requires the dedicated SELECT-only demo_reader.');
        }
        $audit = app(DemoDatasetAudit::class);
        $before = $audit->run();
        if ($before['counts']['workspaces'] !== 1 || $before['contentHash'] !== '9a39a84bb3111143a29505bacb254b15268393c08975d170d7e78240ee6af77c') {
            throw new RuntimeException('The reviewed fictional dataset has changed. Review before exporting.');
        }
        $this->directory = base_path('../static-export');
        File::ensureDirectoryExists($this->directory.'/snapshots');
        File::ensureDirectoryExists($this->directory.'/demo-assets');
        $portrait = File::get(storage_path('app/private/demo/avatar/morgan-ellis.png'));
        $portraitFile = 'demo-assets/'.hash('sha256', $portrait).'.png';
        File::put($this->directory.'/'.$portraitFile, $portrait);
        $this->assets[$portraitFile] = hash('sha256', $portrait);
        $this->avatar = '/'.$portraitFile;
        DB::connection()->beforeExecuting(function (string $query): void {
            if (! preg_match('/^\s*(SELECT|SHOW|EXPLAIN)\b/i', $query)) {
                throw new RuntimeException('Static export cannot write to the source database.');
            }
        });
        config(['session.driver' => 'array', 'cache.default' => 'array']);
        // Only this CLI process may read the three otherwise hidden account/document screens.
        // All normal HTTP middleware, resource serializers and access policies still execute.
        app()->instance(DemoReadOnly::class, new class extends DemoReadOnly
        {
            public function handle(Request $request, \Closure $next): Response
            {
                if ($request->isMethod('GET') && $request->is('api/admin/account', 'api/admin/settings/agreement', 'api/admin/agreement')) {
                    DemoEnvironment::assertIsolated();

                    return $next($request);
                }

                return parent::handle($request, $next);
            }
        });
        app(Kernel::class);
        app('router')->aliasMiddleware('throttle', DemoStaticExportThrottle::class);
        Carbon::setTestNow(Carbon::parse('2026-10-30 16:00:00', 'UTC'));
        try {
            $workspace = Workspace::firstOrFail();
            $projects = Project::orderBy('uuid')->get();
            $clientProject = Project::where('title', 'Northstar Commerce')->firstOrFail();
            $manifest = ['schemaVersion' => 1, 'dataset' => 'harborline-v1', 'contentHash' => $before['contentHash'], 'counts' => $before['counts'], 'workspace' => $workspace->uuid, 'clientProject' => $clientProject->uuid, 'roles' => []];
            foreach (['developer', 'client'] as $role) {
                $this->role = $role;
                $this->cookies = [];
                $this->read('/api/demo/enter/'.$role);
                $routes = [];
                $paths = $role === 'developer'
                    ? ['/api/session', '/api/admin/me', '/api/admin/workspaces', '/api/admin/notifications', '/api/admin/account', '/api/security/status', '/api/admin/settings/agreement', '/api/admin/agreement', '/api/admin/administrators', '/api/admin/projects', '/api/admin/projects/trash', '/api/admin/integrations', '/api/admin/integrations/agents', '/api/admin/integrations/operations', '/api/admin/project-requests', '/api/admin/project-requests/count', '/api/admin/reviews', '/api/admin/telegram', '/api/admin/push/status', '/api/admin/workspaces/'.$workspace->uuid.'/branding']
                    : ['/api/session', '/api/client/me', '/api/client/project', '/api/client/tasks', '/api/client/notifications', '/api/client/telegram', '/api/client/push/status'];
                foreach ($paths as $path) {
                    $value = $this->allPages($path);
                    $value = $this->illustratedState($path, $value, $workspace);
                    $routes[$path] = $this->snapshot($value);
                }
                foreach ($role === 'developer' ? $projects : [$clientProject] as $project) {
                    $prefix = $role === 'developer' ? '/api/admin/projects/'.$project->uuid : '/api/client/project/'.$project->uuid;
                    $sections = $role === 'developer'
                        ? ['', '/sections', '/tasks', '/team', '/team-chat', '/discussions', '/activity', '/clients', '/notifications', '/feedback', '/trash', '/agent-instructions']
                        : ['', '/sections', '/discussions', '/feedback'];
                    foreach ($sections as $section) {
                        if ($section === '/team-chat' && $project->status !== 'active') {
                            continue;
                        }
                        $routes[$prefix.$section] = $this->snapshot($this->allPages($prefix.$section));
                    }
                    $tasksPath = $role === 'developer' ? $prefix.'/tasks' : '/api/client/tasks';
                    foreach ($project->tasks()->orderBy('uuid')->get() as $task) {
                        $taskPath = '/api/'.($role === 'developer' ? 'admin' : 'client').'/tasks/'.$task->uuid;
                        $routes[$taskPath] = [...$routes[$tasksPath], 'task' => $task->uuid];
                        if ($role === 'developer' && $task->agentAssignments()->exists()) {
                            $collaboration = $taskPath.'/agent-collaboration';
                            $value = $this->read($collaboration);
                            $routes[$collaboration] = $this->snapshot($value);
                            foreach ($value['data'] as $assignment) {
                                foreach (['runs', 'messages'] as $kind) {
                                    $routes[$taskPath.'/agent-assignments/'.$assignment['id'].'/'.$kind] = $this->snapshot($this->read($taskPath.'/agent-assignments/'.$assignment['id'].'/'.$kind));
                                }
                            }
                            $routes[$taskPath.'/agent-collaboration/history'] = $this->snapshot($this->read($taskPath.'/agent-collaboration/history'));
                        }
                    }
                }
                if ($role === 'developer') {
                    foreach ($this->allPages('/api/admin/project-requests')['data'] as $request) {
                        $path = '/api/admin/project-requests/'.$request['id'];
                        $routes[$path] = $this->snapshot($this->read($path));
                    }
                    foreach ($this->read('/api/admin/integrations')['webhooks'] as $hook) {
                        $path = '/api/admin/integrations/webhooks/'.$hook['id'].'/deliveries';
                        $routes[$path] = $this->snapshot($this->allPages($path));
                    }
                    $public = '/api/public/workspaces/'.$workspace->uuid.'/agreement';
                    $routes[$public] = $this->snapshot($this->agreementDocument($workspace));
                }
                ksort($routes);
                $manifest['roles'][$role] = ['routes' => $routes];
            }
            if ($before['databaseHash'] !== $audit->run()['databaseHash']) {
                throw new RuntimeException('Export changed its source database.');
            }
            ksort($this->assets);
            $manifest['assets'] = $this->assets;
            File::put($this->directory.'/manifest.json', $this->json($manifest));

            return ['passed' => true, 'schemaVersion' => 1, 'sourceUnchanged' => true, 'routes' => array_map(fn (array $role): int => count($role['routes']), $manifest['roles']), 'counts' => $before['counts']];
        } finally {
            Carbon::setTestNow();
        }
    }

    private function response(string $path): Response
    {
        $request = Request::create('http://localhost'.$path, 'GET', [], $this->cookies, [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_ACCEPT_LANGUAGE' => 'en']);
        $kernel = app(Kernel::class);
        $response = $kernel->handle($request);
        foreach ($response->headers->getCookies() as $cookie) {
            $this->cookies[$cookie->getName()] = $cookie->getValue();
        }
        $kernel->terminate($request, $response);
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('Read contract failed: '.$path.' ('.$response->getStatusCode().')');
        }

        return $response;
    }

    private function read(string $path): array
    {
        return json_decode($this->response($path)->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function allPages(string $path): array
    {
        $value = $this->read($path);
        $last = $value['meta']['last_page'] ?? $value['meta']['lastPage'] ?? 1;
        for ($page = 2; $page <= $last; $page++) {
            $next = $this->read($path.'?page='.$page);
            $value['data'] = [...$value['data'], ...$next['data']];
        }

        return $value;
    }

    private function snapshot(array $value): array
    {
        $json = $this->json($this->safe($value));
        $sha = hash('sha256', $json);
        $file = 'snapshots/'.$this->role.'-'.$sha.'.json';
        File::put($this->directory.'/'.$file, $json);

        return ['file' => $file, 'sha256' => $sha];
    }

    private function safe(mixed $value, string $key = ''): mixed
    {
        if (in_array($key, ['editVersion', 'syncCursor', 'confirmationContext', 'notificationPreferenceId', 'links'], true)) {
            return $key === 'notificationPreferenceId' ? 'fictional-demo-preferences' : null;
        }
        if (is_array($value)) {
            if (($value['email'] ?? null) === 'morgan@harborline.test' && array_key_exists('avatarUrl', $value)) {
                $value['avatarUrl'] = $this->avatar;
                $value['avatarSource'] = 'upload';
            }
            $result = [];
            foreach ($value as $name => $item) {
                $result[$name] = $this->safe($item, (string) $name);
            }

            return $result;
        }
        if (is_string($value)) {
            $value = preg_replace('~^http://(?:127\.0\.0\.1|localhost)(?::[0-9]+)?/~', '/', $value);
            if (preg_match('~^/(branding-assets/|api/(admin|client)/attachments/)~', $value)) {
                $value = preg_replace('~(/api/(?:admin|client)/attachments/[^/]+)/(original|preview)$~', '$1/download', $value);
                if (isset($this->assetAliases[$value])) {
                    return $this->assetAliases[$value];
                }
                $response = $this->response($value);
                if ($response instanceof BinaryFileResponse) {
                    $file = $response->getFile()->getPathname();
                    if (! str_contains($file, '/demo/')) {
                        throw new RuntimeException('Asset is outside the reviewed demo directory.');
                    }
                    $bytes = File::get($file);
                } elseif ($response instanceof StreamedResponse) {
                    ob_start();
                    $response->sendContent();
                    $bytes = ob_get_clean();
                } else {
                    $bytes = $response->getContent();
                }
                $mime = explode(';', $response->headers->get('Content-Type') ?? '')[0];
                $extension = ['image/svg+xml' => 'svg', 'image/png' => 'png', 'image/webp' => 'webp', 'text/plain' => 'txt'][$mime] ?? null;
                if (! $extension) {
                    throw new RuntimeException('Unreviewed asset type: '.$mime);
                }
                $name = 'demo-assets/'.hash('sha256', $bytes).'.'.$extension;
                File::put($this->directory.'/'.$name, $bytes);
                $this->assets[$name] = hash('sha256', $bytes);

                return $this->assetAliases[$value] = '/'.$name;
            }
            if ($value !== '' && preg_match('~(password_hash|BEGIN .*PRIVATE KEY|/Users/|/demo-runtime/|\$argon2|\$2y\$|demo-unusable-signing-material)~i', $value)) {
                throw new RuntimeException('Unexpected private material in a read contract.');
            }
        }

        return $value;
    }

    private function illustratedState(string $path, array $value, Workspace $workspace): array
    {
        if ($path === '/api/security/status') {
            return ['state' => 'authenticated', 'signedIn' => true, 'platformAdmin' => false, 'owner' => true, 'totp' => true, 'recoverySaved' => true, 'fresh' => false, 'freshFor' => 0, 'confirmationContext' => null, 'setupFresh' => false, 'recoveryFresh' => false, 'confirmationMethod' => null, 'recommendationsPending' => false, 'passkeys' => [['id' => 1, 'name' => 'Morgan’s studio laptop', 'createdAt' => '2026-09-08T09:00:00.000000Z', 'lastUsedAt' => '2026-10-30T08:20:00.000000Z'], ['id' => 2, 'name' => 'Studio backup security key', 'createdAt' => '2026-09-10T09:00:00.000000Z', 'lastUsedAt' => '2026-10-28T08:20:00.000000Z']]];
        }
        if ($path === '/api/admin/account') {
            $value['pendingEmail'] = null;
            $value['emailExpiresAt'] = null;
            $value['user']['avatarSource'] = 'upload';
            $value['user']['avatarUrl'] = $this->avatar;
        }
        if ($path === '/api/admin/me') {
            $value['user']['avatarSource'] = 'upload';
            $value['user']['avatarUrl'] = $this->avatar;
        }
        if ($path === '/api/admin/settings/agreement') {
            $value = ['data' => $this->agreementSettings($workspace), 'templates' => $this->agreementSettings($workspace)['draft']['texts'], 'publicUrl' => '/w/'.$workspace->uuid.'/agreement'];
        }
        if ($path === '/api/admin/agreement') {
            $value = [...$this->agreementDocument($workspace), 'required' => false];
        }
        if ($path === '/api/admin/administrators') {
            foreach ($value['members'] as &$member) {
                $member['agreement'] = ['status' => 'accepted', 'version' => '2026-10-01', 'locale' => 'en', 'acceptedAt' => '2026-10-02T09:00:00.000000Z'];
            }
        }
        if ($path === '/api/session' && $this->role === 'developer') {
            $value['securityRecommended'] = false;
            $value['agreementRequired'] = false;
        }

        return $value;
    }

    private function agreementSettings(Workspace $workspace): array
    {
        $paragraphs = [
            ['heading', 'Harborline Studio Team Agreement'],
            ['paragraph', 'Version 2026-10-01 · Fictional demonstration agreement between Harborline Digital Studio and its team members.'],
            ['subheading', 'Professional collaboration'],
            ['paragraph', 'We coordinate delivery through Dev2Done and agree to communicate clearly, document decisions, respect agreed delivery milestones and raise risks early. Workspace access is granted only for assigned studio work.'],
            ['subheading', 'Confidentiality and client trust'],
            ['paragraph', 'Keep client materials and internal discussions within their intended audience. Share AI-assisted research only after a human review; publish client-facing decisions through the approved project channels.'],
            ['subheading', 'Security and responsible access'],
            ['paragraph', 'Use individual accounts, protect authentication factors, apply least privilege and report suspected incidents promptly to studio@harborline.test. Do not share credentials or upload personal client data to unapproved services.'],
            ['subheading', 'Work product and handover'],
            ['paragraph', 'Record sources and licenses, keep project documentation current and provide a clear handover. Team access ends when the engagement ends. Studio and member review changes together before a new published version applies.'],
            ['paragraph', 'This fictional text demonstrates the existing agreement interface. It creates no real relationship or legal obligation.'],
        ];
        $blocks = array_map(fn (array $row): array => ['type' => $row[0], 'content' => [['text' => $row[1]]]], $paragraphs);
        $draft = ['date' => '2026-10-01', 'texts' => array_fill_keys(WorkspaceAgreements::LOCALES, $blocks), 'enabledLocales' => ['en'], 'defaultLocale' => 'en'];
        $workspace = clone $workspace;
        $workspace->administrator_agreement = ['enabled' => true, 'draft' => $draft, 'published' => [...$draft, 'id' => '58217ba8-2afd-54dc-8941-f2428446b128']];

        return app(WorkspaceAgreements::class)->settings($workspace);
    }

    private function agreementDocument(Workspace $workspace): array
    {
        return ['document' => app(WorkspaceAgreements::class)->visibleDocument($this->agreementSettings($workspace)['published']), 'workspace' => ['name' => $workspace->name, 'branding' => app(WorkspaceBranding::class)->portal($workspace)]];
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n";
    }
}
