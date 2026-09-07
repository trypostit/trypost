<?php

declare(strict_types=1);

namespace App\Rules\Repurpose;

use App\Enums\Repurpose\SourceFormat;
use App\Models\Repurpose;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * One workspace watches a given account for a given format once. The database
 * says the same thing, and says it last — this exists so the user reads a
 * sentence instead of the constraint.
 */
class SourceIsFree implements ValidationRule
{
    public function __construct(
        private readonly ?string $workspaceId,
        private readonly SourceFormat $format,
        private readonly ?string $ignoreRepurposeId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->workspaceId === null || ! Str::isUuid((string) $value)) {
            return;
        }

        $taken = Repurpose::query()
            ->where('workspace_id', $this->workspaceId)
            ->where('source_social_account_id', (string) $value)
            ->where('source_format', $this->format)
            ->when($this->ignoreRepurposeId !== null, fn ($query) => $query->whereKeyNot($this->ignoreRepurposeId))
            ->exists();

        if ($taken) {
            $fail(__('repurposes.errors.source_already_used'));
        }
    }
}
