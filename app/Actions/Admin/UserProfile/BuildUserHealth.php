<?php

declare(strict_types=1);

namespace App\Actions\Admin\UserProfile;

use App\Actions\Account\ResolveLifecycleStage;
use App\Actions\Admin\Users\BuildChurnRisk;
use App\Actions\Admin\Users\CountAccountActivity;
use App\Enums\Account\LifecycleStage;
use App\Enums\Post\CreatedVia;
use App\Enums\Post\PublishStatus;
use App\Models\Account;
use App\Models\User;
use App\Support\Admin\PublishingCounts;
use App\Support\Percent;

class BuildUserHealth
{
    /** The days the posting figures cover, set against as many days before. */
    public const int WINDOW_DAYS = 30;

    /**
     * How the user's account is doing now, read the way People → Insights
     * reads every account: its lifecycle stage worked out now, its last
     * post, how much it posted lately against the period before, its
     * channels and, for a paying account, its churn risk and what it pays.
     *
     * @return array<string, mixed>|null
     */
    public static function execute(User $user): ?array
    {
        $account = Account::query()
            ->whereKey($user->account_id)
            ->with('subscriptions')
            ->withLifecycleActivity()
            ->first();

        if ($account === null) {
            return null;
        }

        $last = now();
        $first = $last->subDays(self::WINDOW_DAYS);

        $sent = CountAccountActivity::postsOf($account->id, $first, $last, PublishStatus::wentOutCases());
        $previousSent = CountAccountActivity::postsOf($account->id, $first->subDays(self::WINDOW_DAYS), $first->subSecond(), PublishStatus::wentOutCases());
        $failed = CountAccountActivity::postsOf($account->id, $first, $last, PublishingCounts::failedStatuses());
        $automated = CountAccountActivity::postsOf($account->id, $first, $last, PublishStatus::wentOutCases(), [CreatedVia::Api, CreatedVia::Mcp]);
        $channels = CountAccountActivity::channelsOf($account->id);
        $stage = ResolveLifecycleStage::execute($account, $account->last_published_at, (bool) $account->has_social_account);

        return [
            'stage' => $stage->value,
            'last_posted_at' => $account->last_published_at?->toIso8601String(),
            'posts' => [
                'sent' => $sent,
                'previous' => $previousSent,
                'automated' => $automated,
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
     * @return array{signals: list<string>, score: int, level: ?string, mrr_cents: int}|null
     */
    private static function risk(Account $account, LifecycleStage $stage, ?array $channels, int $sent, int $previousSent, int $failed): ?array
    {
        if ($account->payingSubscription() === null) {
            return null;
        }

        return BuildChurnRisk::assess($account, $stage, $channels, $sent, $previousSent, $failed);
    }
}
