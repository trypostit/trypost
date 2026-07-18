<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Ai\AutofillBrand;
use App\Actions\Workspace\CreateWorkspace;
use App\Actions\Workspace\DeleteWorkspace;
use App\Enums\Workspace\BrandFont;
use App\Enums\Workspace\BrandVoiceTrait;
use App\Enums\Workspace\ContentLanguage;
use App\Enums\Workspace\ImageStyle;
use App\Http\Requests\App\Workspace\AutofillBrandRequest;
use App\Http\Requests\App\Workspace\StoreWorkspaceRequest;
use App\Http\Requests\App\Workspace\UpdateWorkspaceRequest;
use App\Http\Resources\App\WorkspaceMemberResource;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Brand\LogoAttacher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class WorkspaceController extends Controller
{
    public function searchMembers(Request $request): AnonymousResourceCollection
    {
        $workspace = $request->user()->currentWorkspace;

        abort_if(! $workspace, SymfonyResponse::HTTP_FORBIDDEN);

        $this->authorize('view', $workspace);

        $term = trim((string) $request->input('q', ''));

        $members = $workspace->members()
            ->where('users.id', '!=', $request->user()->id)
            ->when($term !== '', fn ($query) => $query->where('users.name', 'ilike', '%'.$term.'%'))
            ->orderBy('users.name')
            ->limit(50)
            ->get(['users.id', 'users.name', 'users.email']);

        return WorkspaceMemberResource::collection($members);
    }

    public function index(Request $request): Response
    {
        $user = $request->user();

        $workspaces = $user->workspaces()
            ->with('media')
            ->withCount(['socialAccounts', 'posts'])
            ->latest()
            ->get();

        return Inertia::render('workspaces/Index', [
            'workspaces' => $workspaces,
            'currentWorkspaceId' => $user->current_workspace_id,
        ]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        if ($redirect = $this->denyAdditionalWorkspaceWithoutSubscription($request->user())) {
            return $redirect;
        }

        return Inertia::render('workspaces/Create', [
            'availableFonts' => BrandFont::values(),
            'availableImageStyles' => ImageStyle::values(),
            'availableVoiceTraits' => BrandVoiceTrait::grouped(),
            'availableContentLanguages' => ContentLanguage::options(),
        ]);
    }

    /**
     * Block creating a paid additional workspace without an active subscription.
     * Guards both the form (`create`) and the write (`store`) so a direct POST
     * can't bootstrap a second billable workspace — which would also inflate the
     * checkout quantity past the fixed first-month coupon.
     */
    private function denyAdditionalWorkspaceWithoutSubscription(User $user): ?RedirectResponse
    {
        if (! config('trypost.self_hosted')
            && $user->ownedWorkspacesCount() > 0
            && ! $user->account?->hasActiveSubscription()) {
            return redirect()->route('app.billing.index')
                ->with('message', 'Subscribe to create more workspaces.');
        }

        return null;
    }

    public function autofillBrand(AutofillBrandRequest $request, AutofillBrand $autofill): JsonResponse
    {
        try {
            $metadata = $autofill($request->validated('url'));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], SymfonyResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json($metadata->toArray());
    }

    public function store(StoreWorkspaceRequest $request, LogoAttacher $logoAttacher): RedirectResponse
    {
        $user = $request->user();

        if ($redirect = $this->denyAdditionalWorkspaceWithoutSubscription($user)) {
            return $redirect;
        }

        $validated = $request->validated();

        $workspace = CreateWorkspace::execute($user, $validated);

        if ($logoUrl = data_get($validated, 'logo_url')) {
            $logoAttacher->attach($workspace, $logoUrl);
        }

        return redirect()->route('app.accounts')
            ->with('success', __('workspaces.create.success'));
    }

    public function switch(Request $request, Workspace $workspace): RedirectResponse
    {
        $user = $request->user();

        if (! $user->belongsToWorkspace($workspace)) {
            abort(403);
        }

        $user->switchWorkspace($workspace);

        return redirect()->route('app.calendar');
    }

    public function settings(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $workspace = $user->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('update', $workspace);

        return Inertia::render('settings/workspace/Workspace', [
            'workspace' => $workspace,
        ]);
    }

    public function brandSettings(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $workspace = $user->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('update', $workspace);

        return Inertia::render('settings/workspace/Brand', [
            'workspace' => $workspace,
            'availableFonts' => BrandFont::values(),
            'availableImageStyles' => ImageStyle::values(),
            'availableVoiceTraits' => BrandVoiceTrait::grouped(),
            'availableContentLanguages' => ContentLanguage::options(),
        ]);
    }

    public function uploadLogo(Request $request): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        $this->authorize('update', $workspace);

        $request->validate([
            'photo' => ['required', 'image', 'max:2048'],
        ]);

        $workspace->clearMediaCollection('logo');
        $workspace->addMedia($request->file('photo'), 'logo');
        $workspace->unsetRelation('media');

        return back()->with('flash.success', __('settings.flash.logo_updated'));
    }

    public function deleteLogo(Request $request): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        $this->authorize('update', $workspace);

        $workspace->clearMediaCollection('logo');
        $workspace->unsetRelation('media');

        return back()->with('flash.success', __('settings.flash.logo_deleted'));
    }

    public function updateSettings(UpdateWorkspaceRequest $request, LogoAttacher $logoAttacher): RedirectResponse
    {
        $user = $request->user();
        $workspace = $user->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('update', $workspace);

        $validated = $request->validated();

        $logoUrl = data_get($validated, 'logo_url');
        unset($validated['logo_url']);

        $workspace->update($validated);

        if ($logoUrl) {
            $logoAttacher->attach($workspace, $logoUrl);
        }

        return back()->with('flash.success', __('settings.flash.workspace_updated'));
    }

    public function destroy(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->authorize('delete', $workspace);

        $user = $request->user();

        if (! config('trypost.self_hosted') && $workspace->account->workspaces()->count() <= 1) {
            return back()->with('flash.error', __('workspaces.cannot_delete_last'));
        }

        DeleteWorkspace::execute($user, $workspace);

        return redirect()->route('app.workspaces.index')
            ->with('flash.success', __('workspaces.flash.deleted'));
    }
}
