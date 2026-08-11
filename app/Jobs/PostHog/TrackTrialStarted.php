<?php

declare(strict_types=1);

namespace App\Jobs\PostHog;

use App\Enums\PostHog\TrialEvent;
use App\Models\Account;
use App\Support\StripeSubscriptionConversion;
use Illuminate\Support\Carbon;

class TrackTrialStarted extends AbstractTrackStripeSubscriptionEvent
{
    protected function event(): string
    {
        return TrialEvent::Started->value;
    }

    /**
     * @return array<string, mixed>
     */
    protected function properties(Account $account): array
    {
        $properties = StripeSubscriptionConversion::baseProperties($account, $this->payload);

        $trialEnd = data_get($this->payload, 'data.object.trial_end');

        if (is_int($trialEnd)) {
            $properties['trial_ends_at'] = Carbon::createFromTimestamp($trialEnd)->toIso8601String();
        }

        return $properties;
    }
}
