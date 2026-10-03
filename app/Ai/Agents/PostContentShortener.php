<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

#[Temperature(0.2)]
class PostContentShortener implements Agent
{
    use Promptable;

    public function __construct(
        public string $platformLabel,
        public int $limit,
    ) {}

    public function instructions(): string
    {
        return view('prompts.post_content.shortener', [
            'platform_label' => $this->platformLabel,
            'limit' => $this->limit,
            'target' => max(1, (int) floor($this->limit * 0.95)),
        ])->render();
    }
}
