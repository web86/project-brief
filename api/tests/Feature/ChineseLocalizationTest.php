<?php

namespace Tests\Feature;

use App\Http\Middleware\SetRequestLocale;
use App\Mail\AccountActionMail;
use App\Models\DeveloperInvitation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Services\DeveloperInvitations;
use App\Services\DocxProjectExporter;
use App\Services\ExportFonts;
use App\Services\ProjectExportData;
use App\Services\SupportedLocales;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PhpOffice\PhpWord\Settings;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;
use ZipArchive;

class ChineseLocalizationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_browser_aliases_do_not_convert_traditional_chinese_to_simplified(): void
    {
        foreach (['zh-CN', 'zh-Hans', 'zh-SG', 'zh_Hans_CN', 'zh-Hans-SG'] as $tag) {
            $this->assertSame('zh-CN', SupportedLocales::browser($tag));
            $request = Request::create('/api/health', server: ['HTTP_ACCEPT_LANGUAGE' => $tag]);
            $response = (new SetRequestLocale)->handle($request, fn () => response(app()->getLocale()));
            $this->assertSame('zh-CN', $response->getContent());
        }
        foreach (['zh', 'zh-Hant', 'zh-TW', 'zh-HK', 'zh-MO', 'zh-Hant-CN'] as $tag) {
            $this->assertSame('en', SupportedLocales::browser($tag));
        }
    }

    public function test_profile_roundtrip_uses_existing_two_character_columns_and_canonical_api_codes(): void
    {
        $user = User::factory()->owner()->create();
        $this->actingAs($user)->patchJson('/api/admin/me/locale', ['locale' => 'zh-CN'])->assertOk()->assertJsonPath('preferredLocale', 'zh-CN');
        $this->assertSame('zh', DB::table('users')->where('id', $user->id)->value('preferred_locale'));
        $this->getJson('/api/admin/account')->assertOk()->assertJsonPath('user.preferredLocale', 'zh-CN');
        $this->patchJson('/api/admin/account', ['name' => '王小明', 'preferredLocale' => 'zh-CN'])->assertOk()->assertJsonPath('user.preferredLocale', 'zh-CN');
        $this->patchJson('/api/admin/me/locale', ['locale' => 'zh-Hant'])->assertUnprocessable();
        $this->app['auth']->forgetGuards();
        $project = Project::factory()->create();
        $client = $project->clients()->first();
        $other = $project->clients()->create(['name' => 'Other']);
        $token = $project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', random_bytes(32))]);
        $this->withSession(['client_project_id' => $project->id, 'project_client_id' => $client->id, 'client_access_token_id' => $token->id]);
        $this->patchJson('/api/client/me/locale', ['locale' => 'zh-CN'])->assertOk()->assertJsonPath('preferredLocale', 'zh-CN');
        $this->getJson('/api/client/me')->assertOk()->assertJsonPath('preferredLocale', 'zh-CN');
        $this->assertSame('zh', DB::table('project_clients')->where('id', $client->id)->value('preferred_locale'));
        $this->assertNull($other->fresh()->preferred_locale);
        $this->patchJson('/api/client/me/locale', ['locale' => 'zh-CN', 'project_client_id' => $other->id])->assertUnprocessable();
        $this->assertSame('zh-CN', SupportedLocales::resolve('zh'));
        foreach (SupportedLocales::codes() as $locale) {
            $this->assertLessThanOrEqual(2, strlen(SupportedLocales::storage($locale)));
        }
    }

    public function test_chinese_account_and_invitation_mail_and_telegram_use_stored_recipient_preference(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.test', 'mail.from.address' => 'sender@example.test']);
        $user = User::factory()->create(['preferred_locale' => 'zh']);
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project, ['preferred_locale' => 'ru']);
        $invitation = DeveloperInvitation::create(['workspace_id' => $project->workspace_id, 'invited_by' => $owner->id, 'email' => $user->email, 'project_ids' => [$project->id], 'locale' => 'ru', 'token_hash' => hash('sha256', random_bytes(32)), 'expires_at' => now()->addDays(7)]);
        $this->assertTrue(app(DeveloperInvitations::class)->deliver($invitation));
        Mail::assertSent(AccountActionMail::class, function ($mail): bool {
            $this->assertSame('zh-CN', $mail->language);
            $mail->assertSeeInHtml('团队邀请');
            $mail->assertSeeInText('您受邀加入工作区');

            return true;
        });
        config(['telegram.transport' => 'direct', 'telegram.bot_token' => '123456:abcdefghijklmnopqrstuvwxyzABCDEF', 'telegram.bot_username' => 'ProjectBriefTestBot', 'telegram.webhook_secret' => str_repeat('s', 40)]);
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        TelegramConnection::factory()->create(['user_id' => $user->id, 'chat_id' => '12345']);
        $this->postJson('/api/telegram/webhook', ['update_id' => 123, 'message' => ['text' => '/start', 'chat' => ['id' => 12345, 'type' => 'private'], 'from' => ['id' => 12345]]], ['X-Telegram-Bot-Api-Secret-Token' => str_repeat('s', 40)])->assertOk();
        Http::assertSent(fn ($request) => $request['text'] === trans('notifications.telegram.welcome', [], 'zh-CN'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_cjk_exports_embed_real_fonts_without_translating_user_text_or_changing_currency(): void
    {
        $original = str_repeat('这是客户提供的原始中文需求，不应被自动翻译。', 6).' Çığ ışığı İpek — исходный текст';
        $task = Task::factory()->create(['title' => '简体中文导出测试：网站改进需求', 'description' => $original, 'status' => 'done', 'estimate_hours' => '1234.50', 'price' => '1234567.89']);
        $task->project->update(['currency' => 'USD']);
        $document = app(ProjectExportData::class)->build($task->project, 'all', 'zh-CN', ['estimateHours' => true, 'price' => true]);
        $this->assertTrue(ExportFonts::needsCjk($document));
        $this->assertSame('技术需求说明书', $document['title']);
        $this->assertMatchesRegularExpression('/^\d{4}年\d{1,2}月\d{1,2}日$/u', $document['generated']['date']);
        $this->assertStringContainsString('1,234,567.89', json_encode($document, JSON_UNESCAPED_UNICODE));
        $this->assertSame($original, $document['sections'][0]['tasks'][0]['content'][0]['value']);
        $bytes = app(DocxProjectExporter::class)->render($document);
        $path = tempnam(sys_get_temp_dir(), 'chinese-docx-');
        try {
            file_put_contents($path, $bytes);
            unset($bytes);
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path));
            $this->assertStringContainsString($original, $zip->getFromName('word/document.xml'));
            $this->assertStringContainsString('w:eastAsia="Noto Sans SC"', $zip->getFromName('word/styles.xml'));
            $this->assertStringContainsString('w:embedTrueTypeFonts', $zip->getFromName('word/settings.xml'));
            $fonts = new DOMDocument;
            $fonts->loadXML($zip->getFromName('word/fontTable.xml'));
            $query = new DOMXPath($fonts);
            $query->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            foreach (['Regular', 'Bold'] as $weight) {
                $embedding = $query->query('//w:font[@w:name="Noto Sans SC"]/w:embed'.$weight)->item(0);
                $this->assertNotNull($embedding);
                $key = $embedding->getAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'fontKey');
                $mask = strrev(hex2bin(str_replace(['{', '}', '-'], '', $key)));
                $font = $zip->getStream('word/fonts/NotoSansSC-'.$weight.'.odttf');
                $header = fread($font, 32);
                for ($index = 0; $index < 32; $index++) {
                    $header[$index] = $header[$index] ^ $mask[$index % 16];
                }
                $hash = hash_init('sha256');
                hash_update($hash, $header);
                hash_update_stream($hash, $font);
                fclose($font);
                $this->assertSame(hash_file('sha256', ExportFonts::path($weight).'.ttf'), hash_final($hash));
            }
            $this->assertStringContainsString('SIL OPEN FONT LICENSE', $zip->getFromName('word/fonts/NotoSansSC-OFL.txt'));
            $zip->close();
        } finally {
            unlink($path);
        }
        $latin = app(ProjectExportData::class)->build($task->project, 'all', 'en');
        $this->assertTrue(ExportFonts::needsCjk($latin));
        $this->assertSame('USD', $task->project->fresh()->currency);
        $latinTask = Task::factory()->create(['title' => 'Original Latin title', 'description' => 'Original content']);
        $latinDocument = app(ProjectExportData::class)->build($latinTask->project, 'all', 'en');
        $this->assertFalse(ExportFonts::needsCjk($latinDocument));
        $this->assertSame('Arial', Settings::getDefaultAsianFontName());
    }
}
