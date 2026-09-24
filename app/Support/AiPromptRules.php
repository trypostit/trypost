<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Shared maximum length for AI prompts from the post assistant and the
 * existing post editing tools.
 */
class AiPromptRules
{
    /**
     * Maximum prompt length (characters); mirrored by the frontend counter.
     */
    public const PROMPT_MAX_LENGTH = 2000;
}
