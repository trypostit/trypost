<?php

declare(strict_types=1);

namespace App\Actions\Automation\Node;

use App\Actions\Post\CreatePost;
use App\Ai\Agents\PostContentGenerator;
use App\Ai\Agents\PostContentHumanizer;
use App\Ai\Templates\AiTemplateRegistry;
use App\Ai\Templates\TemplateContext;
use App\DataTransferObjects\Automation\NodeRunResult;
use App\Enums\Ai\ContentStyle;
use App\Enums\Ai\GeneratorFormat;
use App\Enums\Post\CreatedVia;
use App\Enums\PostPlatform\ContentType;
use App\Models\AutomationRun;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Ai\RecordAiUsage;
use App\Services\Automation\ExpressionResolver;
use App\Services\Automation\GenerateNodeValidator;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunGenerateNode
{
    public function __construct(
        private ExpressionResolver $resolver,
    ) {}

    public function __invoke(AutomationRun $run, array $config): NodeRunResult
    {
        $context = $run->resolverContext();
        $prompt = $this->resolver->resolve((string) data_get($config, 'prompt_template', ''), $context);

        $accountsConfig = $this->resolveAccountsConfig($config);
        ['format' => $format, 'slide_count' => $slideCount] = $this->deriveFormat($accountsConfig, $config);

        $accountIds = array_values(array_filter(array_map(
            fn ($a) => data_get($a, 'social_account_id'),
            $accountsConfig,
        )));

        $workspace = $run->automation->workspace;

        $activeAccounts = SocialAccount::query()
            ->whereIn('id', $accountIds)
            ->where('workspace_id', $workspace->id)
            ->active()
            ->get()
            ->keyBy('id');

        $applyBrandVoice = (bool) data_get($config, 'use_brand_voice', true);

        $platformContext = $this->resolvePlatformContext($accountsConfig);

        $style = ContentStyle::tryFrom((string) data_get($config, 'style', ContentStyle::default()->value)) ?? ContentStyle::default();
        $styleTemplate = app(AiTemplateRegistry::class)->find($style);

        $platforms = [];
        foreach ($accountsConfig as $entry) {
            $accountId = data_get($entry, 'social_account_id');
            if (! $accountId || ! $activeAccounts->has($accountId)) {
                if ($accountId) {
                    Log::warning('RunGenerateNode: account no longer active, skipping', [
                        'automation_id' => $run->automation_id,
                        'social_account_id' => $accountId,
                    ]);
                }

                continue;
            }

            $platforms[] = [
                'social_account_id' => $accountId,
                'content_type' => data_get($entry, 'content_type'),
                'meta' => data_get($entry, 'meta', []),
            ];
        }

        $wantsImage = (int) data_get($config, 'target_slide_count', 1) >= 1;

        $brandAccount = $platforms !== []
            ? $activeAccounts->get(data_get($platforms[0], 'social_account_id'))
            : null;

        $isCarousel = $format->isCarousel();
        $imageCount = $isCarousel ? $slideCount : ($wantsImage ? 1 : 0);

        $templateContext = new TemplateContext(
            workspace: $workspace,
            socialAccount: $brandAccount,
            format: $platformContext ?? $format->value,
            imageCount: $imageCount,
            isCarousel: $isCarousel,
            applyBrandVisuals: (bool) data_get($config, 'use_brand_visuals', true),
        );

        $agent = new PostContentGenerator(
            workspace: $workspace,
            format: $format,
            slideCount: $slideCount,
            platformContext: $platformContext,
            applyBrandVoice: $applyBrandVoice,
            template: $styleTemplate,
            templateContext: $templateContext,
        );

        $generatorResponse = $agent->prompt($prompt);

        RecordAiUsage::recordText(
            workspace: $workspace,
            promptTokens: $generatorResponse->usage->promptTokens,
            completionTokens: $generatorResponse->usage->completionTokens,
            provider: (string) $generatorResponse->meta->provider,
            model: (string) $generatorResponse->meta->model,
            metadata: ['agent' => 'post_generator', 'format' => $format->value, 'source' => 'automation'],
        );

        $structured = $generatorResponse->structured ?? [];

        $structured = $this->humanize($workspace, $structured, $format, $style, $applyBrandVoice, $platformContext);

        $intendedImageCount = $this->intendedImageCount($format, $slideCount, $wantsImage, $structured, $brandAccount, $style);

        if ($run->is_dry_run) {
            $dryContent = $this->extractContent($structured, $format, $style);

            return NodeRunResult::completed(output: [
                'generated' => [
                    'post_id' => null,
                    'content' => $dryContent,
                    'dry_run' => true,
                    'image_count' => $intendedImageCount,
                ],
            ]);
        }

        $generated = $styleTemplate->assemble($structured, $templateContext);

        $user = $this->resolveUser($run);

        $post = CreatePost::execute($workspace, $user, [
            'content' => $generated->content,
            'media' => $generated->media,
            'platforms' => $platforms,
            'created_via' => CreatedVia::Automation,
        ]);

        $run->update(['generated_post_id' => $post->id]);

        return NodeRunResult::completed(output: [
            'generated' => [
                'post_id' => $post->id,
                'content' => $generated->content,
                'post_url' => route('app.posts.show', $post->id),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $structured
     * @return array<string, mixed>
     */
    private function humanize(Workspace $workspace, array $structured, GeneratorFormat $format, ContentStyle $style, bool $applyBrandVoice = true, ?string $platformContext = null): array
    {
        if (! $style->humanizes()) {
            return $structured;
        }

        try {
            $input = $format->isCarousel()
                ? [
                    'caption' => data_get($structured, 'caption', ''),
                    'slides' => array_map(
                        fn ($s) => [
                            'title' => data_get($s, 'title', ''),
                            'body' => data_get($s, 'body', ''),
                        ],
                        data_get($structured, 'slides', []),
                    ),
                ]
                : [
                    'content' => data_get($structured, 'content', ''),
                    'image_title' => data_get($structured, 'image_title', ''),
                    'image_body' => data_get($structured, 'image_body', ''),
                ];

            $humanizer = new PostContentHumanizer($workspace, $format, platformContext: $platformContext, applyBrandVoice: $applyBrandVoice);
            $response = $humanizer->prompt(json_encode($input, JSON_UNESCAPED_UNICODE));
            $humanized = $response->structured ?? [];

            RecordAiUsage::recordText(
                workspace: $workspace,
                promptTokens: $response->usage->promptTokens,
                completionTokens: $response->usage->completionTokens,
                provider: (string) $response->meta->provider,
                model: (string) $response->meta->model,
                metadata: ['agent' => 'post_humanizer', 'format' => $format->value, 'source' => 'automation'],
            );

            if ($format->isCarousel()) {
                $structured['caption'] = data_get($humanized, 'caption', data_get($structured, 'caption', ''));
                $originalSlides = data_get($structured, 'slides', []);
                $humanizedSlides = data_get($humanized, 'slides', []);

                foreach ($originalSlides as $i => $slide) {
                    if (isset($humanizedSlides[$i])) {
                        $originalSlides[$i]['title'] = data_get($humanizedSlides[$i], 'title', data_get($slide, 'title', ''));
                        $originalSlides[$i]['body'] = data_get($humanizedSlides[$i], 'body', data_get($slide, 'body', ''));
                    }
                }

                $structured['slides'] = $originalSlides;
            } else {
                $structured['content'] = data_get($humanized, 'content', data_get($structured, 'content', ''));
                $structured['image_title'] = data_get($humanized, 'image_title', data_get($structured, 'image_title', ''));
                $structured['image_body'] = data_get($humanized, 'image_body', data_get($structured, 'image_body', ''));
            }
        } catch (Throwable $e) {
            Log::warning('RunGenerateNode: PostContentHumanizer failed, using generator output as-is', [
                'error' => $e->getMessage(),
            ]);
        }

        return $structured;
    }

    /**
     * Extract the post caption from the raw structured output without calling
     * assemble() (which triggers image generation). Used for dry-run responses
     * so no pipeline work happens during test runs.
     *
     * @param  array<string, mixed>  $structured
     */
    private function extractContent(array $structured, GeneratorFormat $format, ContentStyle $style): string
    {
        if ($style->isTweetCard()) {
            return $format->isCarousel()
                ? (string) data_get($structured, 'caption', '')
                : (string) data_get($structured, 'tweet_text', '');
        }

        return $format->isCarousel()
            ? (string) data_get($structured, 'caption', '')
            : (string) data_get($structured, 'content', '');
    }

    /**
     * Derive the generator format and slide count from per-account content types.
     *
     * Carousel-capable content types:
     *   - instagram_feed       (Instagram feed carousel = multi-image feed post)
     *   - linkedin_post        (LinkedIn multi-image post — 2+ images)
     *   - linkedin_page_post   (LinkedIn page multi-image post)
     *   - pinterest_carousel   (Pinterest carousel pin)
     *   - tiktok_photo         (TikTok photo carousel)
     *
     * When at least one account has a carousel-capable content type AND
     * target_slide_count > 1, the generator is told to produce a carousel with
     * that many slides. Otherwise it falls back to a single-post format.
     *
     * @param  array<int, array{social_account_id: string, content_type: ?string, meta: array<string, mixed>}>  $accountsConfig
     * @param  array<string, mixed>  $config
     * @return array{format: GeneratorFormat, slide_count: int}
     */
    public function deriveFormat(array $accountsConfig, array $config): array
    {
        $maxImagesAcross = 0;
        foreach ($accountsConfig as $entry) {
            $contentType = ContentType::tryFrom((string) data_get($entry, 'content_type'));
            if ($contentType instanceof ContentType && $contentType->supportsImage() && $contentType->maxMediaCount() > 1) {
                $maxImagesAcross = max($maxImagesAcross, $contentType->maxMediaCount());
            }
        }

        $targetSlideCount = (int) data_get($config, 'target_slide_count', 1);

        if ($maxImagesAcross > 1 && $targetSlideCount > 1) {
            $cap = min(GenerateNodeValidator::MAX_GENERATED_IMAGES, $maxImagesAcross);

            return ['format' => GeneratorFormat::Carousel, 'slide_count' => min($targetSlideCount, $cap)];
        }

        return ['format' => GeneratorFormat::Single, 'slide_count' => 1];
    }

    /**
     * Pick the content type the generator should write for so the copy fits
     * every selected network. A Generate node can target one or many accounts,
     * each with its own content type, so we feed the generator the MOST
     * RESTRICTIVE platform (smallest character cap) — content that fits X (280)
     * also fits LinkedIn (3000). Returns null when no account carries a known
     * content type, leaving the generator platform-agnostic.
     *
     * @param  array<int, array{social_account_id: string, content_type: ?string, meta: array<string, mixed>}>  $accountsConfig
     */
    private function resolvePlatformContext(array $accountsConfig): ?string
    {
        return collect($accountsConfig)
            ->map(fn ($entry) => ContentType::tryFrom((string) data_get($entry, 'content_type')))
            ->filter()
            ->sortBy(fn (ContentType $contentType) => $contentType->platform()->maxContentLength())
            ->first()?->value;
    }

    /**
     * Number of images that would be attached for the resolved format. Used as
     * the dry-run indicator and mirrors the non-dry image generation branches:
     * one per slide for carousels, one for single posts when images are enabled.
     * Tweet styles always produce one image per slide/post when an account is set.
     *
     * @param  array<string, mixed>  $structured
     */
    private function intendedImageCount(GeneratorFormat $format, int $slideCount, bool $wantsImage, array $structured, ?SocialAccount $brandAccount, ContentStyle $style): int
    {
        if (! $brandAccount) {
            return 0;
        }

        if ($style->isTweetCard()) {
            return $format->isCarousel() ? $slideCount : 1;
        }

        if ($format->isCarousel()) {
            $slides = data_get($structured, 'slides', []);

            return is_array($slides) ? count($slides) : $slideCount;
        }

        return $wantsImage ? 1 : 0;
    }

    private function resolveUser(AutomationRun $run): User
    {
        if ($run->automation->user_id) {
            return $run->automation->user;
        }

        return $run->automation->workspace->owner;
    }

    /**
     * Read the current `accounts` shape and fall back to the legacy
     * `social_account_ids` array so older automations keep running until
     * the user re-opens and saves the node.
     *
     * @param  array<string, mixed>  $config
     * @return array<int, array{social_account_id: string, content_type: ?string, meta: array<string, mixed>}>
     */
    private function resolveAccountsConfig(array $config): array
    {
        $accounts = data_get($config, 'accounts');

        if (is_array($accounts)) {
            return array_values(array_map(fn ($entry) => [
                'social_account_id' => (string) data_get($entry, 'social_account_id', ''),
                'content_type' => data_get($entry, 'content_type'),
                'meta' => (array) data_get($entry, 'meta', []),
            ], $accounts));
        }

        $legacy = data_get($config, 'social_account_ids', []);

        if (! is_array($legacy)) {
            return [];
        }

        return array_values(array_map(fn ($id) => [
            'social_account_id' => (string) $id,
            'content_type' => null,
            'meta' => [],
        ], $legacy));
    }
}
