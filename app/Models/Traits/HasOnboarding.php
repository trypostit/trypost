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
     * Whether the account owner has a bound MCP OAuth grant that unlocks
     * the checklist. Teammate grants do not count — activation is owner-owned.
     * Early-exits on the first matching grant so accounts with many MCP
     * tokens do not pay for a full collection + Gate pass every time.
     */
    public function hasOnboardingMcpConnection(): bool
    {
        if ($this->owner_id === null) {
            return false;
        }

        $tokens = AccessToken::query()
            ->activeMcpOAuth()
            ->where('user_id', $this->owner_id)
            ->whereNotNull('workspace_id')
            ->whereHas(
                'workspace',
                fn (Builder $workspace): Builder => $workspace->where('account_id', $this->id),
            )
            ->with(['user', 'workspace', 'client'])
            ->lazyById();

        foreach ($tokens as $token) {
            if ($token->unlocksOnboardingChecklist()) {
                return true;
            }
        }

        return false;
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
