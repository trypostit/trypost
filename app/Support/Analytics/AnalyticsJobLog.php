<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use App\Actions\Analytics\ResolveAnalyticsAccountKey;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Log;

class AnalyticsJobLog
{
    public function __construct(private ResolveAnalyticsAccountKey $accountKeys) {}

    public function record(
        SocialAccount $account,
        string $collector,
        string $dateOrCursor,
        int $attempt,
        string $category,
        ?string $retryAt = null,
    ): void {
        $context = [
            'workspace_id' => $account->workspace_id,
            'social_account_key' => $this->accountKeys->for($account),
            'platform' => $account->platform->value,
            'collector' => $collector,
            'date_or_cursor' => $dateOrCursor,
            'attempt' => $attempt,
            'category' => $category,
        ];

        if ($retryAt !== null) {
            $context['retry_at'] = $retryAt;
        }

        Log::info('analytics.collection', $context);
    }
}
