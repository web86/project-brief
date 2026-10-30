<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\AccountSecurity;
use App\Services\AgreementTemplates;
use App\Services\CurrentWorkspace;
use App\Services\DeveloperInvitations;
use App\Services\WorkspaceAgreements;
use App\Services\WorkspaceBranding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WorkspaceAgreementController extends Controller
{
    public function settings(Request $request, WorkspaceAgreements $agreements): JsonResponse
    {
        $owner = app(DeveloperInvitations::class)->owner($request);

        return response()->json(['data' => $agreements->settings($owner->workspace), 'templates' => app(AgreementTemplates::class)->texts(), 'publicUrl' => $agreements->publicUrl($owner->workspace)])->header('Cache-Control', 'no-store');
    }

    public function save(Request $request, WorkspaceAgreements $agreements): JsonResponse
    {
        $initialOwner = app(DeveloperInvitations::class)->owner($request);
        $rules = ['enabled' => ['required', 'boolean'], 'publish' => ['required', 'boolean'], 'requireAgain' => ['required', 'boolean'], 'baseRevision' => ['present', 'nullable', 'uuid'], 'draft' => ['required', 'array:date,texts,enabledLocales,defaultLocale'], 'draft.date' => ['required', 'date_format:Y-m-d'], 'draft.texts' => ['required', 'array:'.implode(',', WorkspaceAgreements::LOCALES)]];
        $rules['draft.enabledLocales'] = ['sometimes', 'array', 'min:1', 'max:4'];
        $rules['draft.enabledLocales.*'] = ['required', Rule::in(WorkspaceAgreements::LOCALES), 'distinct'];
        $rules['draft.defaultLocale'] = ['required_with:draft.enabledLocales', Rule::in(WorkspaceAgreements::LOCALES)];
        foreach (WorkspaceAgreements::LOCALES as $locale) {
            $key = 'draft.texts.'.$locale;
            $rules[$key] = ['present', 'array', 'max:500'];
            $rules[$key.'.*'] = ['required', 'array:type,content'];
            $rules[$key.'.*.type'] = ['required', Rule::in(['paragraph', 'heading', 'subheading', 'bullet', 'number'])];
            $rules[$key.'.*.content'] = ['required', 'array', 'max:500'];
            $rules[$key.'.*.content.*'] = ['required', 'array:text,bold,italic,href'];
            $rules[$key.'.*.content.*.text'] = ['present', 'string', 'max:20000'];
            $rules[$key.'.*.content.*.bold'] = ['sometimes', 'boolean'];
            $rules[$key.'.*.content.*.italic'] = ['sometimes', 'boolean'];
            $rules[$key.'.*.content.*.href'] = ['sometimes', 'string', 'max:2048', static function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || ! WorkspaceAgreements::validLink($value)) {
                    $fail(trans('agreements.safe_link'));
                }
            }];
        }
        $data = $request->validate($rules);
        $data['draft'] = $agreements->documentLanguages([...$agreements->settings($initialOwner->workspace)['draft'], ...$data['draft']]);
        if (! in_array($data['draft']['defaultLocale'], $data['draft']['enabledLocales'], true)) {
            throw ValidationException::withMessages(['draft.defaultLocale' => [trans('agreements.default_enabled')]]);
        }
        abort_if(strlen(json_encode($data['draft'], JSON_THROW_ON_ERROR)) > 1000000, 422);
        foreach (WorkspaceAgreements::LOCALES as $locale) {
            $plain = collect($data['draft']['texts'][$locale])->flatMap(fn (array $block): array => array_column($block['content'], 'text'))->implode('');
            if (($data['enabled'] || $data['publish']) && in_array($locale, $data['draft']['enabledLocales'], true) && trim($plain) === '') {
                throw ValidationException::withMessages(['draft.texts.'.$locale => [trans('agreements.enabled_languages')]]);
            }
        }
        DB::transaction(function () use ($request, $agreements, $data): void {
            $owner = app(DeveloperInvitations::class)->owner($request);
            $workspace = Workspace::whereKey($owner->workspace_id)->lockForUpdate()->firstOrFail();
            $settings = $agreements->settings($workspace);
            abort_unless(($settings['published']['id'] ?? null) === $data['baseRevision'], 409);
            if ($data['publish']) {
                $oldDate = $settings['published']['date'] ?? null;
                if ($oldDate && ($data['draft']['date'] < $oldDate || ($data['requireAgain'] && $data['draft']['date'] <= $oldDate))) {
                    throw ValidationException::withMessages(['draft.date' => [trans('agreements.new_date')]]);
                }
                if ($settings['published']) {
                    $agreements->archive($workspace, $settings['published']);
                }
                $settings['published'] = [...$data['draft'], 'id' => (string) Str::uuid()];
                $agreements->archive($workspace, $settings['published']);
            }
            if (($data['enabled'] && ! $settings['published']) || ($data['requireAgain'] && (! $data['publish'] || ! $data['enabled']))) {
                throw ValidationException::withMessages(['publish' => [trans('agreements.publish_first')]]);
            }
            $settings['enabled'] = $data['enabled'];
            $settings['draft'] = $data['draft'];
            $workspace->update(['administrator_agreement' => $settings]);
            if ($data['requireAgain']) {
                WorkspaceMember::where('workspace_id', $workspace->id)->where('role', 'admin')->update(['agreement_requirement' => $settings['published']['id']]);
            }
            app(AccountSecurity::class)->audit($request->user(), $data['publish'] ? 'workspace_agreement_published' : 'workspace_agreement_saved');
        });

        return $this->settings($request, $agreements);
    }

    public function document(Request $request, WorkspaceAgreements $agreements): JsonResponse
    {
        $member = app(CurrentWorkspace::class)->resolve($request);

        return response()->json(['document' => $agreements->visibleDocument($agreements->settings($member->workspace)['published']), 'workspace' => ['name' => $member->workspace->name, 'branding' => app(WorkspaceBranding::class)->portal($member->workspace)], 'required' => $agreements->required($member)])->header('Cache-Control', 'no-store');
    }

    public function accept(Request $request, WorkspaceAgreements $agreements): JsonResponse
    {
        $data = $request->validate(['revision' => ['required', 'uuid'], 'locale' => ['required', Rule::in(WorkspaceAgreements::LOCALES)]]);
        DB::transaction(function () use ($request, $agreements, $data): void {
            $context = app(CurrentWorkspace::class)->resolve($request);
            $workspace = Workspace::whereKey($context->workspace_id)->lockForUpdate()->firstOrFail();
            $member = WorkspaceMember::whereKey($context->id)->lockForUpdate()->firstOrFail();
            $member->setRelation('workspace', $workspace);
            $document = $agreements->settings($workspace)['published'];
            abort_unless($document && $document['id'] === $data['revision'], 409);
            abort_unless(in_array($data['locale'], $document['enabledLocales'], true), 422, trans('agreements.language_disabled'));
            $agreements->archive($workspace, $document);
            if (! $agreements->required($member)) {
                return;
            }
            DB::table('workspace_agreement_acceptances')->insert(['workspace_id' => $member->workspace_id, 'workspace_member_id' => $member->id, 'user_id' => $member->user_id, 'requirement_id' => $member->agreement_requirement, 'revision_id' => $document['id'], 'version' => $document['date'], 'locale' => $data['locale'], 'accepted_at' => now()]);
            app(AccountSecurity::class)->audit($request->user(), 'workspace_agreement_accepted');
        });

        return response()->json(['accepted' => true])->header('Cache-Control', 'no-store');
    }

    public function published(Workspace $workspace, WorkspaceAgreements $agreements): JsonResponse
    {
        $document = $agreements->settings($workspace)['published'];
        abort_unless($document, 404);

        return response()->json(['document' => $agreements->visibleDocument($document), 'workspace' => ['name' => $workspace->name, 'branding' => app(WorkspaceBranding::class)->portal($workspace)]])->header('Cache-Control', 'no-store');
    }
}
