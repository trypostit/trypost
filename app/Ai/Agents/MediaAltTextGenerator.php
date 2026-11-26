<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Concerns\OmitsUnsupportedTemperature;
use App\Enums\User\Locale;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

class MediaAltTextGenerator implements Agent
{
    use OmitsUnsupportedTemperature;
    use Promptable;

    public const int MAX_LENGTH = 250;

    public function __construct(
        public Locale $locale,
    ) {}

    public function instructions(): string
    {
        return view('prompts.post_image.alt_text', [
            'language' => $this->locale->promptLanguage(),
            'max_length' => self::MAX_LENGTH,
        ])->render();
    }

    protected function preferredTemperature(): float
    {
        return 0.2;
    }
}
