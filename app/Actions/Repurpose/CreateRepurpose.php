<?php

declare(strict_types=1);

namespace App\Actions\Repurpose;

use App\Enums\Repurpose\Status;
use App\Models\Repurpose;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Validation\ValidationException;

class CreateRepurpose
{
    /**
     * A source account feeds exactly one repurpose, so the workspace's existing
     * one is returned rather than a second being created. Callers that want to
     * surface the clash instead can check `existingFor()` first.
     *
     * @param  array<string, mixed>  $data
     */
    public static function execute(Workspace $workspace, User $user, array $data): Repurpose
    {
        $sourceAccountId = (string) data_get($data, 'source_social_account_id');

        if (self::existingFor($workspace, $sourceAccountId) !== null) {
            throw ValidationException::withMessages([
                'source_social_account_id' => __('repurposes.errors.source_already_used'),
            ]);
        }

        return Repurpose::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'source_social_account_id' => $sourceAccountId,
            'destinations' => data_get($data, 'destinations', []),
            'status' => Status::Draft,
        ]);
    }

    public static function existingFor(Workspace $workspace, string $sourceAccountId): ?Repurpose
    {
        return Repurpose::query()
            ->where('workspace_id', $workspace->id)
            ->where('source_social_account_id', $sourceAccountId)
            ->first();
    }
}
