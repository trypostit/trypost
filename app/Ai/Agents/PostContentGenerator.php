<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\ResolvesPlatformCopyBudget;
use App\Ai\Templates\AiContentTemplate;
use App\Ai\Templates\TemplateContext;
use App\Enums\Ai\GeneratorFormat;
use App\Models\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

#[Temperature(0.7)]
class PostContentGenerator implements Agent, HasStructuredOutput
{
    use Promptable;
    use ResolvesPlatformCopyBudget;

    public function __construct(
        public Workspace $workspace,
        public ?string $currentContent = null,
        public GeneratorFormat $format = GeneratorFormat::Single,
        public int $slideCount = 1,
        public ?string $platformContext = null,
        public bool $applyBrandVoice = true,
        public ?AiContentTemplate $template = null,
        public ?TemplateContext $templateContext = null,
    ) {}

    public function instructions(): string
    {
        $budget = $this->platformCopyBudget($this->platformContext);

        if ($this->template?->style()->isTweetCard()) {
            $budget['hard_max_chars'] = 560;
            $budget['target_chars'] = 280;
        }

        $view = $this->template !== null && $this->templateContext !== null
            ? $this->template->promptView($this->templateContext)
            : 'prompts.post_content.generator';

        return view($view, [
            'brand_name' => $this->workspace->name ?? '',
            'brand_description' => $this->applyBrandVoice ? ($this->workspace->brand_description ?? '') : '',
            'brand_voice_traits' => $this->applyBrandVoice ? ($this->workspace->brand_voice_traits ?? []) : [],
            'content_language' => $this->workspace->content_language,
            'current_content' => $this->currentContent,
            'format' => $this->format->value,
            'slide_count' => $this->slideCount,
            'hard_max_chars' => data_get($budget, 'hard_max_chars'),
            'target_chars' => data_get($budget, 'target_chars'),
            'platform_label' => data_get($budget, 'platform_label'),
        ])->render();
    }

    public function schema(JsonSchema $schema): array
    {
        if ($this->template !== null && $this->templateContext !== null) {
            return $this->template->schema($schema, $this->templateContext);
        }

        if ($this->format->isCarousel()) {
            return [
                'caption' => $schema->string()->description('The Instagram caption for the carousel post.')->required(),
                'slides' => $schema->array()
                    ->items($schema->object(fn ($s) => [
                        'role' => $s->string()
                            ->enum(['hook', 'development', 'proof', 'cta'])
                            ->description('The role of this slide in the carousel arc. First slide is `hook` (specific real problem). Last slide is `cta` (one specific next action). Middle slides are `development` (unfold the idea) or `proof` (concrete result, before/after, behind-the-scenes, real learning). For 4+ slides, at least one middle slide must be `proof`.')
                            ->required(),
                        'title' => $s->string()->description('Headline of the slide. Short, impactful.')->required(),
                        'body' => $s->string()->description('Supporting body below the headline. 1-3 sentences.')->required(),
                        'image_keywords' => $s->array()->items($schema->string())->description('2-4 search keywords for Unsplash.')->required(),
                    ]))
                    ->min($this->slideCount)
                    ->max($this->slideCount)
                    ->description("Exactly {$this->slideCount} slides for the carousel, in order. First slide must have role `hook`, last slide must have role `cta`.")
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
}
