<?php

declare(strict_types=1);

namespace App\Ai\Templates;

use App\Ai\Templates\Concerns\ResolvesContentType;
use App\Enums\Ai\ContentStyle;
use App\Enums\PostPlatform\ContentType;
use App\Services\Image\PostImagePipeline;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class ImageCardTemplate implements AiContentTemplate
{
    use ResolvesContentType;

    public function style(): ContentStyle
    {
        return ContentStyle::ImageCard;
    }

    public function key(): string
    {
        return $this->style()->value;
    }

    public function name(): string
    {
        return $this->style()->label();
    }

    public function description(): string
    {
        return $this->style()->description();
    }

    public function previewAsset(): string
    {
        return $this->style()->previewAsset();
    }

    public function needsAccount(): bool
    {
        return $this->style()->needsAccount();
    }

    public function appliesBrandVisuals(): bool
    {
        return true;
    }

    public function supportedFormats(): array
    {
        return [];
    }

    public function promptView(TemplateContext $context): string
    {
        return 'prompts.post_content.generator';
    }

    public function schema(JsonSchema $schema, TemplateContext $context): array
    {
        if ($context->isCarousel) {
            $slideCount = $context->imageCount > 0 ? $context->imageCount : 1;

            return [
                'caption' => $schema->string()->description('The Instagram caption for the carousel post.')->required(),
                'slides' => $schema->array()
                    ->items($schema->object(fn ($s) => [
                        'role' => $s->string()
                            ->enum(['hook', 'development', 'proof', 'cta'])
                            ->description('The role of this slide in the carousel arc. First slide is `hook` (specific real problem). Last slide is `cta` (one specific next action). Middle slides are `development` (unfold the idea) or `proof` (concrete result, before/after, behind-the-scenes, real learning). For 4+ slides, at least one middle slide must be `proof`.')
                            ->required(),
                        'title' => $s->string()->description('Headline of the slide. Short, impactful.')->required(),
                        'body' => $s->string()->description('Supporting text below the headline. 1-3 sentences.')->required(),
                        'image_keywords' => $s->array()->items($schema->string())->description('2-4 search keywords for Unsplash.')->required(),
                    ]))
                    ->min($slideCount)
                    ->max($slideCount)
                    ->description("Exactly {$slideCount} slides for the carousel, in order. First slide must have role `hook`, last slide must have role `cta`.")
                    ->required(),
            ];
        }

        return [
            'content' => $schema->string()->description('The full post caption text that will be published on the platform.')->required(),
            'image_title' => $schema->string()->description('Short headline (5-12 words) overlaid on the image. The hook — should make a scroller stop. Distinct from content.')->required(),
            'image_body' => $schema->string()->description('1-2 short sentences (max 25 words) overlaid below the image_title. Expands the hook just enough to compel reading the caption.')->required(),
            'image_keywords' => $schema->array()->items($schema->string())->description('2-4 search keywords for Unsplash for the single image.')->required(),
        ];
    }

    /**
     * @param  array<string, mixed>  $structured
     */
    public function assemble(array $structured, TemplateContext $context): GeneratedPost
    {
        if ($context->isCarousel) {
            return $this->assembleCarousel($structured, $context);
        }

        return $this->assembleSingle($structured, $context);
    }

    /**
     * @param  array<string, mixed>  $structured
     */
    private function assembleCarousel(array $structured, TemplateContext $context): GeneratedPost
    {
        $caption = (string) data_get($structured, 'caption', '');

        $media = [];

        if ($context->socialAccount) {
            $media = app(PostImagePipeline::class)->forCarousel(
                workspace: $context->workspace,
                account: $context->socialAccount,
                structured: $structured,
                contentType: ContentType::InstagramFeed,
                applyBrandVisuals: $context->applyBrandVisuals,
            );
        }

        return new GeneratedPost($caption, $media, ContentType::InstagramFeed);
    }

    /**
     * @param  array<string, mixed>  $structured
     */
    private function assembleSingle(array $structured, TemplateContext $context): GeneratedPost
    {
        $contentType = self::resolveContentType($context->format);
        $supportsCaption = $contentType?->supportsCaption() ?? true;

        $rawContent = (string) data_get($structured, 'content', data_get($structured, 'text', ''));

        $media = [];

        if ($context->imageCount > 0 && $context->socialAccount) {
            $media = app(PostImagePipeline::class)->forSingle(
                workspace: $context->workspace,
                account: $context->socialAccount,
                structured: $structured,
                contentType: $contentType,
                applyBrandVisuals: $context->applyBrandVisuals,
            );
        }

        $caption = $supportsCaption ? $rawContent : '';

        return new GeneratedPost($caption, $media, $contentType);
    }
}
