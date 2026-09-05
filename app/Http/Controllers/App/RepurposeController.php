<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Repurpose\ActivateRepurpose;
use App\Actions\Repurpose\CreateRepurpose;
use App\Actions\Repurpose\DeleteRepurpose;
use App\Actions\Repurpose\DisableRepurpose;
use App\Actions\Repurpose\ListRepurposeItems;
use App\Actions\Repurpose\ListRepurposes;
use App\Actions\Repurpose\PauseRepurpose;
use App\Actions\Repurpose\ResumeRepurpose;
use App\Actions\Repurpose\UpdateRepurpose;
use App\Http\Requests\App\Repurpose\StoreRepurposeRequest;
use App\Http\Requests\App\Repurpose\UpdateRepurposeRequest;
use App\Http\Resources\App\SocialAccountResource;
use App\Models\Repurpose;
use App\Services\Repurpose\SourceFetcherFactory;
use App\Support\Repurpose\Templates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RepurposeController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Repurpose::class);

        $workspace = $request->user()->currentWorkspace;

        return Inertia::render('repurposes/Index', [
            'repurposes' => ListRepurposes::execute($workspace),
            'templates' => Templates::all(),
            'sourceAccounts' => SocialAccountResource::collection($this->sourceAccounts($request)),
        ]);
    }

    public function show(Request $request, Repurpose $repurpose): Response
    {
        $this->authorize('view', $repurpose);

        return Inertia::render('repurposes/Show', [
            'repurpose' => $repurpose->load('sourceAccount'),
            'sourceAccounts' => SocialAccountResource::collection($this->sourceAccounts($request)),
            'destinationAccounts' => SocialAccountResource::collection($this->destinationAccounts($request, $repurpose)),
            'items' => Inertia::scroll(fn () => ListRepurposeItems::execute($repurpose)),
        ]);
    }

    public function store(StoreRepurposeRequest $request): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;
        $sourceAccountId = (string) $request->validated('source_social_account_id');

        $existing = CreateRepurpose::existingFor($workspace, $sourceAccountId);

        if ($existing !== null) {
            return redirect()->route('app.repurposes.show', $existing);
        }

        $repurpose = CreateRepurpose::execute($workspace, $request->user(), $request->validated());

        return redirect()->route('app.repurposes.show', $repurpose);
    }

    public function update(UpdateRepurposeRequest $request, Repurpose $repurpose): RedirectResponse
    {
        UpdateRepurpose::execute($repurpose, $request->validated());

        return back();
    }

    public function activate(Request $request, Repurpose $repurpose): RedirectResponse
    {
        $this->authorize('update', $repurpose);

        ActivateRepurpose::execute($repurpose);

        return back();
    }

    public function pause(Request $request, Repurpose $repurpose): RedirectResponse
    {
        $this->authorize('update', $repurpose);

        PauseRepurpose::execute($repurpose);

        return back();
    }

    public function resume(Request $request, Repurpose $repurpose): RedirectResponse
    {
        $this->authorize('update', $repurpose);

        ResumeRepurpose::execute($repurpose);

        return back();
    }

    public function disable(Request $request, Repurpose $repurpose): RedirectResponse
    {
        $this->authorize('update', $repurpose);

        DisableRepurpose::execute($repurpose);

        return back();
    }

    public function destroy(Request $request, Repurpose $repurpose): RedirectResponse
    {
        $this->authorize('delete', $repurpose);

        DeleteRepurpose::execute($repurpose);

        return redirect()->route('app.repurposes.index');
    }

    /**
     * Only networks TryPost can both list and download from can be a source.
     */
    private function sourceAccounts(Request $request)
    {
        return $request->user()->currentWorkspace
            ->socialAccounts()
            ->active()
            ->whereIn('platform', array_map(fn ($platform) => $platform->value, SourceFetcherFactory::supportedPlatforms()))
            ->get();
    }

    /**
     * Accounts, not networks: a workspace may hold two Instagram accounts and
     * both are valid destinations.
     */
    private function destinationAccounts(Request $request, Repurpose $repurpose)
    {
        return $request->user()->currentWorkspace
            ->socialAccounts()
            ->active()
            ->whereKeyNot($repurpose->source_social_account_id)
            ->get();
    }
}
