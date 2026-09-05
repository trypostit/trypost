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
use App\Actions\SocialAccount\ListPinterestBoards;
use App\Enums\PostPlatform\ContentType;
use App\Enums\Repurpose\SourceFormat;
use App\Enums\SocialAccount\Platform;
use App\Http\Requests\App\Repurpose\StoreRepurposeRequest;
use App\Http\Requests\App\Repurpose\UpdateRepurposeRequest;
use App\Http\Resources\Api\RepurposeItemResource;
use App\Http\Resources\Api\RepurposeResource;
use App\Http\Resources\App\PlatformConfigResource;
use App\Http\Resources\App\SocialAccountResource;
use App\Models\Repurpose;
use App\Models\SocialAccount;
use App\Services\Repurpose\SourceFetcherFactory;
use App\Services\Social\TikTokCreatorInfo;
use App\Support\Repurpose\Templates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
            'repurpose' => new RepurposeResource($repurpose->load('sourceAccount')),
            'sourceAccounts' => SocialAccountResource::collection($this->sourceAccounts($request)),
            'destinationAccounts' => SocialAccountResource::collection(
                $this->connectedAccounts($request)->whereNotIn('id', [$repurpose->source_social_account_id])->values(),
            ),
            'items' => Inertia::scroll(fn () => RepurposeItemResource::collection(ListRepurposeItems::execute($repurpose))),
            'sourceFormats' => $this->sourceFormats($repurpose),
            'destinationFormats' => $this->destinationFormats($request, $repurpose->source_format),
            ...$this->platformSettingsProps($this->connectedAccounts($request)),
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
     * @param  Collection<int, SocialAccount>  $accounts
     * @return array<string, mixed>
     */
    private function platformSettingsProps($accounts): array
    {
        return [
            'platformConfigs' => $accounts->mapWithKeys(fn ($account) => [
                $account->id => new PlatformConfigResource($account),
            ]),
            'pinterestBoards' => $accounts
                ->where('platform', Platform::Pinterest)
                ->mapWithKeys(fn ($account) => [
                    $account->id => rescue(
                        fn () => ListPinterestBoards::execute($account),
                        ['boards' => [], 'truncated' => false],
                        report: false,
                    ),
                ]),
            'tiktokCreatorInfos' => $accounts
                ->where('platform', Platform::TikTok)
                ->mapWithKeys(fn ($account) => [
                    $account->id => rescue(
                        fn () => app(TikTokCreatorInfo::class)->fetch($account),
                        null,
                        report: false,
                    ),
                ])
                ->filter(),
        ];
    }

    private function sourceAccounts(Request $request)
    {
        return $request->user()->currentWorkspace
            ->socialAccounts()
            ->active()
            ->whereIn('platform', array_map(fn ($platform) => $platform->value, SourceFetcherFactory::supportedPlatforms()))
            ->get();
    }

    private function connectedAccounts(Request $request)
    {
        return $request->user()->currentWorkspace
            ->socialAccounts()
            ->active()
            ->get();
    }
}
