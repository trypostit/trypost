<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Models\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

#[Temperature(0.2)]
class PostContentReviewer implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(
        public Workspace $workspace,
    ) {}

    public function instructions(): string
    {
        return view('prompts.post_content.reviewer', [
            'brand_name' => $this->workspace->name ?? '',
            'brand_voice_traits' => $this->workspace->brand_voice_traits ?? [],
            'content_language' => $this->workspace->content_language,
        ])->render();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'suggestions' => $schema->array()
                ->items($schema->object(fn ($s) => [
                    'original' => $s->string()->description('The exact substring of the input that needs correction.')->required(),
                    'suggestion' => $s->string()->description('The corrected version.')->required(),
                    'reason' => $s->string()->description('1-line explanation in the output language.')->required(),
                ]))
                ->description('Up to 8 grammar/spelling/clarity suggestions. Empty array if the text is fine.')
                ->required(),
        ];
    }
}
