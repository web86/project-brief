<?php

namespace Tests\Feature;

use App\Mail\AccountActionMail;
use App\Mail\ProjectActivityMail;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AccountMail;
use App\Services\ClientInvitations;
use App\Services\DocxProjectExporter;
use App\Services\NotificationPayload;
use App\Services\ProjectExportData;
use App\Services\ProjectNotificationService;
use App\Services\WorkspaceBranding;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WorkspaceBrandingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Project $project;

    private User $owner;

    private function root(?Workspace $workspace = null): string
    {
        return '/api/admin/workspaces/'.($workspace ?? $this->project->workspace)->uuid.'/branding';
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->project = Project::factory()->create();
        $this->owner = $this->ownerFor($this->project);
        $this->actingAs($this->owner);
    }

    public function test_owner_section_updates_are_scoped_validated_and_do_not_overwrite_other_sections(): void
    {
        $root = $this->root();
        $this->getJson($root)->assertOk()->assertJsonPath('data.export.business', false)->assertJsonPath('data.email.enabled', false);
        $this->patchJson($root, ['section' => 'visual', 'values' => ['companyName' => 'Studio 中文 Çığ', 'primaryColor' => '#17695d']])->assertOk();
        $this->patchJson($root, ['section' => 'business', 'values' => ['account' => 'PRIVATE_IBAN', 'legalName' => 'PRIVATE_LEGAL']])->assertOk()->assertJsonPath('data.visual.companyName', 'Studio 中文 Çığ');
        $this->patchJson($root, ['section' => 'visual', 'values' => ['companyName' => 'New name']])->assertJsonPath('data.business.account', 'PRIVATE_IBAN')->assertJsonPath('data.visual.primaryColor', '#17695d');
        foreach ([['section' => 'visual', 'values' => ['primaryColor' => 'red;background:url(x)']], ['section' => 'visual', 'values' => ['assets' => []]], ['section' => 'contact', 'values' => ['website' => 'javascript:alert(1)']], ['section' => 'business', 'values' => ['note' => str_repeat('a', 2001)]]] as $data) {
            $this->patchJson($root, $data)->assertUnprocessable();
        }
        $this->getJson('/api/admin/workspaces')->assertJsonMissingPath('data.0.branding');
    }

    public function test_nonowners_clients_and_stale_workspace_routes_cannot_read_or_modify_branding(): void
    {
        $foreign = Workspace::factory()->create();
        User::factory()->owner($foreign)->create();
        $this->getJson($this->root($foreign))->assertNotFound();
        $this->patchJson($this->root($foreign), ['section' => 'visual', 'values' => ['companyName' => 'Denied']])->assertNotFound();
        $developer = User::factory()->memberOf($this->project->workspace)->create();
        $this->actingAs($developer)->getJson($this->root())->assertForbidden();
        $this->patchJson($this->root(), ['section' => 'contact', 'values' => ['email' => 'test@example.test']])->assertForbidden();
        $this->post($this->root().'/assets/logo', ['file' => UploadedFile::fake()->image('image.png')], ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($this->owner);
        $second = Workspace::factory()->create();
        $second->members()->create(['user_id' => $this->owner->id, 'role' => 'owner', 'status' => 'active', 'joined_at' => now()]);
        $this->postJson('/api/admin/workspaces/'.$second->uuid.'/select')->assertOk();
        $this->patchJson($this->root(), ['section' => 'visual', 'values' => ['companyName' => 'Old draft']])->assertNotFound();
        $this->assertNull($second->fresh()->branding);
    }

    public function test_raster_upload_is_normalized_opaque_and_deletion_uses_existing_durable_cleanup(): void
    {
        $root = $this->root();
        $response = $this->post($root.'/assets/logo', ['file' => UploadedFile::fake()->image('logo.jpg', 600, 200)], ['Accept' => 'application/json'])->assertOk();
        $url = $response->json('data.assets.logo');
        $this->assertStringContainsString('/branding-assets/', $url);
        $response->assertJsonMissingPath('data.assets.logo.path');
        $this->assertStringNotContainsString('branding/'.$this->project->workspace->uuid, $response->getContent());
        $path = $this->project->workspace->fresh()->branding['assets']['logo']['path'];
        $this->assertSame('image/png', getimagesizefromstring(Storage::disk('local')->get($path))['mime']);
        $public = $this->get(parse_url($url, PHP_URL_PATH))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertStringContainsString('sandbox', $public->headers->get('Content-Security-Policy'));
        $this->deleteJson($root.'/assets/logo')->assertOk()->assertJsonPath('data.assets.logo', null);
        $this->get(parse_url($url, PHP_URL_PATH))->assertNotFound();
        $this->assertDatabaseHas('project_file_deletions', ['path' => $path]);
    }

    public function test_small_favicon_fills_its_canvas_without_changing_aspect_ratio(): void
    {
        $response = $this->post($this->root().'/assets/favicon', ['file' => UploadedFile::fake()->image('icon.png', 32, 16)], ['Accept' => 'application/json'])->assertOk();
        $image = imagecreatefromstring($this->get(parse_url($response->json('data.assets.favicon'), PHP_URL_PATH))->assertOk()->getContent());
        $this->assertSame(256, imagesx($image));
        $this->assertSame(256, imagesy($image));
        $this->assertSame(127, imagecolorsforindex($image, imagecolorat($image, 128, 0))['alpha']);
        $this->assertSame(0, imagecolorsforindex($image, imagecolorat($image, 0, 128))['alpha']);
        imagedestroy($image);
    }

    public function test_svg_sanitization_removes_active_content_external_resources_and_entities(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 60" onload="alert(1)"><script>alert(1)</script><foreignObject><div>bad</div></foreignObject><image href="https://attacker.test/x"/><path d="M0 0L40 40" style="fill:red" onclick="bad()" fill="url(https://attacker.test/x)"/><rect width="40" height="40" fill="#17695d"/><text x="10" y="20">Studio Çığ<?xml-stylesheet href="https://attacker.test/text"?></text></svg>';
        $clean = $this->post($this->root().'/sanitize-svg', ['file' => UploadedFile::fake()->createWithContent('logo.svg', $svg)], ['Accept' => 'application/json'])->assertOk()->json('svg');
        foreach (['script', 'foreignObject', 'attacker', 'onclick', 'onload', 'style=', '<image'] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $clean);
        }
        $this->assertStringContainsString('<rect', $clean);
        $this->assertStringContainsString('Studio Çığ', $clean);
        $this->assertStringNotContainsString('<?xml-stylesheet', $clean);
        $this->post($this->root().'/assets/logo', ['file' => UploadedFile::fake()->createWithContent('logo.svg', $clean), 'raster' => UploadedFile::fake()->image('preview.png', 120, 60)], ['Accept' => 'application/json'])->assertOk();
        $meta = $this->project->workspace->fresh()->branding['assets']['logo'];
        $this->assertStringNotContainsString('script', Storage::disk('local')->get($meta['svgPath']));
        $this->post($this->root().'/sanitize-svg', ['file' => UploadedFile::fake()->createWithContent('logo.svg', '<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg">&x;</svg>')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post($this->root().'/assets/logo', ['file' => UploadedFile::fake()->createWithContent('logo.png', '<?php bad();')], ['Accept' => 'application/json'])->assertUnprocessable();
    }

    public function test_reset_removes_visuals_but_keeps_company_records_and_contact_data(): void
    {
        $root = $this->root();
        $this->patchJson($root, ['section' => 'business', 'values' => ['account' => 'KEPT_ACCOUNT']])->assertOk();
        $this->patchJson($root, ['section' => 'contact', 'values' => ['email' => 'kept@example.test']])->assertOk();
        $this->patchJson($root, ['section' => 'visual', 'values' => ['companyName' => 'Remove me', 'primaryColor' => '#17695d']])->assertOk();
        $this->postJson($root.'/reset')->assertOk()->assertJsonPath('data.visual.companyName', '')->assertJsonPath('data.business.account', 'KEPT_ACCOUNT')->assertJsonPath('data.contact.email', 'kept@example.test');
    }

    public function test_portal_never_exposes_company_records_and_export_flags_control_private_fields_in_both_formats(): void
    {
        $root = $this->root();
        $this->patchJson($root, ['section' => 'visual', 'values' => ['companyName' => 'Studio 中文 Çığ']])->assertOk();
        $this->patchJson($root, ['section' => 'business', 'values' => ['account' => 'PRIVATE_IBAN']])->assertOk();
        $this->patchJson($root, ['section' => 'contact', 'values' => ['address' => 'PRIVATE_ADDRESS', 'email' => 'private@example.test']])->assertOk();
        Task::factory()->for($this->project)->create();
        $this->post($root.'/assets/logo', ['file' => UploadedFile::fake()->image('logo.png', 300, 100)], ['Accept' => 'application/json'])->assertOk();
        $builder = app(ProjectExportData::class);
        $document = $builder->build($this->project->fresh(), 'all', 'en');
        $this->assertSame([], $document['branding']['business']);
        $this->assertSame([], $document['branding']['address']);
        $this->patchJson($root, ['section' => 'export', 'values' => ['business' => true, 'address' => true, 'contact' => true, 'platform' => false]])->assertOk();
        $this->project->unsetRelation('workspace');
        $document = $builder->build($this->project, 'all', 'zh-CN');
        $this->assertStringContainsString('PRIVATE_IBAN', json_encode($document));
        $this->assertSame(false, $document['branding']['platform']);
        $bytes = app(DocxProjectExporter::class)->render($document);
        $path = tempnam(sys_get_temp_dir(), 'brand-docx-');
        file_put_contents($path, $bytes);
        $zip = new \ZipArchive;
        $zip->open($path);
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($path);
        $this->assertStringContainsString('PRIVATE_IBAN', $xml);
        $this->assertStringContainsString('Studio 中文 Çığ', $xml);
        $token = $this->project->accessTokens()->create(['project_client_id' => $this->project->clients()->first()->id, 'token_hash' => hash('sha256', 'test')]);
        $this->app['auth']->forgetGuards();
        $this->withSession(['client_project_id' => $this->project->id, 'project_client_id' => $token->project_client_id, 'client_access_token_id' => $token->id]);
        $response = $this->getJson('/api/client/project/'.$this->project->uuid)->assertOk()->assertJsonPath('data.branding.companyName', 'Studio 中文 Çığ');
        $this->assertStringNotContainsString('PRIVATE_IBAN', $response->getContent());
        $this->assertStringNotContainsString('private@example.test', $response->getContent());
        $this->getJson($root)->assertUnauthorized();
    }

    public function test_project_emails_and_client_invites_use_branding_but_platform_security_mail_does_not(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.test', 'mail.from.address' => 'platform@example.test']);
        $root = $this->root();
        $this->patchJson($root, ['section' => 'visual', 'values' => ['companyName' => '<script>Company</script>', 'primaryColor' => '#17695d']])->assertOk();
        $this->patchJson($root, ['section' => 'email', 'values' => ['enabled' => true, 'companyName' => true, 'accent' => true]])->assertOk();
        $payload = NotificationPayload::test('en', 'https://example.test');
        $payload['project_uuid'] = $this->project->uuid;
        app(ProjectNotificationService::class)->sendMail('owner@example.test', $payload);
        $mail = Mail::sent(ProjectActivityMail::class)->first();
        $html = $mail->render();
        $this->assertStringContainsString('&lt;script&gt;Company&lt;/script&gt;', $html);
        $this->assertStringContainsString('#17695d', $html);
        $client = $this->project->clients()->first();
        $client->update(['email' => 'client@example.test']);
        app(ClientInvitations::class)->deliver($this->project, $client, 'https://example.test/access/test');
        $invite = Mail::sent(AccountActionMail::class)->first();
        $this->assertNotNull($invite->branding);
        $this->assertStringContainsString('/access/test', $invite->render());
        app(ClientInvitations::class)->deliver($this->project, $client, 'https://example.test/access/login', login: true);
        $login = Mail::sent(AccountActionMail::class)->last();
        $this->assertSame($invite->branding, $login->branding);
        $this->assertSame(trans('account.client_open_project', [], 'en'), $login->actionLabel);
        $this->assertStringContainsString('&lt;script&gt;Company&lt;/script&gt;', $login->render());
        $this->assertStringContainsString('#17695d', $login->render());
        app(AccountMail::class)->localized('user@example.test', 'en', 'reset', 'https://example.test/reset');
        $system = Mail::sent(AccountActionMail::class)->last();
        $this->assertNull($system->branding);
        $this->assertStringNotContainsString('Company', $system->render());
        $this->patchJson($root, ['section' => 'email', 'values' => ['enabled' => false]])->assertOk();
        app(ProjectNotificationService::class)->sendMail('owner@example.test', $payload);
        $this->assertNull(Mail::sent(ProjectActivityMail::class)->last()->payload['branding']);
    }

    public function test_multilingual_branded_export_qa_artifacts(): void
    {
        $directory = getenv('BRANDING_EXPORT_QA_DIRECTORY');
        if ($directory && ! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        $image = imagecreatetruecolor(360, 100);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        $green = imagecolorallocate($image, 23, 105, 93);
        imagefilledrectangle($image, 0, 0, 88, 88, $green);
        imagestring($image, 5, 38, 36, 'A', imagecolorallocate($image, 255, 255, 255));
        imagestring($image, 5, 110, 35, 'ASTER STUDIO', $green);
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);
        $this->post($this->root().'/assets/logo', ['file' => UploadedFile::fake()->createWithContent('logo.png', $png)], ['Accept' => 'application/json'])->assertOk();
        $workspace = $this->project->workspace->fresh();
        $settings = app(WorkspaceBranding::class)->settings($workspace);
        $settings['visual'] = ['companyName' => 'ASTER Studio — Студия / Çığ / 中文', 'primaryColor' => '#17695d'];
        $settings['business'] = array_replace($settings['business'], ['legalName' => 'ASTER Digital Ltd', 'taxId' => 'TR TEST 123456', 'bankName' => 'Example International Bank', 'payee' => 'ASTER Digital Ltd', 'account' => 'TR00 0000 0000 0000 0000 0000 00', 'bic' => 'EXAMPLEXX', 'note' => 'Test fixture only / Тестовые реквизиты / 测试数据']);
        $settings['contact'] = array_replace($settings['contact'], ['country' => 'Türkiye', 'city' => 'İstanbul', 'address' => 'Tasarım Sokak 14', 'phone' => '+90 212 000 00 00', 'email' => 'hello@example.test', 'website' => 'https://agency.example.test']);
        $settings['export'] = array_fill_keys(array_keys(WorkspaceBranding::EXPORT_DEFAULTS), true);
        $workspace->update(['branding' => $settings]);
        $this->project->unsetRelation('workspace');
        Task::factory()->for($this->project)->create(['title' => 'Личный кабинет / Müşteri hesabı / 客户账户', 'description' => 'Кириллица и детали проекта. Türkçe: Çığ İpek Şule. 中文：项目需求与用户界面。', 'status' => 'done', 'estimate_hours' => 12, 'price' => 450]);
        foreach (['ru', 'en', 'tr', 'zh-CN'] as $locale) {
            $document = app(ProjectExportData::class)->build($this->project, 'all', $locale, ['price' => true, 'estimateHours' => true], ['documentTitle' => 'Project specification / Техническое задание / 项目说明']);
            if ($directory) {
                file_put_contents($directory.'/branded-'.$locale.'.json', json_encode(['schemaVersion' => 1, 'filename' => 'branded-'.$locale.'.pdf', 'document' => $document], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            }
            if ($directory) {
                file_put_contents($directory.'/branded-'.$locale.'.docx', app(DocxProjectExporter::class)->render($document));
            }
            $this->assertNotEmpty($document['branding']['logo']);
        }
        $settings['export'] = array_replace(WorkspaceBranding::EXPORT_DEFAULTS, ['logo' => false, 'companyName' => false, 'platform' => false]);
        $workspace->update(['branding' => $settings]);
        $this->project->unsetRelation('workspace');
        $document = app(ProjectExportData::class)->build($this->project, 'all', 'en');
        if ($directory) {
            file_put_contents($directory.'/unbranded.json', json_encode(['schemaVersion' => 1, 'filename' => 'unbranded.pdf', 'document' => $document], JSON_THROW_ON_ERROR));
            file_put_contents($directory.'/unbranded.docx', app(DocxProjectExporter::class)->render($document));
        }
        $this->assertNull($document['branding']['logo']);
    }
}
