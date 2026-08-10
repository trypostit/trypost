<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\ResolvesPlatformCopyBudget;
use App\Enums\Ai\GeneratorFormat;
use App\Models\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Second-pass agent that rewrites AI-generated post text to remove AI-tells.
 * Operates on the same structured shape as PostContentGenerator (single or
 * carousel) but only touches human-readable text fields — image_keywords pass
 * through untouched (those need to stay in English for Unsplash regardless).
 */
#[Temperature(0.4)]
class PostContentHumanizer implements Agent, HasStructuredOutput
{
    use Promptable;
    use ResolvesPlatformCopyBudget;

    public function __construct(
        public Workspace $workspace,
        public GeneratorFormat $format = GeneratorFormat::Single,
        public ?string $platformContext = null,
        public bool $applyBrandVoice = true,
    ) {}

    public function instructions(): string
    {
        // The generator already wrote within the platform's character cap; the
        // humanizer must preserve it, so it gets the same budget — otherwise the
        // rewrite can drift past the limit the generator respected.
        $budget = $this->platformCopyBudget($this->platformContext);

        return view('prompts.post_content.humanizer', [
            'brand_name' => $this->workspace->name ?? '',
            'brand_voice_traits' => $this->applyBrandVoice ? ($this->workspace->brand_voice_traits ?? []) : [],
            'content_language' => $this->workspace->content_language,
            'format' => $this->format->value,
            'hard_max_chars' => data_get($budget, 'hard_max_chars'),
            'target_chars' => data_get($budget, 'target_chars'),
            'platform_label' => data_get($budget, 'platform_label'),
        ])->render();
    }

    public function schema(JsonSchema $schema): array
    {
        if ($this->format->isCarousel()) {
            return [
                'caption' => $schema->string()->description('The humanized Instagram caption.')->required(),
                'slides' => $schema->array()
                    ->items($schema->object(fn ($s) => [
                        'title' => $s->string()->description('Humanized slide headline.')->required(),
                        'body' => $s->string()->description('Humanized slide body.')->required(),
                    ]))
                    ->description('The same number of slides as the input, in the same order, with humanized text.')
                    ->required(),
            ];
        }

        return [
            'content' => $schema->string()->description('The humanized post caption.')->required(),
            'image_title' => $schema->string()->description('The humanized image overlay title.')->required(),
            'image_body' => $schema->string()->description('The humanized image overlay body.')->required(),
        ];
    }
}
