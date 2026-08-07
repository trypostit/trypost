<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Enums\PostHog\OnboardingEvent;
use App\Enums\SocialAccount\Status;
use App\Events\OnboardingStatusUpdated;
use App\Models\AccessToken;
use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PostHogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

class ResolveOnboardingStatus
{
    /**
     * Checklist step identifiers mapped to their status keys. Steps are derived
     * from real state; optional steps may additionally be skipped.
     */
    public const STEPS = [
        'mcp' => 'mcp_connected',
        'social' => 'social_connected',
        'first_post' => 'first_post_created',
    ];

    public function __construct(
        private readonly PostHogService $postHog,
    ) {}

    public static function totalSteps(): int
    {
        return count(self::STEPS);
    }

    /**
     * Pure read of activation checklist state. Safe for Inertia shared props.
     *
     * @return array{
     *     mcp_connected: bool,
     *     social_connected: bool,
     *     first_post_created: bool,
     *     skipped_steps: list<string>,
     *     all_complete: bool,
     *     show_progress: bool,
     *     completed_at: ?string,
     *     dismissed_at: ?string
     * }
     */
    public function handle(User $user): array
    {
        $account = $user->accountOrFail();

        return $account->isOnboardingCompleted()
            ? $this->completedStatus($account)
            : $this->activeStatus($user, $account);
    }

    /**
     * Read checklist state, capture step analytics, and stamp completion when done.
     * Call from observers / explicit mutations — not from shared Inertia props or GETs.
     *
     * @return array{
     *     mcp_connected: bool,
     *     social_connected: bool,
     *     first_post_created: bool,
     *     skipped_steps: list<string>,
     *     all_complete: bool,
     *     show_progress: bool,
     *     completed_at: ?string,
     *     dismissed_at: ?string
     * }
     */
    public function syncProgress(User $user): array
    {
        $account = $user->accountOrFail();

        // Completed is read-only. Dismissed still runs below so a late MCP
        // connect can clear a leftover mcp skip.
        if ($account->isOnboardingCompleted()) {
            return $this->handle($user);
        }

        $this->clearMcpSkipIfConnected($account);
        $status = $this->handle($user);

        if ($account->isOnboardingDismissed()) {
            return $status;
        }

        $this->captureCompletedSteps($user, $account, $status);

        if (! $status['all_complete']) {
            return $status;
        }

        $this->markCompleted($user);
        $account->refresh();

        return [
            ...$status,
            'show_progress' => false,
            'completed_at' => $account->onboarding_completed_at?->toIso8601String(),
        ];
    }

    /**
     * Sync checklist progress for an account actor, then broadcast progress updates.
     * Used by observers after a step unlocks (post-commit).
     */
    public function syncAndNotify(Account $account, ?string $actorId = null): void
    {
        $actor = $actorId !== null
            ? User::query()->with('account')->find($actorId)
            : null;

        $syncTarget = $actor?->belongsToAccount($account->id)
            ? $actor
            : $account->owner;

        if ($syncTarget !== null) {
            $this->syncProgress($syncTarget);
            $account->refresh();
        }

        if ($account->isOnboardingOpen()) {
            OnboardingStatusUpdated::broadcastForAccount($account);
        }
    }

    /**
     * Stamp account onboarding completion once and capture the funnel event.
     */
    public function markCompleted(User $user): bool
    {
        $account = $user->accountOrFail();

        if (! $account->isOnboardingOpen()) {
            return false;
        }

        $completedAt = now();

        $updated = Account::query()
            ->whereKey($account->id)
            ->onboardingOpen()
            ->update([
                'onboarding_completed_at' => $completedAt,
                'updated_at' => $completedAt,
            ]);

        if ($updated === 0) {
            return false;
        }

        $account->refresh();

        $this->capture($user->id, OnboardingEvent::Completed->value, account: $account);

        // Every stamp path must clear progress banners account-wide.
        OnboardingStatusUpdated::broadcastForAccount($account);

        return true;
    }

    /**
     * Skip the optional MCP step, then stamp completion when it was the last open step.
     */
    public function skipMcp(User $user): bool
    {
        $account = $user->accountOrFail();

        if (! $account->isOnboardingOpen()) {
            return false;
        }

        $status = $this->handle($user);

        if ($status['mcp_connected'] || in_array('mcp', $status['skipped_steps'], true)) {
            return false;
        }

        $skippedSteps = [...$status['skipped_steps'], 'mcp'];

        $updated = Account::query()
            ->whereKey($account->id)
            ->onboardingOpen()
            ->where(function (Builder $query): void {
                $query->whereNull('onboarding_skipped_steps')
                    ->orWhereJsonDoesntContain('onboarding_skipped_steps', 'mcp');
            })
            ->update([
                'onboarding_skipped_steps' => $skippedSteps,
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            return false;
        }

        $account->refresh();

        $this->capture(
            $user->id,
            OnboardingEvent::StepSkipped->value,
            ['step' => 'mcp'],
            $account,
        );

        if ($this->handle($user)['all_complete'] && $this->markCompleted($user)) {
            return true;
        }

        OnboardingStatusUpdated::broadcastForAccount($account);

        return true;
    }

    /**
     * Sidebar checklist progress, or false when the banner should not show.
     *
     * Pure read — safe for Inertia shared props and prefetch. Cross-workspace
     * completion is stamped by syncProgress / observers, not here.
     *
     * @return array{completed: int, total: int}|false
     */
    public function sidebarProgress(?User $user): array|false
    {
        if ($user === null || ! $this->canShowProgress($user)) {
            return false;
        }

        $status = $this->handle($user);

        if (! $status['show_progress']) {
            return false;
        }

        return [
            'completed' => $this->completedStepCount($status),
            'total' => self::totalSteps(),
        ];
    }

    /**
     * Cheap gates before handle()'s EXISTS queries (runs on every Inertia load).
     * Self-hosted and non-owners never reach step state.
     */
    private function canShowProgress(User $user, ?Account $account = null): bool
    {
        if (config('trypost.self_hosted') || ! $user->isAccountOwner()) {
            return false;
        }

        $account ??= $user->accountOrFail();

        return $account->isOnboardingOpen() && $account->hasAppAccess();
    }

    /**
     * @return array{
     *     mcp_connected: bool,
     *     social_connected: bool,
     *     first_post_created: bool,
     *     skipped_steps: list<string>,
     *     all_complete: bool,
     *     show_progress: bool,
     *     completed_at: string,
     *     dismissed_at: ?string
     * }
     */
    private function completedStatus(Account $account): array
    {
        $skippedSteps = $account->onboarding_skipped_steps ?? [];

        // Preserve skip vs real MCP completion for the ready UI badge.
        $mcpConnected = ! in_array('mcp', $skippedSteps, true)
            || $this->accountHasMcpConnection($account);

        return [
            'mcp_connected' => $mcpConnected,
            'social_connected' => true,
            'first_post_created' => true,
            'skipped_steps' => $mcpConnected
                ? array_values(array_diff($skippedSteps, ['mcp']))
                : $skippedSteps,
            'all_complete' => true,
            'show_progress' => false,
            'completed_at' => $account->onboarding_completed_at->toIso8601String(),
            'dismissed_at' => $account->onboarding_dismissed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{
     *     mcp_connected: bool,
     *     social_connected: bool,
     *     first_post_created: bool,
     *     skipped_steps: list<string>,
     *     all_complete: bool,
     *     show_progress: bool,
     *     completed_at: null,
     *     dismissed_at: ?string
     * }
     */
    private function activeStatus(User $user, Account $account): array
    {
        $skippedSteps = $account->onboarding_skipped_steps ?? [];
        $steps = $this->stepStates($account);

        $allComplete = collect($steps)->every(
            fn (bool $done, string $step): bool => $this->stepIsComplete($done, $step, $skippedSteps),
        );

        return [
            'mcp_connected' => $steps['mcp'],
            'social_connected' => $steps['social'],
            'first_post_created' => $steps['first_post'],
            'skipped_steps' => $skippedSteps,
            'all_complete' => $allComplete,
            'show_progress' => $this->canShowProgress($user, $account) && ! $allComplete,
            'completed_at' => null,
            'dismissed_at' => $account->onboarding_dismissed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{mcp: bool, social: bool, first_post: bool}
     */
    private function stepStates(Account $account): array
    {
        return [
            'mcp' => $this->accountHasMcpConnection($account),
            'social' => $this->accountHasSocialConnection($account),
            'first_post' => $this->accountHasPost($account),
        ];
    }

    /**
     * @param  list<string>  $skippedSteps
     */
    private function stepIsComplete(bool $done, string $step, array $skippedSteps): bool
    {
        return $done || in_array($step, $skippedSteps, true);
    }

    /**
     * @param  array{skipped_steps: list<string>, mcp_connected: bool, social_connected: bool, first_post_created: bool}  $status
     */
    private function completedStepCount(array $status): int
    {
        return collect(self::STEPS)
            ->filter(fn (string $statusKey, string $step): bool => $this->stepIsComplete(
                $status[$statusKey],
                $step,
                $status['skipped_steps'],
            ))
            ->count();
    }

    /**
     * @param  array{mcp_connected: bool, social_connected: bool, first_post_created: bool}  $status
     */
    private function captureCompletedSteps(User $user, Account $account, array $status): void
    {
        foreach (self::STEPS as $step => $statusKey) {
            $this->captureOnce(
                "onboarding:step:{$account->id}:{$step}",
                fn () => $this->postHog->capture(
                    $user->id,
                    OnboardingEvent::StepCompleted->value,
                    ['step' => $step],
                    $account,
                ),
                when: $status[$statusKey],
            );
        }
    }

    private function accountHasMcpConnection(Account $account): bool
    {
        return AccessToken::query()
            ->onboardingMcpConnection($account)
            ->exists();
    }

    private function accountHasSocialConnection(Account $account): bool
    {
        return Workspace::query()
            ->where('account_id', $account->id)
            ->whereHas(
                'socialAccounts',
                fn (Builder $query): Builder => $query->where('status', Status::Connected),
            )
            ->exists();
    }

    private function accountHasPost(Account $account): bool
    {
        return Workspace::query()
            ->where('account_id', $account->id)
            ->whereHas('posts')
            ->exists();
    }

    private function clearMcpSkipIfConnected(Account $account): void
    {
        $skipped = $account->onboarding_skipped_steps ?? [];

        if (! in_array('mcp', $skipped, true) || ! $this->accountHasMcpConnection($account)) {
            return;
        }

        Account::query()
            ->whereKey($account->id)
            ->whereNull('onboarding_completed_at')
            ->update([
                'onboarding_skipped_steps' => array_values(array_diff($skipped, ['mcp'])) ?: null,
                'updated_at' => now(),
            ]);

        $account->refresh();
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function capture(
        string $userId,
        string $event,
        array $properties = [],
        ?Account $account = null,
    ): void {
        if (! PostHogService::isEnabled()) {
            return;
        }

        $this->postHog->capture($userId, $event, $properties, $account);
    }

    /**
     * Claim a cache slot then capture — concurrent syncs don't double-fire.
     * Releases the slot if capture throws so a later retry can still report.
     */
    private function captureOnce(string $dedupeKey, callable $capture, bool $when = true): void
    {
        if (! $when || ! PostHogService::isEnabled() || ! Cache::add($dedupeKey, true, now()->addYear())) {
            return;
        }

        try {
            $capture();
        } catch (\Throwable $exception) {
            Cache::forget($dedupeKey);

            throw $exception;
        }
    }
}
