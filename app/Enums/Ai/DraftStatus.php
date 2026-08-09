<?php

declare(strict_types=1);

namespace App\Enums\Ai;

/**
 * Lifecycle of an AI post draft: the two-phase "review before final generation"
 * flow. Preparing → Ready (text pre-generated, awaiting user review) →
 * Generating (images being rendered from the reviewed text) → Completed.
 */
enum DraftStatus: string
{
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Generating = 'generating';
    case Completed = 'completed';
    case Failed = 'failed';
}
