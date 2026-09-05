<?php

declare(strict_types=1);

namespace App\Actions\Repurpose;

use App\Enums\Repurpose\SourceFormat;
use App\Enums\Repurpose\Status;
use App\Models\Repurpose;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Validation\ValidationException;

class CreateRepurpose
{
    /**
     * A repurpose watches one format, so a creator replicating both their Reels
     * and their Stories has two on the same account. Only the same account and
     * the same format together are a duplicate.
     *
     * @param  array<string, mixed>  $data
     */
    public static function execute(Workspace $workspace, User $user, array $data): Repurpose
    {
        $sourceAccountId = (string) data_get($data, 'source_social_account_id');
        $sourceFormat = SourceFormat::tryFrom((string) data_get($data, 'source_format')) ?? SourceFormat::Reel;

        if (self::existingFor($workspace, $sourceAccountId, $sourceFormat) !== null) {
            throw ValidationException::withMessages([
                'source_social_account_id' => __('repurposes.errors.source_already_used'),
            ]);
        }

        return Repurpose::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'source_social_account_id' => $sourceAccountId,
            'source_format' => $sourceFormat,
            'destinations' => data_get($data, 'destinations', []),
            'status' => Status::Draft,
        ]);
    }

    public static function existingFor(Workspace $workspace, string $sourceAccountId, SourceFormat $format): ?Repurpose
    {
        return Repurpose::query()
            ->where('workspace_id', $workspace->id)
            ->where('source_social_account_id', $sourceAccountId)
            ->where('source_format', $format)
            ->first();
    }
}
