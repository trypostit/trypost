<?php

declare(strict_types=1);

namespace App\Ai\Templates;

use App\Enums\Ai\ContentStyle;
use App\Enums\PostPlatform\ContentType;
use App\Services\Image\PostImagePipeline;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class TweetCardImageTemplate implements AiContentTemplate
{
    public function style(): ContentStyle
    {
        return ContentStyle::TweetCardImage;
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
        return false;
    }

    /** @return array<int, string> */
    public function supportedFormats(): array
    {
        return [];
    }

    public function promptView(TemplateContext $context): string
    {
        return $context->isCarousel
            ? 'prompts.post_content.tweet_card_image_carousel'
            : 'prompts.post_content.tweet_card_image';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema, TemplateContext $context): array
    {
        if ($context->isCarousel) {
            $slideCount = $context->imageCount > 0 ? $context->imageCount : 1;

            return [
                'caption' => $schema->string()->description('The caption for the carousel post (teases the content, encourages swiping).')->required(),
                'slides' => $schema->array()
                    ->items($schema->object(fn ($s) => [
                        'tweet_text' => $s->string()
                            ->description('The tweet-style text for this slide. First-person, punchy, max ~560 characters.')
                            ->required(),
                        'image_keywords' => $s->array()
                            ->items($schema->string())
                            ->description('2-4 English keywords for a background photo that fits this slide\'s theme. The photo will be blurred and darkened behind the card.')
                            ->required(),
                    ]))
                    ->min($slideCount)
                    ->max($slideCount)
                    ->description("Exactly {$slideCount} slides, each a self-contained tweet-card. First slide must hook the reader.")
                    ->required(),
            ];
        }

        return [
            'tweet_text' => $schema->string()
                ->description('The post body, written as a punchy first-person X/Twitter-style take. Paragraph breaks (\\n\\n) allowed. Max ~560 characters.')
                ->required(),
            'image_keywords' => $schema->array()
                ->items($schema->string())
                ->description('2-4 English keywords for a background photo that fits the post\'s theme. The photo will be blurred and darkened behind the card.')
                ->required(),
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
    private function assembleSingle(array $structured, TemplateContext $context): GeneratedPost
    {
        $text = (string) data_get($structured, 'tweet_text', '');
        $imageKeywords = data_get($structured, 'image_keywords', []);

        $media = [];

        if ($context->socialAccount) {
            $media = app(PostImagePipeline::class)->forTweetCard(
                workspace: $context->workspace,
                account: $context->socialAccount,
                tweetText: $text,
                imageKeywords: $imageKeywords,
            );
        }

        return new GeneratedPost(
            content: $text,
            media: $media,
            contentType: ContentType::tryFrom($context->format),
        );
    }

    /**
     * @param  array<string, mixed>  $structured
     */
    private function assembleCarousel(array $structured, TemplateContext $context): GeneratedPost
    {
        $caption = (string) data_get($structured, 'caption', '');

        $slides = array_map(
            fn ($slide) => [
                'tweet_text' => (string) data_get($slide, 'tweet_text', ''),
                'image_keywords' => data_get($slide, 'image_keywords', []),
            ],
            data_get($structured, 'slides', []),
        );

        $media = [];

        if ($context->socialAccount) {
            $media = app(PostImagePipeline::class)->forTweetCardCarousel(
                workspace: $context->workspace,
                account: $context->socialAccount,
                slides: $slides,
            );
        }

        return new GeneratedPost(
            content: $caption,
            media: $media,
            contentType: ContentType::InstagramFeed,
        );
    }
}
