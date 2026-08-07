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
     * Use after complete/skip so owner residual banners update immediately
     * even when dispatchForAccount would early-return on stamped timestamps.
     */
    public static function broadcastForAccount(?Account $account): void
    {
        if ($account === null) {
            return;
        }

        foreach ($account->workspaces()->pluck('id') as $workspaceId) {
            static::dispatch((string) $workspaceId);
        }
    }

    /**
     * Broadcast to every workspace on the account and sync progress for the actor.
     * Use when a step is account-scoped (e.g. MCP OAuth).
     *
     * Sync/analytics run afterCommit so Cache/PostHog never outlive a rolled-back
     * CreatePost (or similar) transaction.
     */
    public static function dispatchForAccount(?Account $account, ?User $actor = null): void
    {
        if ($account === null || $account->hasFinishedOnboarding()) {
            return;
        }

        $accountId = $account->id;
        $actorId = $actor?->id;

        DB::afterCommit(function () use ($accountId, $actorId): void {
            $account = Account::query()->find($accountId);

            if ($account === null || $account->hasFinishedOnboarding()) {
                return;
            }

            static::syncAndBroadcast($account, $actorId);
        });
    }

    /**
     * Broadcast only while the workspace account still has active onboarding.
     * Steps are account-scoped, so syncing the actor (or the account owner when
     * there is no actor, e.g. webhook-driven connects) is enough to stamp.
     *
     * Sync/analytics run afterCommit so Cache/PostHog never outlive a rolled-back
     * CreatePost (or similar) transaction.
     */
    public static function dispatchForWorkspace(?string $workspaceId, ?User $actor = null): void
    {
        if (blank($workspaceId)) {
            return;
        }

        $account = Workspace::query()->find($workspaceId)?->account;

        if ($account === null || $account->hasFinishedOnboarding()) {
            return;
        }

        $accountId = $account->id;
        $actorId = $actor?->id;

        DB::afterCommit(function () use ($accountId, $actorId): void {
            $account = Account::query()->find($accountId);

            if ($account === null || $account->hasFinishedOnboarding()) {
                return;
            }

            static::syncAndBroadcast($account, $actorId);
        });
    }

    /**
     * Stamp completion for the actor — falling back to the account owner so
     * actor-less flows (Telegram webhook, jobs) still complete — then notify
     * every workspace channel exactly once. markCompleted already broadcasts
     * when it stamps, so an un-stamped sync is the only case that fans out here.
     */
    private static function syncAndBroadcast(Account $account, ?string $actorId): void
    {
        $actor = $actorId !== null
            ? User::query()->with('account')->find($actorId)
            : null;

        $syncTarget = $actor !== null && (string) $actor->account_id === (string) $account->id
            ? $actor
            : $account->owner;

        if ($syncTarget !== null) {
            app(ResolveOnboardingStatus::class)->syncProgress($syncTarget);
            $account->refresh();
        }

        if (! $account->hasFinishedOnboarding()) {
            static::broadcastForAccount($account);
        }
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
