<?php

declare(strict_types=1);

namespace App\Events;

use App\Actions\Onboarding\ResolveOnboardingStatus;
use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class OnboardingStatusUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public string $workspaceId) {}

    /**
     * Notify every workspace channel that onboarding state changed — no sync.
     * Use after complete/skip so owner progress banners update immediately
     * even when dispatchForAccount would early-return on stamped timestamps.
     */
    public static function broadcastForAccount(Account $account): void
    {
        foreach ($account->workspaces()->pluck('id') as $workspaceId) {
            static::dispatch((string) $workspaceId);
        }
    }

    /**
     * Sync progress then broadcast to every workspace on the account.
     * Use when a step is account-scoped (e.g. MCP OAuth).
     */
    public static function dispatchForAccount(Account $account, ?User $actor = null): void
    {
        if ($account->isOnboardingOpen()) {
            static::syncAfterCommit($account->id, $actor?->id);
        }
    }

    /**
     * Sync progress for the workspace account, then broadcast.
     * Actor-less flows (webhooks/jobs) fall back to the account owner.
     */
    public static function dispatchForWorkspace(?string $workspaceId, ?User $actor = null): void
    {
        $account = filled($workspaceId)
            ? Workspace::query()->find($workspaceId)?->account
            : null;

        if ($account?->isOnboardingOpen()) {
            static::syncAfterCommit($account->id, $actor?->id);
        }
    }

    private static function syncAfterCommit(string $accountId, ?string $actorId): void
    {
        DB::afterCommit(function () use ($accountId, $actorId): void {
            $account = Account::query()->find($accountId);

            if ($account?->isOnboardingOpen()) {
                app(ResolveOnboardingStatus::class)->syncAndNotify($account, $actorId);
            }
        });
    }

    public function broadcastAs(): string
    {
        return 'onboarding.status.updated';
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("workspace.{$this->workspaceId}"),
        ];
    }

    /**
     * @return array{workspace_id: string}
     */
    public function broadcastWith(): array
    {
        return [
            'workspace_id' => $this->workspaceId,
        ];
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }
}
