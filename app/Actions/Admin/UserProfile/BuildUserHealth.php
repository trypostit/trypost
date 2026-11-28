<?php

declare(strict_types=1);

namespace App\Actions\Admin\UserProfile;

use App\Actions\Account\ResolveLifecycleStage;
use App\Actions\Admin\Users\BuildChurnRisk;
use App\Actions\Admin\Users\CountAccountActivity;
use App\Dto\Analytics\DateRange;
use App\Enums\Account\LifecycleStage;
use App\Models\Account;
use App\Models\User;
use App\Support\Percent;
use Illuminate\Support\Arr;

class BuildUserHealth
{
    /** The calendar days the posting figures cover, set against as many days before. */
    public const int WINDOW_DAYS = 30;

    /**
     * How the user's account is doing now, read the way People → Insights
     * reads every account: its lifecycle stage worked out now, its last
     * post, how much it posted over the last 30 calendar days of the
     * viewing admin's zone against the 30 before, its channels and, for a
     * paying account, its churn risk and what it pays.
     *
     * @return array<string, mixed>|null
     */
    public static function execute(User $user, string $timezone): ?array
    {
        $account = Account::query()
            ->whereKey($user->account_id)
            ->with('subscriptions')
            ->withLifecycleActivity()
            ->first();

        if (blank($account)) {
            return null;
        }

        $today = now($timezone);
        $activity = CountAccountActivity::execute(
            DateRange::fromDates($today->subDays(self::WINDOW_DAYS - 1)->toDateString(), $today->toDateString(), $timezone),
            $account->id,
        );
        $sent = $activity->sentBy($account->id);
        $previousSent = $activity->previousSentBy($account->id);
        $failed = $activity->failedFor($account->id);
        $channels = $activity->channelsOf($account->id);
        $stage = ResolveLifecycleStage::execute($account, $account->last_published_at, (bool) $account->has_social_account);

        return [
            'stage' => $stage->value,
            'last_posted_at' => $account->last_published_at?->toIso8601String(),
            'posts' => [
                'sent' => $sent,
                'previous' => $previousSent,
                'automated' => $activity->automatedBy($account->id),
                'failed' => $failed,
                'failure_rate' => Percent::of($failed, $failed + $sent),
            ],
            'channels' => [
                'total' => (int) data_get($channels, 'total', 0),
                'broken' => (int) data_get($channels, 'broken', 0),
            ],
            'risk' => self::risk($account, $stage, $channels, $sent, $previousSent, $failed),
        ];
    }

    /**
     * The churn risk of a paying account, as the Churn risk tab weighs it;
     * null for an account that pays nothing now.
     *
     * @param  array{total: int, broken: int}|null  $channels
     * @return array{signals: list<string>, level: ?string, mrr_cents: int}|null
     */
    private static function risk(Account $account, LifecycleStage $stage, ?array $channels, int $sent, int $previousSent, int $failed): ?array
    {
        if (blank($account->payingSubscription())) {
            return null;
        }

        return Arr::except(BuildChurnRisk::assess($account, $stage, $channels, $sent, $previousSent, $failed), 'score');
    }
}
