<?php

namespace Tests\Feature;

use App\Models\DeveloperInvitation;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Services\WorkspaceAgreements;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\WebAuthnDevice;
use Tests\TestCase;

class WorkspaceAgreementTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function payload(bool $enabled = true, string $date = '2026-10-30'): array
    {
        return ['enabled' => $enabled, 'publish' => true, 'requireAgain' => false, 'baseRevision' => null, 'draft' => ['enabledLocales' => WorkspaceAgreements::LOCALES, 'defaultLocale' => 'ru', 'date' => $date, 'texts' => array_fill_keys(WorkspaceAgreements::LOCALES, [['type' => 'heading', 'content' => [['text' => 'Workspace agreement', 'bold' => true]]], ['type' => 'paragraph', 'content' => [['text' => 'Terms <img src=x onerror=alert(1)>', 'italic' => true]]]])]];
    }

    private function publish(): array
    {
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project);
        $this->actingAs($owner)->putJson('/api/admin/settings/agreement', $this->payload())->assertOk();

        return [$project, $owner, $project->workspace->fresh()->administrator_agreement['published']];
    }

    private function invited(Project $project, User $owner): User
    {
        $this->actingAs($owner);
        $link = $this->postJson('/api/admin/administrators/invitations', ['email' => Str::random(10).'@example.test', 'sendEmail' => false, 'generateLink' => true])->assertCreated()->json('link');
        parse_str(parse_url($link, PHP_URL_FRAGMENT), $fragment);
        $this->postJson('/api/admin/logout')->assertOk();
        $this->postJson('/api/invitations/accept', ['token' => $fragment['token'], 'name' => 'Invited admin', 'password' => 'Long-password-93', 'password_confirmation' => 'Long-password-93'])->assertOk();

        return User::where('email', DeveloperInvitation::latest('id')->first()->email)->firstOrFail();
    }

    public function test_safe_links_survive_draft_publication_and_required_document_without_extra_html_attributes(): void
    {
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project);
        $payload = $this->payload();
        foreach (WorkspaceAgreements::LOCALES as $locale) {
            $payload['draft']['texts'][$locale][1]['content'] = [
                ['text' => 'Rules <script>inert</script>', 'href' => 'https://example.test/rules?a=1&b=2', 'bold' => true, 'italic' => true],
                ['text' => 'Contact', 'href' => 'mailto:help@example.test'],
                ['text' => 'HTTP', 'href' => 'http://example.test/'],
            ];
        }
        $this->actingAs($owner);
        $draft = [...$payload, 'enabled' => false, 'publish' => false];
        $this->putJson('/api/admin/settings/agreement', $draft)->assertOk()->assertJsonPath('data.published', null)->assertJsonPath('data.draft.texts.en.1.content.1.href', 'mailto:help@example.test');
        $this->getJson('/api/public/workspaces/'.$project->workspace->uuid.'/agreement')->assertNotFound();
        $document = $this->putJson('/api/admin/settings/agreement', $payload)->assertOk()->assertJsonPath('data.draft.texts.en.1.content.0.href', 'https://example.test/rules?a=1&b=2')->json('data.published');
        $this->getJson('/api/public/workspaces/'.$project->workspace->uuid.'/agreement')->assertOk()->assertJsonPath('document.texts.tr.1.content.0.href', 'https://example.test/rules?a=1&b=2');
        $this->invited($project, $owner);
        $this->getJson('/api/admin/agreement')->assertOk()->assertJsonPath('required', true)->assertJsonPath('document.texts.zh-CN.1.content.0.bold', true)->assertJsonPath('document.texts.ru.1.content.1.href', 'mailto:help@example.test');
        $this->postJson('/api/admin/agreement/accept', ['revision' => $document['id'], 'locale' => 'zh-CN'])->assertOk();
        $this->assertDatabaseCount('workspace_agreement_acceptances', 1);
    }

    public function test_unsafe_link_urls_and_non_allowlisted_attributes_are_rejected_even_in_drafts(): void
    {
        $project = Project::factory()->create();
        $this->actingAs($this->ownerFor($project));
        $payload = $this->payload(false);
        $payload['publish'] = false;
        $original = $project->workspace->fresh()->administrator_agreement;
        $path = 'draft.texts.en.1.content.0';
        foreach (['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html,test', 'vbscript:alert(1)', '//evil.test', '/relative', 'https:///evil.test', 'https://u:p@example.test', 'https://example.test\\@evil.test', "https://exam\nple.test/", 'mailto:a@example.test?subject=x', 'mailto:a@example.test%0aBcc:evil@example.test', ''] as $href) {
            $payload['draft']['texts']['en'][1]['content'][0]['href'] = $href;
            $this->putJson('/api/admin/settings/agreement', $payload)->assertUnprocessable()->assertJsonValidationErrors($path.'.href');
        }
        unset($payload['draft']['texts']['en'][1]['content'][0]['href']);
        foreach (['onclick', 'style', 'target', 'rel'] as $attribute) {
            $tampered = $payload;
            $tampered['draft']['texts']['en'][1]['content'][0][$attribute] = 'Injected';
            $this->putJson('/api/admin/settings/agreement', $tampered)->assertUnprocessable()->assertJsonValidationErrors($path);
        }
        $this->assertSame($original, $project->workspace->fresh()->administrator_agreement);
    }

    public function test_default_is_disabled_and_invitation_preserves_existing_flow(): void
    {
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project);
        $this->actingAs($owner)->getJson('/api/admin/settings/agreement')->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.published', null);
        $this->invited($project, $owner);
        $this->getJson('/api/session')->assertJsonMissingPath('agreementRequired');
        $this->getJson('/api/admin/projects')->assertOk();
        $this->assertDatabaseCount('workspace_agreement_acceptances', 0);
    }

    public function test_invited_administrator_is_gated_on_every_business_api_until_server_acceptance(): void
    {
        [$project, $owner, $document] = $this->publish();
        $user = $this->invited($project, $owner);
        $this->getJson('/api/session')->assertJsonPath('agreementRequired', true);
        foreach (['/api/admin/me', '/api/admin/projects', '/api/admin/account', '/api/admin/notifications', '/api/admin/sync', '/api/admin/integrations', '/api/admin/administrators'] as $path) {
            $this->getJson($path)->assertForbidden()->assertJsonPath('code', 'administrator_agreement_required');
        }
        $this->postJson('/api/admin/projects', ['title' => 'Bypass'])->assertForbidden()->assertJsonPath('code', 'administrator_agreement_required');
        $this->getJson('/api/security/status')->assertOk();
        $this->getJson('/api/admin/agreement')->assertOk()->assertJsonPath('required', true);
        $this->postJson('/api/admin/agreement/accept', ['revision' => (string) Str::uuid(), 'locale' => 'en'])->assertConflict();
        foreach (['en', 'ru'] as $locale) {
            $this->postJson('/api/admin/agreement/accept', ['revision' => $document['id'], 'locale' => $locale])->assertOk();
        }
        $this->assertDatabaseCount('workspace_agreement_acceptances', 1);
        $this->assertDatabaseHas('workspace_agreement_acceptances', ['workspace_id' => $project->workspace_id, 'user_id' => $user->id, 'version' => '2026-10-30', 'locale' => 'en']);
        $this->getJson('/api/admin/projects')->assertOk();
        $this->actingAs($user)->getJson('/api/session')->assertJsonMissingPath('agreementRequired');
    }

    public function test_all_four_acceptance_languages_are_recorded_and_interface_language_does_not_require_acceptance_again(): void
    {
        [$project, $owner, $document] = $this->publish();
        foreach (WorkspaceAgreements::LOCALES as $locale) {
            $user = $this->invited($project, $owner);
            $this->postJson('/api/admin/agreement/accept', ['revision' => $document['id'], 'locale' => $locale])->assertOk();
            $this->assertDatabaseHas('workspace_agreement_acceptances', ['user_id' => $user->id, 'workspace_id' => $project->workspace_id, 'locale' => $locale, 'version' => $document['date']]);
            $this->withHeader('Accept-Language', 'tr')->getJson('/api/session')->assertJsonMissingPath('agreementRequired');
        }
        $this->assertDatabaseCount('workspace_agreement_acceptances', 4);
    }

    public function test_corrections_do_not_gate_existing_admins_but_explicit_reacceptance_does_and_preserves_history(): void
    {
        [$project, $owner, $first] = $this->publish();
        $legacy = User::factory()->memberOf($project->workspace)->create();
        $user = $this->invited($project, $owner);
        $this->postJson('/api/admin/agreement/accept', ['revision' => $first['id'], 'locale' => 'ru'])->assertOk();
        $this->actingAs($owner);
        $update = $this->payload();
        $update['baseRevision'] = $first['id'];
        $update['draft']['texts']['en'][0]['content'][0]['text'] = 'Corrected text';
        $second = $this->putJson('/api/admin/settings/agreement', $update)->assertOk()->json('data.published');
        $this->actingAs($user)->getJson('/api/admin/projects')->assertOk();
        $this->actingAs($legacy)->getJson('/api/admin/projects')->assertOk();
        $this->actingAs($owner);
        $update['baseRevision'] = $second['id'];
        $update['requireAgain'] = true;
        $this->putJson('/api/admin/settings/agreement', $update)->assertUnprocessable();
        $update['draft']['date'] = '2026-10-31';
        $third = $this->putJson('/api/admin/settings/agreement', $update)->assertOk()->json('data.published');
        $this->getJson('/api/admin/administrators')->assertJsonFragment(['status' => 'renewal', 'version' => '2026-10-30', 'locale' => 'ru', 'acceptedAt' => DB::table('workspace_agreement_acceptances')->value('accepted_at')]);
        $this->actingAs($user)->getJson('/api/admin/projects')->assertForbidden();
        $this->postJson('/api/admin/agreement/accept', ['revision' => $first['id'], 'locale' => 'en'])->assertConflict();
        $this->postJson('/api/admin/agreement/accept', ['revision' => $third['id'], 'locale' => 'zh-CN'])->assertOk();
        $this->assertDatabaseCount('workspace_agreement_acceptances', 2);
        $this->actingAs($legacy)->getJson('/api/session')->assertJsonPath('agreementRequired', true);
        $this->postJson('/api/admin/logout')->assertOk();
    }

    public function test_owner_only_validation_draft_is_private_and_public_document_is_workspace_bound_even_when_disabled(): void
    {
        [$project, $owner, $document] = $this->publish();
        $foreign = Project::factory()->create();
        $admin = User::factory()->memberOf($project->workspace)->create();
        $this->actingAs($admin)->getJson('/api/admin/settings/agreement')->assertForbidden();
        $this->putJson('/api/admin/settings/agreement', $this->payload())->assertForbidden();
        $this->actingAs($owner);
        $payload = $this->payload(false);
        $payload['baseRevision'] = $document['id'];
        $payload['publish'] = false;
        $payload['draft']['texts']['en'][0]['content'][0]['text'] = 'SECRET DRAFT';
        $this->putJson('/api/admin/settings/agreement', $payload)->assertOk();
        $this->postJson('/api/admin/logout')->assertOk();
        $this->getJson('/api/public/workspaces/'.$project->workspace->uuid.'/agreement')->assertOk()->assertJsonPath('document.id', $document['id'])->assertDontSee('SECRET DRAFT')->assertJsonMissingPath('draft');
        $this->getJson('/api/public/workspaces/'.$foreign->workspace->uuid.'/agreement')->assertNotFound();
        $this->postJson('/api/admin/agreement/accept', ['revision' => $document['id'], 'locale' => 'en'])->assertUnauthorized();
        $this->actingAs($this->ownerFor($foreign))->getJson('/api/admin/settings/agreement')->assertJsonPath('data.published', null);
        $this->actingAs($owner);
        $payload['enabled'] = true;
        $payload['draft']['texts']['tr'] = [];
        $this->putJson('/api/admin/settings/agreement', $payload)->assertUnprocessable();
        $payload = $this->payload();
        $payload['baseRevision'] = $document['id'];
        $payload['draft']['texts']['ru'][0]['type'] = 'script';
        $this->putJson('/api/admin/settings/agreement', $payload)->assertUnprocessable();
        $payload['draft']['texts']['ru'][0]['type'] = 'paragraph';
        $payload['draft']['texts']['ru'][0]['content'][0]['html'] = '<script>alert(1)</script>';
        $this->putJson('/api/admin/settings/agreement', $payload)->assertUnprocessable();
    }

    public function test_pending_acceptance_survives_mfa_step_up_and_is_isolated_from_other_workspaces(): void
    {
        [$project, $owner, $document] = $this->publish();
        $user = $this->invited($project, $owner);
        $this->getJson('/api/security/passkey/confirm/options')->assertOk();
        $this->postJson('/api/security/confirm', ['current_password' => 'Long-password-93'])->assertOk();
        $this->getJson('/api/session')->assertJsonPath('agreementRequired', true);
        $foreign = Project::factory()->create();
        $other = WorkspaceMember::factory()->create(['workspace_id' => $foreign->workspace_id, 'user_id' => $user->id]);
        $this->postJson('/api/admin/workspaces/'.$foreign->workspace->uuid.'/select')->assertOk();
        $this->getJson('/api/admin/projects')->assertOk();
        $this->postJson('/api/admin/agreement/accept', ['revision' => $document['id'], 'locale' => 'en'])->assertConflict();
        $this->assertDatabaseCount('workspace_agreement_acceptances', 0);
        $this->postJson('/api/admin/workspaces/'.$project->workspace->uuid.'/select')->assertOk();
        $this->getJson('/api/admin/projects')->assertForbidden();
        $this->postJson('/api/admin/logout')->assertOk();
    }

    public function test_footer_requires_explicit_publication_of_existing_contact_fields(): void
    {
        [$project, $owner] = $this->publish();
        $root = '/api/admin/workspaces/'.$project->workspace->uuid.'/branding';
        $this->actingAs($owner)->patchJson($root, ['section' => 'contact', 'values' => ['email' => 'support@example.test', 'website' => 'https://company.example.test', 'address' => 'PRIVATE ADDRESS']])->assertOk();
        $this->getJson('/api/public/workspaces/'.$project->workspace->uuid.'/agreement')->assertJsonPath('workspace.branding.supportEmail', null)->assertJsonPath('workspace.branding.companyUrl', null)->assertDontSee('PRIVATE ADDRESS');
        $this->patchJson($root, ['section' => 'footer', 'values' => ['support' => true, 'website' => true]])->assertOk();
        $this->postJson('/api/admin/logout')->assertOk();
        $this->getJson('/api/public/workspaces/'.$project->workspace->uuid.'/agreement')->assertJsonPath('workspace.branding.supportEmail', 'support@example.test')->assertJsonPath('workspace.branding.companyUrl', 'https://company.example.test')->assertDontSee('PRIVATE ADDRESS');
    }

    public function test_independent_signed_passkey_confirmations_do_not_bypass_pending_agreement(): void
    {
        config(['fortify.passkeys.allowed_origins' => ['http://localhost'], 'fortify.passkeys.relying_party_id' => 'localhost', 'passkeys.allowed_origins' => ['http://localhost'], 'passkeys.relying_party_id' => 'localhost']);
        [$project, $owner, $document] = $this->publish();
        $user = $this->invited($project, $owner);
        $device = new WebAuthnDevice;
        $registration = $this->getJson('/api/security/passkey/register/options')->assertOk()->json();
        $this->postJson('/api/security/passkey/register', ['attemptId' => $registration['attemptId'], 'name' => 'Agreement device', 'credential' => $device->registration($registration['options'])])->assertOk();
        $first = $this->getJson('/api/security/passkey/confirm/options')->assertOk()->json();
        $second = $this->getJson('/api/security/passkey/confirm/options')->assertOk()->json();
        foreach ([$second, $first] as $attempt) {
            $body = ['attemptId' => $attempt['attemptId'], 'credential' => $device->assertion($user->fresh(), $attempt['options'])];
            $this->postJson('/api/security/passkey/confirm', $body)->assertOk();
            $this->getJson('/api/admin/projects')->assertForbidden()->assertJsonPath('code', 'administrator_agreement_required');
            $this->postJson('/api/security/passkey/confirm', $body)->assertUnprocessable();
        }
        $this->postJson('/api/admin/agreement/accept', ['revision' => $document['id'], 'locale' => 'tr'])->assertOk();
        $this->getJson('/api/admin/projects')->assertOk();
    }

    public function test_removing_workspace_access_retains_acceptance_evidence_and_the_account(): void
    {
        [$project, $owner, $document] = $this->publish();
        $user = $this->invited($project, $owner);
        $member = $user->workspaceMembers()->firstOrFail();
        $this->postJson('/api/admin/agreement/accept', ['revision' => $document['id'], 'locale' => 'en'])->assertOk();
        $this->actingAs($owner)->deleteJson('/api/admin/administrators/members/'.$member->id)->assertOk();
        $this->assertDatabaseMissing('workspace_members', ['id' => $member->id]);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('workspace_agreement_acceptances', ['workspace_id' => $project->workspace_id, 'user_id' => $user->id, 'workspace_member_id' => null, 'version' => $document['date'], 'locale' => 'en']);
    }

    public function test_published_inline_formatting_preserves_spaces_between_text_runs(): void
    {
        [$project, $owner, $document] = $this->publish();
        $payload = $this->payload();
        $payload['baseRevision'] = $document['id'];
        $runs = [['text' => 'Before '], ['text' => 'bold', 'bold' => true], ['text' => ' and '], ['text' => 'italic', 'italic' => true], ['text' => ' after.']];
        $payload['draft']['texts']['en'] = [['type' => 'paragraph', 'content' => $runs]];
        $this->actingAs($owner)->putJson('/api/admin/settings/agreement', $payload)->assertOk()->assertJsonPath('data.published.texts.en.0.content', $runs);
        $this->postJson('/api/admin/logout')->assertOk();
        $this->getJson('/api/public/workspaces/'.$project->workspace->uuid.'/agreement')->assertOk()->assertJsonPath('document.texts.en.0.content', $runs);
    }
}
