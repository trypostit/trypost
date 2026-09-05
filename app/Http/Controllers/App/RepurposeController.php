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
use App\Enums\PostPlatform\ContentType;
use App\Enums\Repurpose\SourceFormat;
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
            'destinationAccounts' => SocialAccountResource::collection($this->connectedAccounts($request)),
        ]);
    }

    public function show(Request $request, Repurpose $repurpose): Response
    {
        $this->authorize('view', $repurpose);

        return Inertia::render('repurposes/Show', [
            'repurpose' => $repurpose->load('sourceAccount'),
            'sourceAccounts' => SocialAccountResource::collection($this->sourceAccounts($request)),
            'destinationAccounts' => SocialAccountResource::collection(
                $this->connectedAccounts($request)->whereNotIn('id', [$repurpose->source_social_account_id])->values(),
            ),
            'items' => Inertia::scroll(fn () => ListRepurposeItems::execute($repurpose)),
            'sourceFormats' => $this->sourceFormats($repurpose),
            'destinationFormats' => $this->destinationFormats($request, $repurpose->source_format),
        ]);
    }

    public function store(StoreRepurposeRequest $request): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;
        $sourceAccountId = (string) $request->validated('source_social_account_id');
        $sourceFormat = SourceFormat::tryFrom((string) $request->validated('source_format')) ?? SourceFormat::Reel;

        $existing = CreateRepurpose::existingFor($workspace, $sourceAccountId, $sourceFormat);

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
     * Formats the source network can be watched for. A repurpose watches one,
     * so replicating Reels and Stories means two repurposes on one account.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function sourceFormats(Repurpose $repurpose): array
    {
        $platform = $repurpose->sourceAccount?->platform;

        return array_map(
            fn (SourceFormat $format): array => ['value' => $format->value, 'label' => $format->label()],
            $platform === null ? [] : SourceFormat::forPlatform($platform),
        );
    }

    /**
     * Publishable video formats per connected destination account. Anything
     * that cannot carry a video is never offered, and the closest match to what
     * the source watches comes first so a newly picked destination opens on it.
     *
     * @return array<string, array<int, array{value: string, label: string}>>
     */
    private function destinationFormats(Request $request, SourceFormat $sourceFormat): array
    {
        $formats = [];

        foreach ($this->connectedAccounts($request) as $account) {
            $recommended = $sourceFormat->defaultContentTypeFor($account->platform);

            $contentTypes = collect(SourceFormat::videoContentTypesFor($account->platform))
                ->sortByDesc(fn (ContentType $contentType): bool => $contentType === $recommended)
                ->values();

            $formats[$account->id] = $contentTypes
                ->map(fn (ContentType $contentType): array => ['value' => $contentType->value, 'label' => $contentType->label()])
                ->all();
        }

        return $formats;
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
    private function connectedAccounts(Request $request)
    {
        return $request->user()->currentWorkspace
            ->socialAccounts()
            ->active()
            ->get();
    }
}
