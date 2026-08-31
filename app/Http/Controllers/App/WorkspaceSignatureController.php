<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Signature\CreateSignature;
use App\Actions\Signature\DeleteSignature;
use App\Actions\Signature\UpdateSignature;
use App\Models\WorkspaceSignature;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceSignatureController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('createPost', $workspace);

        $signatures = $workspace->signatures()
            ->when($request->input('search'), fn ($query, $search) => $query->whereLike('name', "%{$search}%"))
            ->latest()
            ->paginate(config('app.pagination.default'));

        return Inertia::render('signatures/Index', [
            'workspace' => $workspace,
            'signatures' => Inertia::scroll(fn () => $signatures),
            'filters' => [
                'search' => $request->input('search', ''),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('createPost', $workspace);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
        ]);

        CreateSignature::execute($workspace, $validated);

        session()->flash('flash.banner', __('signatures.flash.created'));
        session()->flash('flash.bannerStyle', 'success');

        return redirect()->route('app.signatures.index');
    }

    public function update(Request $request, WorkspaceSignature $signature): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('createPost', $workspace);

        if ($signature->workspace_id !== $workspace->id) {
            abort(404);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
        ]);

        UpdateSignature::execute($signature, $validated);

        session()->flash('flash.banner', __('signatures.flash.updated'));
        session()->flash('flash.bannerStyle', 'success');

        return redirect()->route('app.signatures.index');
    }

    public function destroy(Request $request, WorkspaceSignature $signature): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('createPost', $workspace);

        if ($signature->workspace_id !== $workspace->id) {
            abort(404);
        }

        DeleteSignature::execute($signature);

        session()->flash('flash.banner', __('signatures.flash.deleted'));
        session()->flash('flash.bannerStyle', 'success');

        return redirect()->route('app.signatures.index');
    }
}
