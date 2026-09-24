<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Enums\Ai\PostAssistantMode;
use App\Models\Workspace;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

#[Temperature(0.7)]
class PostWritingAssistant implements Agent
{
    use Promptable;

    public function __construct(
        public Workspace $workspace,
        public PostAssistantMode $mode,
        public string $currentContent,
    ) {}

    public function instructions(): string
    {
        $task = match ($this->mode) {
            PostAssistantMode::WriteMore => 'Write a social media caption from the request. If existing text is supplied, continue or improve it while preserving its intent.',
            PostAssistantMode::Rephrase => 'Rephrase the existing caption while preserving its meaning and important details.',
            PostAssistantMode::Shorten => 'Shorten the existing caption substantially while preserving its key message and call to action.',
            PostAssistantMode::Expand => 'Expand the existing caption with useful detail while preserving its meaning and avoiding invented claims.',
        };

        return view('prompts.post_content.assistant', [
            'task' => $task,
            'brand_name' => $this->workspace->name ?? '',
            'brand_description' => $this->workspace->brand_description ?? '',
            'brand_voice_traits' => $this->workspace->brand_voice_traits ?? [],
            'content_language' => $this->workspace->content_language,
            'current_content' => $this->currentContent,
        ])->render();
    }
}
