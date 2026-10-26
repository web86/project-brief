<?php

namespace Tests\Feature;

use App\Events\ProjectNotificationRequested;
use App\Http\Middleware\SetRequestLocale;
use App\Mail\AccountActionMail;
use App\Mail\ProjectActivityMail;
use App\Mail\VerifyNotificationEmail;
use App\Models\Comment;
use App\Models\DeveloperInvitation;
use App\Models\Project;
use App\Models\ProjectClient;
use App\Models\PushSubscription;
use App\Models\Task;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Services\DeveloperInvitations;
use App\Services\DocxProjectExporter;
use App\Services\NotificationEvents;
use App\Services\NotificationPayload;
use App\Services\PdfProjectExporter;
use App\Services\ProjectExportData;
use App\Services\ProjectNotificationService;
use App\Services\SupportedLocales;
use App\Services\TelegramTransport;
use App\Services\WebPushTransport;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;
use ZipArchive;

class MultilingualTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.test', 'mail.from.address' => 'sender@example.test']);
    }

    public function test_dictionaries_have_exact_keys_nonempty_values_and_matching_parameters(): void
    {
        foreach (['notifications', 'exports', 'account', 'interface'] as $group) {
            $reference = Arr::dot(require lang_path('en/'.$group.'.php'));
            foreach (SupportedLocales::codes() as $locale) {
                $messages = Arr::dot(require lang_path($locale.'/'.$group.'.php'));
                $this->assertEqualsCanonicalizing(array_keys($reference), array_keys($messages));
                foreach ($messages as $key => $message) {
                    $this->assertNotSame('', trim($message), $locale.'.'.$group.'.'.$key);
                    preg_match_all('/(?<!\w):([a-zA-Z_]+)\b/', $message, $actual);
                    preg_match_all('/(?<!\w):([a-zA-Z_]+)\b/', $reference[$key], $expected);
                    $this->assertEqualsCanonicalizing(array_unique($expected[1]), array_unique($actual[1]), $locale.'.'.$group.'.'.$key);
                }
            }
        }
        $this->assertSame('en', SupportedLocales::resolve('de'));
        $this->assertSame('en', SupportedLocales::resolve(null));
    }

    public function test_admin_account_persists_supported_locales_and_rejects_other_fields(): void
    {
        $user = User::factory()->owner()->create();
        $other = User::factory()->owner()->create();
        foreach (SupportedLocales::codes() as $locale) {
            $this->actingAs($user)->patchJson('/api/admin/me/locale', ['locale' => $locale])->assertOk()->assertJsonPath('preferredLocale', $locale);
            $this->getJson('/api/admin/account')->assertOk()->assertJsonPath('user.preferredLocale', $locale);
            $this->patchJson('/api/admin/account', ['name' => 'İpek Işık', 'preferredLocale' => $locale])->assertOk()->assertJsonPath('user.preferredLocale', $locale);
            $this->assertSame($locale, $user->fresh()->preferred_locale);
        }
        $this->patchJson('/api/admin/account', ['name' => 'İpek', 'preferredLocale' => 'de'])->assertUnprocessable();
        $this->patchJson('/api/admin/me/locale', ['locale' => 'tr', 'user_id' => $other->id])->assertUnprocessable();
        $this->assertNull($other->fresh()->preferred_locale);
    }

    public function test_all_events_localize_each_recipient_across_email_telegram_and_push(): void
    {
        $task = Task::factory()->create(['title' => 'Original title çğışİöü', 'description' => 'Описание пользователя — unchanged']);
        $project = $task->project;
        $project->clients()->delete();
        $pushLanguages = $telegramLanguages = [];
        foreach (SupportedLocales::codes() as $locale) {
            $admin = $this->ownerFor($project, ['preferred_locale' => $locale, 'notification_preferences' => ['email' => true, 'push' => true, 'telegram' => true]]);
            $client = ProjectClient::factory()->for($project)->create(['preferred_locale' => $locale, 'notification_preferences' => ['email' => true, 'push' => true, 'telegram' => true]]);
            $project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', random_bytes(32))]);
            foreach ([$admin, $client] as $recipient) {
                $owner = $recipient instanceof User ? ['user_id' => $recipient->id, 'project_client_id' => null] : ['user_id' => null, 'project_client_id' => $recipient->id];
                $subscription = PushSubscription::factory()->create($owner);
                $connection = TelegramConnection::factory()->create($owner);
                $pushLanguages[$subscription->id] = $telegramLanguages[$connection->id] = $locale;
            }
        }
        $push = Mockery::mock(WebPushTransport::class);
        $push->shouldReceive('available')->andReturnTrue();
        $push->shouldReceive('send')->times(count(NotificationEvents::CODES) * 3)->andReturnUsing(function ($subscription, $payload) use ($pushLanguages): array {
            $locale = $pushLanguages[$subscription->id];
            $heading = str_contains($payload['event'], 'project_comment') ? trans('notifications.discussion_heading', [], $locale) : trans('notifications.events.'.$payload['event'], [], $locale);
            $this->assertStringStartsWith($heading, $payload['title']);

            return ['status' => 'sent', 'error_code' => null];
        });
        $telegram = Mockery::mock(TelegramTransport::class);
        $telegram->shouldReceive('send')->times(count(NotificationEvents::CODES) * 3)->andReturnUsing(function ($connection, $payload) use ($telegramLanguages): array {
            $this->assertStringContainsString(trans('notifications.project', [], $telegramLanguages[$connection->id]), $payload['text']);
            $this->assertStringContainsString('Original title', $payload['text']);

            return ['status' => 'sent', 'error_code' => null];
        });
        $service = new ProjectNotificationService($push, $telegram);
        $service->settings($project)->update(['delivery_mode' => 'personal', 'event_rules' => array_fill_keys(NotificationEvents::CODES, ['enabled' => true, 'recipients' => ['mode' => 'all', 'ids' => []]])]);
        foreach (NotificationEvents::CODES as $event) {
            Mail::fake();
            $actor = str_starts_with($event, 'client.') ? 'client' : 'admin';
            $discussion = str_contains($event, 'project_comment');
            $comment = Comment::create(['task_id' => $discussion ? null : $task->id, 'project_id' => $discussion ? $project->id : null, 'author_type' => $actor, 'text' => 'Original title — пользовательский комментарий çğışİöü']);
            $history = $task->history()->create(['actor_type' => $actor, 'event_type' => str_contains($event, 'comment') ? 'comment' : 'status', 'meta' => ['commentId' => $comment->id]]);
            $service->notify(new ProjectNotificationRequested($history->id, $event, $discussion ? null : $task->id, $actor, null, 'Русский администратор', $discussion ? $comment->id : null));
            Mail::assertSentCount(3);
            foreach (SupportedLocales::codes() as $locale) {
                Mail::assertSent(ProjectActivityMail::class, function ($mail) use ($locale): bool {
                    if ($mail->payload['locale'] !== $locale) {
                        return false;
                    }
                    $mail->assertSeeInHtml($mail->payload['heading']);
                    $mail->assertSeeInText($mail->payload['open']);

                    return true;
                });
            }
        }
    }

    public function test_service_mail_uses_recipient_language_in_html_text_and_subject(): void
    {
        foreach (SupportedLocales::codes() as $locale) {
            Mail::fake();
            $user = User::factory()->owner()->create(['preferred_locale' => $locale, 'password' => 'Current-password']);
            $this->postJson('/api/admin/password/forgot', ['email' => $user->email])->assertOk();
            Mail::assertSent(AccountActionMail::class, function ($mail) use ($locale): bool {
                $this->assertSame($locale, $mail->language);
                $this->assertSame(trans('account.reset_heading', [], $locale).' — Project Brief', $mail->envelope()->subject);
                $mail->assertSeeInHtml(trans('account.reset_message', [], $locale));
                $mail->assertSeeInText(trans('account.reset_message', [], $locale));

                return true;
            });
            Mail::fake();
            $this->actingAs($user)->postJson('/api/admin/account/email', ['email' => $locale.'@new.example.test', 'current_password' => 'Current-password'])->assertOk();
            Mail::assertSent(AccountActionMail::class, fn ($mail) => $mail->language === $locale && $mail->heading === trans('account.confirm_heading', [], $locale));
            $mail = new VerifyNotificationEmail('https://example.test/#verify=123', $locale);
            $mail->assertSeeInHtml(trans('notifications.verify_heading', [], $locale));
            $mail->assertSeeInText(trans('notifications.verify_text', [], $locale));
            $this->app['auth']->forgetGuards();
        }
    }

    public function test_invitation_uses_recipient_preference_instead_of_sender_language(): void
    {
        $user = User::factory()->create(['preferred_locale' => 'tr']);
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project, ['preferred_locale' => 'ru']);
        $invitation = DeveloperInvitation::create(['workspace_id' => $project->workspace_id, 'invited_by' => $owner->id, 'email' => $user->email, 'project_ids' => [$project->id], 'locale' => 'ru', 'token_hash' => hash('sha256', random_bytes(32)), 'expires_at' => now()->addDays(7)]);
        $this->assertTrue(app(DeveloperInvitations::class)->deliver($invitation));
        Mail::assertSent(AccountActionMail::class, function ($mail): bool {
            $this->assertSame('tr', $mail->language);
            $mail->assertSeeInHtml('Ekip daveti');
            $mail->assertSeeInText('çalışma alanına davet edildiniz');

            return true;
        });
    }

    public function test_turkish_exports_preserve_content_currency_exact_amounts_and_unicode(): void
    {
        $task = Task::factory()->create(['title' => 'Çığ ışığı — İpek Özgür Şule', 'description' => 'Описание клиента — original çğışİöü', 'status' => 'done', 'estimate_hours' => '1234.50', 'price' => '1234567.89']);
        $owner = $this->ownerFor($task->project, ['preferred_locale' => 'ru']);
        $task->project->update(['currency' => 'USD']);
        $document = app(ProjectExportData::class)->build($task->project, 'all', 'tr', ['estimateHours' => true, 'price' => true]);
        $json = json_encode($document, JSON_UNESCAPED_UNICODE);
        foreach (['Teknik şartname', 'Tamamlandı', '1.234,5 sa', "1.234.567,89\u{00A0}$", $task->description, $task->title] as $text) {
            $this->assertStringContainsString($text, $json);
        }
        $bytes = app(DocxProjectExporter::class)->render($document);
        $file = tempnam(sys_get_temp_dir(), 'turkish-docx-');
        try {
            file_put_contents($file, $bytes);
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($file));
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
            $this->assertStringContainsString($task->title, $xml);
            $this->assertStringContainsString('Teknik şartname', $xml);
        } finally {
            unlink($file);
        }
        $pdf = app(PdfProjectExporter::class)->render($document);
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('DejaVuSans', $pdf);
        foreach (['pdf', 'docx'] as $format) {
            $this->actingAs($owner)->postJson('/api/admin/projects/'.$task->project->uuid.'/export', ['locale' => 'tr', 'format' => $format, 'scope' => 'all'])->assertOk();
        }
        $this->assertSame('USD', $task->project->fresh()->currency);
        $this->assertSame('ru', $owner->fresh()->preferred_locale);
    }

    public function test_request_locale_resolves_manual_profile_browser_and_english_fallback(): void
    {
        $user = User::factory()->create(['preferred_locale' => 'tr']);
        foreach ([['ru', $user, 'ru'], ['en-GB', $user, 'tr'], ['tr-TR,tr;q=0.9', null, 'tr'], ['ru-RU', null, 'ru'], ['de-DE', null, 'en']] as [$header, $profile, $expected]) {
            $request = Request::create('/api/health', server: ['HTTP_ACCEPT_LANGUAGE' => $header]);
            $request->setUserResolver(fn () => $profile);
            $response = (new SetRequestLocale)->handle($request, fn () => response(app()->getLocale()));
            $this->assertSame($expected, $response->getContent());
        }
    }

    public function test_turkish_bot_welcome_and_attachment_only_notifications_are_localized(): void
    {
        config(['telegram.transport' => 'direct', 'telegram.bot_token' => '123456:abcdefghijklmnopqrstuvwxyzABCDEF', 'telegram.bot_username' => 'ProjectBriefTestBot', 'telegram.webhook_secret' => str_repeat('s', 40)]);
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $user = User::factory()->owner()->create(['preferred_locale' => 'tr']);
        TelegramConnection::factory()->create(['user_id' => $user->id, 'chat_id' => '12345']);
        $this->postJson('/api/telegram/webhook', ['update_id' => 123, 'message' => ['text' => '/start', 'chat' => ['id' => 12345, 'type' => 'private'], 'from' => ['id' => 12345]]], ['X-Telegram-Bot-Api-Secret-Token' => str_repeat('s', 40)])->assertOk();
        Http::assertSent(fn ($request) => $request['text'] === trans('notifications.telegram.welcome', [], 'tr'));
        $task = Task::factory()->create();
        $comment = $task->comments()->create(['text' => '', 'author_type' => 'admin']);
        $comment->setRelation('attachments', collect([(object) ['mime_type' => 'image/png']]));
        $this->assertSame('Geliştirici bir görsel ekledi', NotificationPayload::commentText($comment, 'tr'));
        $comment->setRelation('attachments', collect([(object) ['mime_type' => 'text/plain'], (object) ['mime_type' => 'text/plain']]));
        $this->assertSame('Geliştirici 2 dosya ekledi', NotificationPayload::commentText($comment, 'tr'));
    }

    public function test_new_project_default_sections_follow_request_language_without_renaming_existing_content(): void
    {
        $user = User::factory()->owner()->create();
        foreach (SupportedLocales::codes() as $locale) {
            $response = $this->actingAs($user)->withHeader('Accept-Language', $locale)->postJson('/api/admin/projects', ['title' => 'Original çğışİöü', 'currency' => 'USD'])->assertCreated();
            $project = Project::where('uuid', $response->json('data.id'))->firstOrFail();
            $this->assertSame(trans('interface.sections', [], $locale), $project->briefSections()->orderBy('position')->pluck('name')->all());
            $this->assertSame('Original çğışİöü', $project->title);
            $this->assertSame('USD', $project->currency);
        }
    }
}
