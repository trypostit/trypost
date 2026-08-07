<?php

declare(strict_types=1);

namespace App\Models\Traits;

use App\Models\AccessToken;
use App\Models\Account;
use Illuminate\Database\Eloquent\Builder;

trait HasOnboarding
{
    public function hasFinishedOnboarding(): bool
    {
        return $this->isOnboardingCompleted() || $this->isOnboardingDismissed();
    }

    public function isOnboardingCompleted(): bool
    {
        return $this->onboarding_completed_at !== null;
    }

    public function isOnboardingDismissed(): bool
    {
        return $this->onboarding_dismissed_at !== null;
    }

    /**
     * Whether activation progress should still be tracked for this account.
     * Prefer `$account?->isOnboardingOpen()` at call sites so null accounts bail out cleanly.
     */
    public function isOnboardingOpen(): bool
    {
        return ! $this->hasFinishedOnboarding();
    }

    /**
     * @return list<string>
     */
    public function skippedOnboardingSteps(): array
    {
        return $this->onboarding_skipped_steps ?? [];
    }

    public function hasSkippedOnboardingStep(string $step): bool
    {
        return in_array($step, $this->skippedOnboardingSteps(), true);
    }

    /**
     * Whether any bound MCP OAuth grant unlocks the account checklist.
     */
    public function hasOnboardingMcpConnection(): bool
    {
        return AccessToken::query()
            ->activeMcpOAuth()
            ->whereNotNull('workspace_id')
            ->whereHas(
                'workspace',
                fn (Builder $workspace): Builder => $workspace->where('account_id', $this->id),
            )
            ->with(['user', 'workspace', 'client'])
            ->get()
            ->contains->unlocksOnboardingChecklist();
    }

    /**
     * @param  Builder<Account>  $query
     * @return Builder<Account>
     */
    public function scopeOnboardingOpen(Builder $query): Builder
    {
        return $query
            ->whereNull('onboarding_completed_at')
            ->whereNull('onboarding_dismissed_at');
    }
}
