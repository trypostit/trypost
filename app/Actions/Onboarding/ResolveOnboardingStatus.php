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
    public const TOTAL_STEPS = 3;

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

    /**
     * Pure read of activation checklist state. Safe for Inertia shared props.
     *
     * @return array{
     *     mcp_connected: bool,
     *     social_connected: bool,
     *     first_post_created: bool,
     *     skipped_steps: list<string>,
     *     all_complete: bool,
     *     show_residual: bool,
     *     completed_at: ?string,
     *     dismissed_at: ?string
     * }
     */
    public function handle(User $user): array
    {
        $account = $user->account;
        $skippedSteps = $account?->onboarding_skipped_steps ?? [];

        if ($account?->onboarding_completed_at !== null) {
            // Preserve skip vs real MCP completion for the ready UI badge.
            $mcpConnected = ! in_array('mcp', $skippedSteps, true)
                || $this->accountHasMcpConnection($account);
            $effectiveSkippedSteps = $mcpConnected
                ? array_values(array_diff($skippedSteps, ['mcp']))
                : $skippedSteps;

            return [
                'mcp_connected' => $mcpConnected,
                'social_connected' => true,
                'first_post_created' => true,
                'skipped_steps' => $effectiveSkippedSteps,
                'all_complete' => true,
                'show_residual' => false,
                'completed_at' => $account->onboarding_completed_at->toIso8601String(),
                'dismissed_at' => $account->onboarding_dismissed_at?->toIso8601String(),
            ];
        }

        // All three steps are account-scoped so checklist checkmarks, residual
        // progress, and cross-workspace completion stay aligned.
        $stepStates = [
            'mcp' => $this->accountHasMcpConnection($account),
            'social' => $this->accountHasSocialConnection($account),
            'first_post' => $this->accountHasPost($account),
        ];

        // A skipped optional step counts as done for completion purposes.
        $allComplete = collect($stepStates)
            ->every(fn (bool $done, string $step): bool => $done || in_array($step, $skippedSteps, true));

        $showResidual = ! config('trypost.self_hosted')
            && $user->isAccountOwner()
            && ($account?->hasAppAccess() ?? false)
            && $account->onboarding_dismissed_at === null
            && ! $allComplete;

        return [
            'mcp_connected' => $stepStates['mcp'],
            'social_connected' => $stepStates['social'],
            'first_post_created' => $stepStates['first_post'],
            'skipped_steps' => $skippedSteps,
            'all_complete' => $allComplete,
            'show_residual' => $showResidual,
            'completed_at' => null,
            'dismissed_at' => $account?->onboarding_dismissed_at?->toIso8601String(),
        ];
    }

    /**
     * Read checklist state, capture step analytics, and stamp completion when done.
     * Call from intentional onboarding surfaces — not from shared Inertia props.
     *
     * @return array{
     *     mcp_connected: bool,
     *     social_connected: bool,
     *     first_post_created: bool,
     *     skipped_steps: list<string>,
     *     all_complete: bool,
     *     show_residual: bool,
     *     completed_at: ?string,
     *     dismissed_at: ?string
     * }
     */
    public function syncProgress(User $user): array
    {
        $status = $this->handle($user);
        $account = $user->account;

        if ($account === null || $account->onboarding_completed_at !== null) {
            return $status;
        }

        if ($account->onboarding_dismissed_at === null) {
            foreach (self::STEPS as $step => $statusKey) {
                $this->captureCompletedStep($user, $account, $step, $status[$statusKey]);
            }
        }

        if ($account->onboarding_dismissed_at !== null) {
            return $status;
        }

        if ($status['all_complete']) {
            $this->markCompleted($user);
            $account->refresh();

            return [
                ...$status,
                'show_residual' => false,
                'completed_at' => $account->onboarding_completed_at?->toIso8601String(),
            ];
        }

        return $status;
    }

    /**
     * Stamp account onboarding completion once and capture the funnel event.
     */
    public function markCompleted(User $user): bool
    {
        $account = $user->account;

        if (
            $account === null
            || $account->hasFinishedOnboarding()
        ) {
            return false;
        }

        $completedAt = now();

        $updated = Account::query()
            ->whereKey($account->id)
            ->whereNull('onboarding_completed_at')
            ->whereNull('onboarding_dismissed_at')
            ->update([
                'onboarding_completed_at' => $completedAt,
                'updated_at' => $completedAt,
            ]);

        if ($updated === 0) {
            return false;
        }

        $account->refresh();

        if (PostHogService::isEnabled()) {
            $this->postHog->capture(
                $user->id,
                OnboardingEvent::Completed->value,
                account: $account,
            );
        }

        // Every stamp path (endpoint, syncProgress, observers) must clear residual
        // banners account-wide — not only the explicit complete() action.
        OnboardingStatusUpdated::broadcastForAccount($account);

        return true;
    }

    /**
     * Skip the optional MCP step, then stamp completion when it was the last open step.
     */
    public function skipMcp(User $user): bool
    {
        $account = $user->account;

        if ($account === null || $account->hasFinishedOnboarding()) {
            return false;
        }

        $status = $this->handle($user);

        if ($status['mcp_connected'] || in_array('mcp', $status['skipped_steps'], true)) {
            return false;
        }

        $skippedSteps = [...$status['skipped_steps'], 'mcp'];

        $updated = Account::query()
            ->whereKey($account->id)
            ->whereNull('onboarding_completed_at')
            ->whereNull('onboarding_dismissed_at')
            ->update([
                'onboarding_skipped_steps' => $skippedSteps,
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            return false;
        }

        $account->refresh();

        if (PostHogService::isEnabled()) {
            $this->postHog->capture(
                $user->id,
                OnboardingEvent::StepSkipped->value,
                ['step' => 'mcp'],
                $account,
            );
        }

        $completed = $this->handle($user)['all_complete'] && $this->markCompleted($user);

        if (! $completed) {
            OnboardingStatusUpdated::broadcastForAccount($account);
        }

        return true;
    }

    /**
     * Sidebar residual banner payload, or false when the banner should not show.
     *
     * Pure read — safe for Inertia shared props and prefetch. Cross-workspace
     * completion is stamped by syncProgress / observers, not here.
     *
     * @return array{completed: int, total: int}|false
     */
    public function residual(User $user): array|false
    {
        $account = $user->account;

        // Cheap gates first: this runs on every full Inertia load, so dismissed /
        // completed accounts, members, and self-hosted must never pay for the
        // step EXISTS queries in handle() — or even for a subscription lookup.
        if (config('trypost.self_hosted')
            || $account === null
            || $account->hasFinishedOnboarding()
            || ! $user->isAccountOwner()
            || ! $account->hasAppAccess()
        ) {
            return false;
        }

        $status = $this->handle($user);

        if (! $status['show_residual']) {
            return false;
        }

        return [
            'completed' => collect(self::STEPS)
                ->filter(fn (string $statusKey, string $step): bool => $status[$statusKey]
                    || in_array($step, $status['skipped_steps'], true))
                ->count(),
            'total' => self::TOTAL_STEPS,
        ];
    }

    private function accountHasMcpConnection(?Account $account): bool
    {
        if ($account === null) {
            return false;
        }

        $tokens = AccessToken::query()
            ->whereIn(
                'user_id',
                User::query()->select('id')->where('account_id', $account->id),
            )
            ->activeMcpOAuth()
            ->with('workspace')
            ->get();
        $users = User::query()
            ->with('currentWorkspace')
            ->whereIn('id', $tokens->pluck('user_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        return $tokens->contains(
            fn (AccessToken $token): bool => $token->isUsableMcpGrant(
                $users->get($token->user_id),
            ),
        );
    }

    private function accountHasSocialConnection(?Account $account): bool
    {
        if ($account === null) {
            return false;
        }

        return Workspace::query()
            ->where('account_id', $account->id)
            ->whereHas(
                'socialAccounts',
                fn (Builder $query): Builder => $query->where('status', Status::Connected),
            )
            ->exists();
    }

    private function accountHasPost(?Account $account): bool
    {
        if ($account === null) {
            return false;
        }

        return Workspace::query()
            ->where('account_id', $account->id)
            ->whereHas('posts')
            ->exists();
    }

    private function captureCompletedStep(User $user, Account $account, string $step, bool $completed): void
    {
        if (! $completed) {
            return;
        }

        // One capture per account/step — replaces the former PostHog job dedupe.
        if (! Cache::add("onboarding:step:{$account->id}:{$step}", true)) {
            return;
        }

        $this->postHog->capture(
            $user->id,
            OnboardingEvent::StepCompleted->value,
            ['step' => $step],
            $account,
        );
    }
}
