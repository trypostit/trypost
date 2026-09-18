<?php

declare(strict_types=1);

namespace App\Support\Repurpose;

use App\Enums\Repurpose\SourceFormat;
use App\Models\Repurpose;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class SourceIsFree
{
    public static function addErrors(
        Validator $validator,
        ?string $workspaceId,
        ?string $sourceAccountId,
        SourceFormat $format,
        ?string $ignoreRepurposeId = null,
    ): void {
        if (self::isTaken($workspaceId, $sourceAccountId, $format, $ignoreRepurposeId)) {
            $validator->errors()->add('source_social_account_id', __('repurposes.errors.source_already_used'));
        }
    }

    public static function assert(
        ?string $workspaceId,
        ?string $sourceAccountId,
        SourceFormat $format,
        ?string $ignoreRepurposeId = null,
    ): void {
        if (self::isTaken($workspaceId, $sourceAccountId, $format, $ignoreRepurposeId)) {
            throw ValidationException::withMessages([
                'source_social_account_id' => __('repurposes.errors.source_already_used'),
            ]);
        }
    }

    private static function isTaken(
        ?string $workspaceId,
        ?string $sourceAccountId,
        SourceFormat $format,
        ?string $ignoreRepurposeId,
    ): bool {
        if ($workspaceId === null || $sourceAccountId === null) {
            return false;
        }

        return Repurpose::query()
            ->where('workspace_id', $workspaceId)
            ->where('source_social_account_id', $sourceAccountId)
            ->where('source_format', $format)
            ->when($ignoreRepurposeId !== null, fn ($query) => $query->whereKeyNot($ignoreRepurposeId))
            ->exists();
    }
}
