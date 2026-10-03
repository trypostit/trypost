<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Signature\CreateSignature;
use App\Actions\Signature\DeleteSignature;
use App\Actions\Signature\UpdateSignature;
use App\Http\Requests\App\Signature\StoreSignatureRequest;
use App\Http\Requests\App\Signature\UpdateSignatureRequest;
use App\Models\WorkspaceSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

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
            'hasData' => $workspace->signatures()->exists(),
            'filters' => [
                'search' => $request->input('search', ''),
            ],
        ]);
    }

    public function store(StoreSignatureRequest $request): RedirectResponse|JsonResponse
    {
        $workspace = $request->user()->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('createPost', $workspace);

        $signature = CreateSignature::execute($workspace, $request->validated());

        if ($request->expectsJson()) {
            return response()->json($signature->only(['id', 'name', 'content']), SymfonyResponse::HTTP_CREATED);
        }

        return back();
    }

    public function update(UpdateSignatureRequest $request, WorkspaceSignature $signature): RedirectResponse|JsonResponse
    {
        $workspace = $request->user()->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('createPost', $workspace);

        if ($signature->workspace_id !== $workspace->id) {
            abort(404);
        }

        $signature = UpdateSignature::execute($signature, $request->validated());

        if ($request->expectsJson()) {
            return response()->json($signature->only(['id', 'name', 'content']));
        }

        return back();
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

        return back();
    }
}
