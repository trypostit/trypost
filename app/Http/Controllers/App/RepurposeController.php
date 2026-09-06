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
use App\Enums\Repurpose\PublishMode;
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
        $accounts = $this->connectedAccounts($request);

        return Inertia::render('repurposes/Index', [
            'repurposes' => Inertia::scroll(fn () => RepurposeResource::collection(ListRepurposes::execute($workspace))),
            'templates' => Templates::all(),
            'sourceAccounts' => SocialAccountResource::collection($this->sourceAccounts($accounts)),
            'destinationAccounts' => SocialAccountResource::collection($accounts),
        ]);
    }

    public function show(Request $request, Repurpose $repurpose): Response
    {
        $this->authorize('view', $repurpose);

        $accounts = $this->connectedAccounts($request);
        $destinations = $accounts->whereNotIn('id', [$repurpose->source_social_account_id])->values();

        return Inertia::render('repurposes/Show', [
            'repurpose' => new RepurposeResource($repurpose->load('sourceAccount')),
            'destinationAccounts' => SocialAccountResource::collection($destinations),
            'items' => Inertia::scroll(fn () => RepurposeItemResource::collection(ListRepurposeItems::execute($repurpose))),
            'sourceFormats' => $this->sourceFormats($repurpose),
            'publishModes' => array_map(
                fn (PublishMode $mode): array => [
                    'value' => $mode->value,
                    'label' => $mode->label(),
                    'description' => $mode->description(),
                ],
                PublishMode::cases(),
            ),
            'recommendedFormats' => $this->recommendedFormats($destinations, $repurpose->source_format),
            ...$this->platformSettingsProps($destinations),
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
     * The content type a destination starts on: what the watched format maps to
     * on that network, or its first video type when the two do not line up.
     *
     * @param  Collection<int, SocialAccount>  $accounts
     * @return array<string, string>
     */
    private function recommendedFormats(Collection $accounts, SourceFormat $sourceFormat): array
    {
        return $accounts
            ->mapWithKeys(function (SocialAccount $account) use ($sourceFormat): array {
                $contentType = $sourceFormat->defaultContentTypeFor($account->platform)
                    ?? SourceFormat::videoContentTypesFor($account->platform)[0]
                    ?? null;

                return [$account->id => $contentType?->value];
            })
            ->filter()
            ->all();
    }

    /**
     * @param  Collection<int, SocialAccount>  $accounts
     * @return array<string, mixed>
     */
    private function platformSettingsProps(Collection $accounts): array
    {
        return [
            'platformConfigs' => fn () => $accounts->mapWithKeys(fn (SocialAccount $account): array => [
                $account->id => new PlatformConfigResource($account),
            ]),
            'pinterestBoards' => fn () => $accounts
                ->where('platform', Platform::Pinterest)
                ->mapWithKeys(fn (SocialAccount $account): array => [
                    $account->id => rescue(
                        fn () => ListPinterestBoards::execute($account),
                        ['boards' => [], 'truncated' => false],
                        report: false,
                    ),
                ]),
            'tiktokCreatorInfos' => fn () => $accounts
                ->where('platform', Platform::TikTok)
                ->mapWithKeys(fn (SocialAccount $account): array => [
                    $account->id => rescue(
                        fn () => app(TikTokCreatorInfo::class)->fetch($account),
                        null,
                        report: false,
                    ),
                ])
                ->filter(),
        ];
    }

    /**
     * @param  Collection<int, SocialAccount>  $accounts
     * @return Collection<int, SocialAccount>
     */
    private function sourceAccounts(Collection $accounts): Collection
    {
        return $accounts
            ->whereIn('platform', SourceFetcherFactory::supportedPlatforms())
            ->values();
    }

    /**
     * @return Collection<int, SocialAccount>
     */
    private function connectedAccounts(Request $request): Collection
    {
        return $request->user()->currentWorkspace->socialAccounts()->active()->get();
    }
}
