<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Enums\Ai\GeneratorFormat;
use App\Models\Workspace;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

/**
 * Streaming-compatible agent for inline post content generation in the editor.
 *
 * This is a separate agent from PostContentGenerator because the Laravel AI SDK
 * does not support streaming with HasStructuredOutput. Use this agent for
 * broadcast()/stream() calls; use PostContentGenerator for prompt() with
 * structured output in the AI wizard pipeline.
 */
#[Temperature(0.7)]
class PostContentStreamer implements Agent
{
    use Promptable;

    public function __construct(
        public Workspace $workspace,
        public ?string $currentContent = null,
    ) {}

    public function instructions(): string
    {
        return view('prompts.post_content.generator', [
            'brand_name' => $this->workspace->name ?? '',
            'brand_description' => $this->workspace->brand_description ?? '',
            'brand_voice_traits' => $this->workspace->brand_voice_traits ?? [],
            'content_language' => $this->workspace->content_language,
            'current_content' => $this->currentContent,
            'format' => GeneratorFormat::Single->value,
            'slide_count' => 1,
        ])->render();
    }
}
