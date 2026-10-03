<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Enums\User\Locale;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

#[Temperature(0.8)]
class IdeaGenerator implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(
        public Locale $locale,
        public int $count,
        public string $business,
        public string $audience,
        public ?string $notes = null,
    ) {}

    public function instructions(): string
    {
        return view('prompts.ideas.generator', [
            'business' => $this->business,
            'audience' => $this->audience,
            'notes' => $this->notes,
            'count' => $this->count,
            'language' => $this->locale->promptLanguage(),
        ])->render();
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'ideas' => $schema->array()
                ->items($schema->object([
                    'title' => $schema->string()->required(),
                    'body' => $schema->string()->required(),
                ]))
                ->required(),
        ];
    }
}
