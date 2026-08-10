<?php

declare(strict_types=1);

namespace App\Models\Traits;

use App\Models\Account;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait HasAccount
{
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Account for authenticated product flows (onboarding, billing, settings).
     * In-app users always have one; null only during account teardown.
     */
    public function accountOrFail(): Account
    {
        return $this->account ?? $this->account()->firstOrFail();
    }

    public function isAccountOwner(): bool
    {
        if (! $this->account_id) {
            return false;
        }

        if ($this->relationLoaded('account')) {
            return $this->id === $this->account?->owner_id;
        }

        return Account::query()
            ->whereKey($this->account_id)
            ->where('owner_id', $this->id)
            ->exists();
    }

    public function belongsToAccount(string $accountId): bool
    {
        return $this->account_id === $accountId;
    }
}
