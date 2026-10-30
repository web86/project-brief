<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AccountSecurity;
use App\Services\BrandImage;
use App\Services\DocxProjectExporter;
use App\Services\ProjectExportData;
use App\Services\WorkspaceBranding;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UxRefinementsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function owner(): array
    {
        Storage::fake('local');
        $project = Project::factory()->create(['title' => 'Studio project']);
        $owner = $this->ownerFor($project);
        $this->actingAs($owner);

        return [$project, '/api/admin/workspaces/'.$project->workspace->uuid.'/branding', $owner];
    }

    public function test_universal_search_is_trimmed_paged_and_has_no_duplicate_projects(): void
    {
        [$project] = $this->owner();
        $projects = collect([$project])->concat(Project::factory()->count(21)->for($project->workspace)->create());
        foreach ($projects as $index => $item) {
            $item->clients()->first()->update(['name' => 'Acme Client', 'email' => 'client-'.$index.'@acme.test']);
        }
        $project->clients()->create(['name' => 'Second Acme match', 'email' => 'another@acme.test', 'active' => true]);
        $foreign = Project::factory()->create(['title' => 'Acme foreign']);
        $trash = Project::factory()->for($project->workspace)->create(['title' => 'Acme trash']);
        $trash->delete();
        $this->getJson('/api/admin/projects?q=%20Acme%20')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 22)->assertJsonPath('meta.last_page', 2)->assertJsonMissing(['id' => $foreign->uuid]);
        $this->getJson('/api/admin/projects?q=acme&page=2')->assertJsonCount(2, 'data');
        $this->getJson('/api/admin/projects?q=client-0@acme.test')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $project->uuid)->assertJsonMissingPath('data.0.clients');
        $this->getJson('/api/admin/projects?q=%20ab%20')->assertJsonPath('meta.total', 22);
        $project->update(['status' => 'inactive']);
        $this->getJson('/api/admin/projects?q=Acme&status=inactive')->assertJsonPath('meta.total', 1);
    }

    public function test_client_identity_search_obeys_each_developer_assignment_permission(): void
    {
        [$project] = $this->owner();
        $dev = User::factory()->memberOf($project->workspace)->create();
        $member = $dev->workspaceMembers()->first();
        $project->clients()->first()->update(['name' => 'Private identity', 'email' => 'private@example.test']);
        $assignment = $project->members()->create(['workspace_id' => $project->workspace_id, 'workspace_member_id' => $member->id]);
        $other = Project::factory()->for($project->workspace)->create(['title' => 'Private unassigned']);
        $this->actingAs($dev)->getJson('/api/admin/projects?q=private')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/admin/projects?q=Studio')->assertJsonPath('meta.total', 1);
        $assignment->update(['permissions' => ['manage_clients' => true]]);
        $this->getJson('/api/admin/projects?q=private')->assertJsonPath('meta.total', 0);
        $member->update(['is_project_superadmin' => true]);
        $response = $this->getJson('/api/admin/projects?q=private')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $project->uuid)->assertJsonMissing(['id' => $other->uuid]);
        $this->assertStringNotContainsString('private@example.test', $response->getContent());
        $this->getJson('/api/admin/me')->assertJsonMissingPath('workspace.branding.business')->assertJsonMissingPath('workspace.branding.contact');
    }

    public function test_all_sections_commit_atomically_with_per_section_conflict_detection(): void
    {
        [$project, $root] = $this->owner();
        $versions = $this->getJson($root)->json('data.versions');
        $changes = ['visual' => ['companyName' => 'Studio Çığ 中文'], 'business' => ['account' => 'PRIVATE_BANK'], 'contact' => ['email' => 'hello@example.test']];
        $this->patchJson($root, ['sections' => array_replace($changes, ['contact' => ['email' => 'invalid']]), 'versions' => $versions])->assertUnprocessable();
        $this->assertNull($project->workspace->fresh()->branding);
        $this->patchJson($root, ['sections' => $changes, 'versions' => $versions])->assertOk()->assertJsonPath('data.business.account', 'PRIVATE_BANK');
        $this->getJson($root)->assertJsonPath('data.visual.companyName', 'Studio Çığ 中文')->assertJsonPath('data.contact.email', 'hello@example.test');
        $this->patchJson($root, ['sections' => ['business' => ['account' => 'STALE'], 'visual' => ['companyName' => 'STALE']], 'versions' => $versions])->assertStatus(409)->assertJsonPath('code', 'branding_conflict');
        $this->getJson($root)->assertJsonPath('data.business.account', 'PRIVATE_BANK');
        $latest = $this->getJson($root)->json('data.versions');
        $this->patchJson($root, ['section' => 'contact', 'values' => ['phone' => '+90 212 000']])->assertOk();
        $this->patchJson($root, ['sections' => ['visual' => ['companyName' => 'Updated']], 'versions' => ['visual' => $latest['visual']]])->assertOk()->assertJsonPath('data.contact.phone', '+90 212 000');
        Event::listen('eloquent.updating: '.Workspace::class, fn () => throw new \RuntimeException('Test database failure'));
        try {
            $this->patchJson($root, ['sections' => ['business' => ['account' => 'NOT_COMMITTED']], 'versions' => ['business' => $latest['business']]])->assertStatus(500);
        } finally {
            Event::forget('eloquent.updating: '.Workspace::class);
        }
        $this->getJson($root)->assertJsonPath('data.business.account', 'PRIVATE_BANK');
    }

    public function test_svg_is_primary_safe_on_direct_navigation_with_raster_only_for_email_and_documents(): void
    {
        [$project, $root] = $this->owner();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 50" onload="bad()"><script>bad()</script><image href="https://attacker.test"/><rect width="100" height="50" fill="#17695d"/><text x="5" y="20">Studio</text></svg>';
        $response = $this->post($root.'/assets/favicon', ['file' => UploadedFile::fake()->createWithContent('brand.svg', $svg), 'raster' => UploadedFile::fake()->image('fallback.png', 100, 50)], ['Accept' => 'application/json'])->assertOk();
        $url = $response->json('data.assets.favicon');
        $this->assertStringEndsWith('.svg', $url);
        $image = $this->get(parse_url($url, PHP_URL_PATH))->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        $this->assertStringContainsString('sandbox', $image->headers->get('Content-Security-Policy'));
        foreach (['<script', 'onload', 'attacker', '<image'] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $image->getContent());
        }
        $this->get(parse_url(str_replace('.svg', '.png', $url), PHP_URL_PATH))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->getJson('/api/admin/me')->assertJsonPath('workspace.branding.faviconType', 'image/svg+xml');
        $this->patchJson($root, ['section' => 'email', 'values' => ['enabled' => true]])->assertOk();
        $this->post($root.'/assets/logo', ['file' => UploadedFile::fake()->createWithContent('brand.svg', $svg), 'raster' => UploadedFile::fake()->image('fallback.png', 100, 50)], ['Accept' => 'application/json'])->assertOk();
        $workspace = $project->workspace->fresh();
        $service = app(WorkspaceBranding::class);
        $this->assertStringEndsWith('.png', $service->email($workspace)['logoUrl']);
        $this->assertStringStartsWith('data:image/png;base64,', $service->document($workspace, 'en')['logo']);
        $this->deleteJson($root.'/assets/favicon')->assertOk();
        $this->get(parse_url($url, PHP_URL_PATH))->assertNotFound();
        $this->assertDatabaseCount('project_file_deletions', 2);
    }

    public function test_image_optimization_measures_near_two_mb_input_and_preserves_alpha(): void
    {
        [$project, $root] = $this->owner();
        $image = imagecreatetruecolor(1900, 1100);
        $seed = 1729;
        for ($y = 0; $y < 1100; $y++) {
            for ($x = 0; $x < 1900; $x++) {
                $seed = ($seed * 1664525 + 1013904223) & 0xFFFFFFFF;
                imagesetpixel($image, $x, $y, ($seed >> 8) & 0xFFFFFF);
            }
        }
        for ($quality = 90; $quality >= 40; $quality -= 5) {
            ob_start();
            imagejpeg($image, null, $quality);
            $source = ob_get_clean();
            if (strlen($source) <= 2 * 1024 * 1024) {
                break;
            }
        }
        imagedestroy($image);
        $this->assertGreaterThan(1500000, strlen($source));
        $this->assertLessThanOrEqual(2 * 1024 * 1024, strlen($source));
        $file = UploadedFile::fake()->createWithContent('photographic-logo.jpg', $source);
        $old = app(BrandImage::class)->png($file, 'logo');
        $result = $this->post($root.'/assets/logo', ['file' => $file], ['Accept' => 'application/json'])->assertOk();
        $asset = $project->workspace->fresh()->branding['assets']['logo'];
        $output = Storage::disk('local')->get($asset['path']);
        $this->assertSame('image/png', $asset['mime']);
        $this->assertLessThan(strlen($old) * 0.5, strlen($output));
        $this->assertLessThanOrEqual(1024, getimagesizefromstring($output)[0]);
        $this->assertSame(strlen($output), $result->json('data.assetInfo.logo.bytes'));
        $oldFavicon = app(BrandImage::class)->png($file, 'favicon');
        $this->post($root.'/assets/favicon', ['file' => $file], ['Accept' => 'application/json'])->assertOk();
        $faviconAsset = $project->workspace->fresh()->branding['assets']['favicon'];
        $favicon = Storage::disk('local')->get($faviconAsset['path']);
        $this->assertSame([256, 256], array_slice(getimagesizefromstring($favicon), 0, 2));
        $this->assertLessThanOrEqual(strlen($oldFavicon), strlen($favicon));
        $this->assertCount(2, Storage::disk('local')->allFiles());
        $transparent = imagecreatetruecolor(100, 50);
        imagealphablending($transparent, false);
        imagesavealpha($transparent, true);
        imagefill($transparent, 0, 0, imagecolorallocatealpha($transparent, 0, 0, 0, 127));
        imagefilledellipse($transparent, 50, 25, 30, 30, imagecolorallocatealpha($transparent, 23, 105, 93, 0));
        ob_start();
        imagepng($transparent);
        $alpha = ob_get_clean();
        imagedestroy($transparent);
        $optimized = app(BrandImage::class)->optimized(UploadedFile::fake()->createWithContent('transparent.png', $alpha), 'logo');
        $this->assertSame('image/png', $optimized['mime']);
        $decoded = imagecreatefromstring($optimized['bytes']);
        $this->assertSame(127, imagecolorsforindex($decoded, imagecolorat($decoded, 0, 0))['alpha']);
        imagedestroy($decoded);
        Task::factory()->for($project)->create(['title' => 'Кириллица / Çığ / 中文需求', 'description' => 'JPEG logo compatibility — Müşteri hesabı / 客户账户']);
        $document = app(ProjectExportData::class)->build($project->fresh(), 'all', 'en');
        $this->assertStringStartsWith('data:image/png;base64,', $document['branding']['logo']);
        $docx = app(DocxProjectExporter::class)->render($document);
        $this->assertStringStartsWith('PK', $docx);
        if ($directory = getenv('BRANDING_IMAGE_QA_DIRECTORY')) {
            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
            file_put_contents($directory.'/original.jpg', $source);
            file_put_contents($directory.'/milestone-7-5.png', $old);
            file_put_contents($directory.'/optimized.png', $output);
            file_put_contents($directory.'/sizes.json', json_encode(['original' => strlen($source), 'milestone75Png' => strlen($old), 'optimized' => strlen($output), 'mime' => $asset['mime'], 'originalDimensions' => [1900, 1100], 'milestone75Dimensions' => array_slice(getimagesizefromstring($old), 0, 2), 'optimizedDimensions' => array_slice(getimagesizefromstring($output), 0, 2), 'favicon75Bytes' => strlen($oldFavicon), 'favicon76Bytes' => strlen($favicon), 'faviconDimensions' => [256, 256]], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            file_put_contents($directory.'/jpeg-branded.json', json_encode(['schemaVersion' => 1, 'filename' => 'jpeg-branded.pdf', 'document' => $document], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            file_put_contents($directory.'/jpeg-branded.docx', $docx);
        }
    }

    public function test_cosmetic_mutation_still_requires_existing_fresh_proof_before_any_write(): void
    {
        [$project, $root, $owner] = $this->owner();
        session()->forget('security.step_up');
        session()->save();
        $this->withCookie(config('session.cookie'), session()->getId());
        $file = UploadedFile::fake()->image('icon.png', 32, 32);
        $this->post($root.'/assets/favicon', ['file' => $file], ['Accept' => 'application/json'])->assertStatus(423)->assertJsonPath('mutationNotStarted', true);
        $this->assertNull($project->workspace->fresh()->branding);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $request = Request::create('/');
        $request->setLaravelSession(app('session')->driver());
        app(AccountSecurity::class)->confirm($request, $owner, 'mfa');
        session()->save();
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->post($root.'/assets/favicon', ['file' => $file], ['Accept' => 'application/json'])->assertOk();
    }
}
