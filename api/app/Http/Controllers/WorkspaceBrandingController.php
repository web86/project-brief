<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Services\AccountSecurity;
use App\Services\AttachmentStorage;
use App\Services\BrandImage;
use App\Services\CurrentWorkspace;
use App\Services\WorkspaceBranding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class WorkspaceBrandingController extends Controller
{
    private function messages(): array
    {
        return array_fill_keys(['min', 'required', 'in', 'array', 'string', 'max', 'regex', 'email', 'url', 'boolean', 'file'], trans('branding.check_fields'));
    }

    private function owner(Request $request, bool $lock = false): Workspace
    {
        $member = app(CurrentWorkspace::class)->resolve($request, $lock);
        abort_unless($member->role === 'owner', 403);
        abort_unless($request->route('brandingWorkspace') === $member->workspace->uuid, 404);
        if (! $request->isMethodSafe()) {
            app(AccountSecurity::class)->requireFresh($request);
        }

        return $lock ? Workspace::whereKey($member->workspace_id)->lockForUpdate()->firstOrFail() : $member->workspace;
    }

    public function show(Request $request, WorkspaceBranding $branding): JsonResponse
    {
        return response()->json(['data' => $branding->ownerState($this->owner($request))]);
    }

    private function sectionRules(string $section, string $prefix): array
    {
        $fields = WorkspaceBranding::FIELDS[$section] ?? array_fill_keys(array_keys($section === 'email' ? WorkspaceBranding::EMAIL_DEFAULTS : WorkspaceBranding::EXPORT_DEFAULTS), null);
        $rules = [$prefix => ['required', 'array:'.implode(',', array_keys($fields))]];
        foreach ($fields as $key => $length) {
            $rules[$prefix.'.'.$key] = $length === null ? ['sometimes', 'boolean'] : ['sometimes', 'nullable', 'string', 'max:'.$length];
        }
        if ($section === 'visual') {
            $rules[$prefix.'.primaryColor'][] = 'regex:/^#[0-9a-fA-F]{6}$/D';
        }
        if ($section === 'contact') {
            $rules[$prefix.'.email'][] = 'email';
            $rules[$prefix.'.website'][] = 'url:http,https';
        }

        return $rules;
    }

    public function update(Request $request, WorkspaceBranding $branding): JsonResponse
    {
        $this->owner($request);
        $keys = ['visual', 'business', 'contact', 'export', 'email'];
        $atomic = $request->has('sections');
        if ($atomic) {
            $rules = ['sections' => ['required', 'array:'.implode(',', $keys), 'min:1'], 'versions' => ['required', 'array:'.implode(',', $keys)]];
            $request->validate($rules, $this->messages());
            foreach (array_keys($request->input('sections')) as $section) {
                $rules = array_merge($rules, $this->sectionRules($section, 'sections.'.$section), ['versions.'.$section => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D']]);
            }
            $data = $request->validate($rules, $this->messages());
            $sections = $data['sections'];
        } else {
            $section = $request->validate(['section' => ['required', Rule::in($keys)]], $this->messages())['section'];
            $data = $request->validate($this->sectionRules($section, 'values'), $this->messages());
            $sections = [$section => $data['values']];
        }
        DB::transaction(function () use ($request, $branding, $sections, $atomic, $data): void {
            $workspace = $this->owner($request, true);
            $settings = $branding->settings($workspace);
            $versions = $branding->versions($workspace);
            foreach ($sections as $section => $values) {
                if ($atomic && ! hash_equals($versions[$section], $data['versions'][$section])) {
                    abort(response()->json(['code' => 'branding_conflict', 'message' => trans('branding.conflict')], 409));
                }
                $settings[$section] = array_replace($settings[$section], array_map(fn ($value) => is_string($value) ? trim($value) : ($value ?? ''), $values));
            }
            $workspace->update(['branding' => $settings]);
        });

        return $this->show($request, $branding);
    }

    public function sanitize(Request $request, BrandImage $images): JsonResponse
    {
        $this->owner($request);
        $request->validate(['file' => ['required', 'file', 'max:2048']], $this->messages());

        return response()->json(['svg' => $images->svg($request->file('file')->get())]);
    }

    public function asset(Request $request, string $brandingWorkspace, string $kind, WorkspaceBranding $branding, BrandImage $images): JsonResponse
    {
        abort_unless(in_array($kind, ['logo', 'favicon'], true), 404);
        $this->owner($request);
        if ($request->isMethod('DELETE')) {
            DB::transaction(function () use ($request, $kind): void {
                $workspace = $this->owner($request, true);
                $settings = $workspace->branding ?? [];
                $this->retire($settings['assets'][$kind] ?? null);
                unset($settings['assets'][$kind]);
                $workspace->update(['branding' => $settings]);
            });
        } else {
            $request->validate(['file' => ['required', 'file', 'max:2048'], 'raster' => ['nullable', 'file', 'max:4096']], $this->messages());
            $file = $request->file('file');
            $svg = strtolower($file->getClientOriginalExtension()) === 'svg' || str_contains($file->getMimeType(), 'svg');
            $source = $svg ? $images->svg($file->get()) : null;
            if ($svg && ! $request->file('raster')) {
                $images->invalid();
            }
            $image = $images->optimized($svg ? $request->file('raster') : $file, $kind);
            AttachmentStorage::atomic(function (array &$paths) use ($request, $kind, $source, $image, $file): void {
                $workspace = $this->owner($request, true);
                $settings = $workspace->branding ?? [];
                $token = bin2hex(random_bytes(24));
                $path = 'branding/'.$workspace->uuid.'/'.$token.'.'.$image['extension'];
                $paths[] = $path;
                abort_unless(Storage::disk('local')->put($path, $image['bytes']), 500);
                $svgPath = null;
                if ($source) {
                    $svgPath = 'branding/'.$workspace->uuid.'/'.$token.'.svg';
                    $paths[] = $svgPath;
                    abort_unless(Storage::disk('local')->put($svgPath, $source), 500);
                }
                $this->retire($settings['assets'][$kind] ?? null);
                $settings['assets'][$kind] = ['token' => $token, 'path' => $path, 'svgPath' => $svgPath, 'extension' => $image['extension'], 'mime' => $image['mime'], 'bytes' => strlen($image['bytes']), 'browserBytes' => $source ? strlen($source) : strlen($image['bytes']), 'originalBytes' => $file->getSize()];
                $workspace->update(['branding' => $settings]);
            });
        }

        return $this->show($request, $branding);
    }

    private function retire(?array $asset): void
    {
        foreach (array_filter([$asset['path'] ?? null, $asset['svgPath'] ?? null]) as $path) {
            DB::table('project_file_deletions')->insert(['disk' => 'local', 'path' => $path]);
        }
    }

    public function reset(Request $request, WorkspaceBranding $branding): JsonResponse
    {
        DB::transaction(function () use ($request, $branding): void {
            $workspace = $this->owner($request, true);
            $settings = $branding->settings($workspace);
            foreach ($settings['assets'] as $asset) {
                $this->retire($asset);
            }
            $settings['assets'] = [];
            $settings['visual'] = array_fill_keys(array_keys(WorkspaceBranding::FIELDS['visual']), '');
            $settings['email'] = WorkspaceBranding::EMAIL_DEFAULTS;
            $settings['export'] = WorkspaceBranding::EXPORT_DEFAULTS;
            $workspace->update(['branding' => $settings]);
        });

        return $this->show($request, $branding);
    }

    /** Only opaque, normalized visuals; SVG is sanitized again for legacy assets and direct navigation. */
    public function image(Workspace $workspace, string $token, string $extension, BrandImage $images): Response
    {
        $asset = collect($workspace->branding['assets'] ?? [])->first(fn ($asset) => $asset['token'] === $token);
        $svg = $extension === 'svg';
        $path = $svg ? ($asset['svgPath'] ?? null) : ($asset['path'] ?? null);
        abort_unless($asset && $path && ($svg || $extension === ($asset['extension'] ?? 'png')) && Storage::disk('local')->exists($path), 404);
        $bytes = Storage::disk('local')->get($path);
        $bytes = $svg ? $images->svg($bytes) : $bytes;

        return response($bytes, 200, ['Content-Type' => $svg ? 'image/svg+xml' : ($asset['mime'] ?? 'image/png'), 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; script-src 'none'; style-src 'none'; sandbox", 'Content-Disposition' => 'inline; filename="brand.'.$extension.'"']);
    }
}
