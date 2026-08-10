<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Models\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

#[Temperature(0.25)]
class PostImageRegenerator implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(
        public Workspace $workspace,
    ) {}

    public function instructions(): string
    {
        return view('prompts.post_image.regenerator', [
            'content_language' => $this->workspace->content_language ?: 'en',
        ])->render();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()
                ->description('Updated short title for the image (max ~120 chars).')
                ->required(),
            'body' => $schema->string()
                ->description('Updated supporting text for the image (max ~240 chars).')
                ->required(),
            'keywords' => $schema->array()
                ->items($schema->string())
                ->description('3-10 short keywords for image generation context.')
                ->required(),
            'change_mode' => $schema->string()
                ->enum(['image_only', 'text_only', 'both'])
                ->description('Set to image_only (change visual only), text_only (change text only), or both (change visual and text).')
                ->required(),
        ];
    }
}
