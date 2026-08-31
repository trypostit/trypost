<?php

declare(strict_types=1);

namespace App\Jobs\PostHog;

use App\Enums\SocialAccount\Status;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\PostHogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class IdentifyConnectedPlatforms implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public string $workspaceId)
    {
        $this->onQueue('posthog');
    }

    public function handle(PostHogService $postHog): void
    {
        if (! PostHogService::isEnabled()) {
            return;
        }

        $workspace = Workspace::query()
            ->with('account.owner')
            ->find($this->workspaceId);

        $account = $workspace?->account;

        if ($account === null) {
            return;
        }

        $workspacePlatforms = $this->connectedPlatformSlugs(
            SocialAccount::query()->where('workspace_id', $workspace->id),
        );
        $accountPlatforms = $this->connectedPlatformSlugs(
            SocialAccount::query()->whereIn('workspace_id', $account->workspaces()->select('id')),
        );

        $postHog->groupIdentify('workspace', (string) $workspace->id, [
            'connected_platforms' => $workspacePlatforms,
        ]);
        $postHog->groupIdentify('account', (string) $account->id, [
            'connected_platforms' => $accountPlatforms,
        ]);

        $owner = $account->owner;

        if ($owner === null) {
            return;
        }

        $postHog->identify($owner->id, [
            'connected_platforms' => $accountPlatforms,
        ]);
    }

    /**
     * @param  Builder<SocialAccount>  $query
     * @return list<string>
     */
    private function connectedPlatformSlugs(Builder $query): array
    {
        return $query
            ->where('status', Status::Connected)
            ->orderBy('id')
            ->get()
            ->map(fn (SocialAccount $account): string => $account->platform->value)
            ->unique()
            ->values()
            ->all();
    }
}
