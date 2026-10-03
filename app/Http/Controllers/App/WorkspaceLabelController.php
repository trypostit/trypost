<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Label\CreateLabel;
use App\Actions\Label\DeleteLabel;
use App\Actions\Label\UpdateLabel;
use App\Http\Requests\App\Label\StoreLabelRequest;
use App\Http\Requests\App\Label\UpdateLabelRequest;
use App\Models\WorkspaceLabel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceLabelController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('createPost', $workspace);

        $labels = $workspace->labels()
            ->withCount('posts')
            ->when($request->input('search'), fn ($query, $search) => $query->whereLike('name', "%{$search}%"))
            ->latest()
            ->paginate(config('app.pagination.default'));

        return Inertia::render('labels/Index', [
            'workspace' => $workspace,
            'labels' => Inertia::scroll(fn () => $labels),
            'hasData' => $workspace->labels()->exists(),
            'filters' => [
                'search' => $request->input('search', ''),
            ],
        ]);
    }

    public function store(StoreLabelRequest $request): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('createPost', $workspace);

        $label = CreateLabel::execute($workspace, $request->validated());

        return Inertia::flash('createdLabel', $label->only(['id', 'name', 'color']))->back();
    }

    public function update(UpdateLabelRequest $request, WorkspaceLabel $label): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('createPost', $workspace);

        if ($label->workspace_id !== $workspace->id) {
            abort(404);
        }

        UpdateLabel::execute($label, $request->validated());

        return back();
    }

    public function destroy(Request $request, WorkspaceLabel $label): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('createPost', $workspace);

        if ($label->workspace_id !== $workspace->id) {
            abort(404);
        }

        DeleteLabel::execute($label);

        return back();
    }
}
