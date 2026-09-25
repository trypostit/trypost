<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Signature\CreateSignature;
use App\Actions\Signature\DeleteSignature;
use App\Actions\Signature\UpdateSignature;
use App\Models\WorkspaceSignature;
use Illuminate\Http\JsonResponse;
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

    public function store(Request $request): RedirectResponse|JsonResponse
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

        $signature = CreateSignature::execute($workspace, $validated);

        if ($request->expectsJson()) {
            return response()->json($signature->only(['id', 'name', 'content']), 201);
        }

        session()->flash('flash.banner', __('signatures.flash.created'));
        session()->flash('flash.bannerStyle', 'success');

        return redirect()->route('app.signatures.index');
    }

    public function update(Request $request, WorkspaceSignature $signature): RedirectResponse|JsonResponse
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

        $signature = UpdateSignature::execute($signature, $validated);

        if ($request->expectsJson()) {
            return response()->json($signature->only(['id', 'name', 'content']));
        }

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
