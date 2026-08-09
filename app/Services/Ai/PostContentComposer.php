<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Ai\Agents\PostContentGenerator;
use App\Ai\Agents\PostContentHumanizer;
use App\Ai\Templates\AiContentTemplate;
use App\Ai\Templates\TemplateContext;
use App\Enums\Ai\ContentStyle;
use App\Enums\Ai\GeneratorFormat;
use App\Enums\PostPlatform\ContentType;
use App\Models\Workspace;
use Illuminate\Support\Facades\Log;

/**
 * Produces the structured TEXT of an AI post (no images): runs the content
 * generator, then the humanizer pass. Extracted from StreamPostCreation so the
 * one-shot flow and the two-phase review flow (PreparePostContent) share one
 * implementation instead of drifting apart.
 *
 * Behaviour is identical to the inline version it replaces.
 */
class PostContentComposer
{
    /**
     * @return array<string, mixed>
     */
    public function compose(
        Workspace $workspace,
        string $format,
        int $imageCount,
        string $prompt,
        AiContentTemplate $style,
        TemplateContext $context,
        string $userId,
    ): array {
        $isCarousel = $format === ContentType::CAROUSEL_FORMAT;
        $agentFormat = $isCarousel ? GeneratorFormat::Carousel : GeneratorFormat::Single;
        $slideCount = $isCarousel && $imageCount > 0 ? $imageCount : 1;

        $agent = new PostContentGenerator(
            workspace: $workspace,
            format: $agentFormat,
            slideCount: $slideCount,
            platformContext: $format,
            template: $style,
            templateContext: $context,
        );

        $response = $agent->prompt($prompt);

        RecordAiUsage::recordText(
            workspace: $workspace,
            promptTokens: $response->usage->promptTokens,
            completionTokens: $response->usage->completionTokens,
            provider: (string) config('ai.default'),
            model: (string) config('ai.default_text_model'),
            userId: $userId,
            metadata: ['agent' => 'post_generator', 'format' => $format],
        );

        return $this->humanize($workspace, $response->structured ?? [], $agentFormat, $style->style(), $userId, $format);
    }

    /**
     * Run the structured generator output through the humanizer pass and merge
     * the humanized text fields back over the original structure (preserving
     * image_keywords and slide order/count). Failures are logged and the
     * original structure is returned so generation never breaks because of the
     * polish step.
     *
     * @param  array<string, mixed>  $structured
     * @return array<string, mixed>
     */
    private function humanize(
        Workspace $workspace,
        array $structured,
        GeneratorFormat $format,
        ContentStyle $style,
        string $userId,
        string $platformFormat,
    ): array {
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

            $humanizer = new PostContentHumanizer($workspace, $format, platformContext: $platformFormat);
            $response = $humanizer->prompt(json_encode($input, JSON_UNESCAPED_UNICODE));
            $humanized = $response->structured ?? [];

            RecordAiUsage::recordText(
                workspace: $workspace,
                promptTokens: $response->usage->promptTokens,
                completionTokens: $response->usage->completionTokens,
                provider: (string) config('ai.default'),
                model: (string) config('ai.default_text_model'),
                userId: $userId,
                metadata: ['agent' => 'post_humanizer', 'format' => $format->value],
            );
        } catch (\Throwable $e) {
            Log::warning('PostContentHumanizer failed, using generator output as-is', [
                'error' => $e->getMessage(),
            ]);

            return $structured;
        }

        if ($format->isCarousel()) {
            $structured['caption'] = data_get($humanized, 'caption', $structured['caption'] ?? '');
            $originalSlides = $structured['slides'] ?? [];
            $humanizedSlides = data_get($humanized, 'slides', []);

            foreach ($originalSlides as $i => $slide) {
                if (isset($humanizedSlides[$i])) {
                    $originalSlides[$i]['title'] = data_get($humanizedSlides[$i], 'title', $slide['title'] ?? '');
                    $originalSlides[$i]['body'] = data_get($humanizedSlides[$i], 'body', $slide['body'] ?? '');
                }
            }

            $structured['slides'] = $originalSlides;
        } else {
            $structured['content'] = data_get($humanized, 'content', $structured['content'] ?? '');
            $structured['image_title'] = data_get($humanized, 'image_title', $structured['image_title'] ?? '');
            $structured['image_body'] = data_get($humanized, 'image_body', $structured['image_body'] ?? '');
        }

        return $structured;
    }
}
