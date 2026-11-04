<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Concerns\OmitsUnsupportedTemperature;
use App\Enums\Ai\PostAssistantMode;
use App\Enums\SocialAccount\Platform;
use App\Enums\User\Locale;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

class PostWritingAssistant implements Agent
{
    use OmitsUnsupportedTemperature;
    use Promptable;

    public function __construct(
        public PostAssistantMode $mode,
        public string $currentContent,
        public Locale $locale,
        public ?Platform $platform = null,
        public ?string $previousContent = null,
        public ?int $hardMaxChars = null,
    ) {}

    public function instructions(): string
    {
        return view('prompts.post_content.assistant', [
            'mode' => $this->mode->value,
            'writes_new_text' => $this->mode->requiresPrompt(),
            'language' => $this->locale->promptLanguage(),
            'current_content' => $this->currentContent,
            'previous_content' => $this->previousContent,
            'platform' => $this->platform?->value,
            'platform_label' => $this->platform?->label(),
            'hard_max_chars' => $this->hardMaxChars ?? $this->platform?->maxContentLength(),
            'target_chars' => $this->platform?->recommendedAiContentLength(),
        ])->render();
    }

    protected function preferredTemperature(): float
    {
        return 0.7;
    }
}
