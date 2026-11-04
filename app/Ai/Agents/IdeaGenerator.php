<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Concerns\OmitsUnsupportedTemperature;
use App\Enums\User\Locale;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

class IdeaGenerator implements Agent, HasStructuredOutput
{
    use OmitsUnsupportedTemperature;
    use Promptable;

    public function __construct(
        public Locale $locale,
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
            'language' => $this->locale->promptLanguage(),
        ])->render();
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required(),
            'body' => $schema->string()->required(),
        ];
    }

    protected function preferredTemperature(): float
    {
        return 0.8;
    }
}
