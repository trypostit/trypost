<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Models\Workspace;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

#[Temperature(0.2)]
class PostContentShortener implements Agent
{
    use Promptable;

    public function __construct(
        public Workspace $workspace,
        public string $platformLabel,
        public int $limit,
    ) {}

    public function instructions(): string
    {
        return view('prompts.post_content.shortener', [
            'brand_name' => $this->workspace->name ?? '',
            'brand_voice_traits' => $this->workspace->brand_voice_traits ?? [],
            'platform_label' => $this->platformLabel,
            'limit' => $this->limit,
        ])->render();
    }
}
