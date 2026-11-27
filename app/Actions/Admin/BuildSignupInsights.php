<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Dto\Analytics\DateRange;
use App\Enums\Post\PublishStatus;
use App\Enums\User\Goal;
use App\Models\Account;
use App\Models\Post;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Admin\KeyLabel;
use App\Support\Admin\StripePriceAmounts;
use App\Support\Admin\SubscriptionPlans;
use App\Support\Percent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class BuildSignupInsights
{
    /** Brand names the Welcome software step shows as logos, spelled as the brands do. */
    private const array SOFTWARE_NAMES = [
        'sprout_social' => 'Sprout Social',
        'socialbee' => 'SocialBee',
        'none' => 'None yet',
    ];

    private const array SIGNUP_METHODS = ['google' => 'Google', 'github' => 'GitHub', 'email' => 'Email and password'];

    /**
     * Who signed up on the range's days of the viewer's zone and how far they
     * went: account owners only (an invited member skips Welcome and never
     * pays), followed as one cohort through the Welcome answers, a
     * subscription, a connected channel and a post sent. Each answer is split
     * out with how many of its owners went on to subscribe, and the period is
     * set against the one before it.
     *
     * @return array<string, mixed>
     */
    public static function execute(DateRange $range): array
    {
        $days = collect($range->calendarDays());
        $previous = $range->previous();

        $owners = self::owners($range->startsAt(), $range->endsAt());
        $subscribed = self::subscribedAccounts($owners);
        $previousOwners = self::owners($previous->startsAt(), $previous->endsAt());
        $previousSubscribed = self::subscribedAccounts($previousOwners);
        $signupsPerDay = $owners->countBy(fn (User $user): string => $range->localDate($user->created_at));
        $subscribedPerDay = $owners
            ->filter(fn (User $user): bool => $subscribed->has($user->account_id))
            ->countBy(fn (User $user): string => $range->localDate($user->created_at));

        return [
            'totals' => [
                'signups' => $owners->count(),
                'subscribed' => $subscribed->count(),
                'new_mrr_cents' => self::newMrrCents($subscribed),
                'minutes_to_subscribe' => self::medianMinutesToSubscribe($owners, $subscribed),
                'subscribed_rate' => Percent::of($subscribed->count(), $owners->count()),
            ],
            'previous' => [
                'signups' => $previousOwners->count(),
                'subscribed' => $previousSubscribed->count(),
                'new_mrr_cents' => self::newMrrCents($previousSubscribed),
                'minutes_to_subscribe' => self::medianMinutesToSubscribe($previousOwners, $previousSubscribed),
                'subscribed_rate' => Percent::of($previousSubscribed->count(), $previousOwners->count()),
            ],
            'days' => $days->all(),
            'signups_by_day' => $days->map(fn (string $day): int => (int) $signupsPerDay->get($day, 0))->all(),
            'subscribed_by_day' => $days->map(fn (string $day): int => (int) $subscribedPerDay->get($day, 0))->all(),
            'funnel' => self::funnel($owners, $subscribed),
            'personas' => self::breakdown($owners, $subscribed, fn (User $user): array => [$user->persona?->value], fn (string $value): string => (string) __("welcome.personas.{$value}", [], 'en')),
            'software' => self::breakdown($owners, $subscribed, fn (User $user): array => [$user->previous_software?->value], fn (string $value): string => self::softwareName($value)),
            'goals' => self::breakdown($owners, $subscribed, fn (User $user): array => collect($user->goals ?? [])->filter(fn (mixed $goal): bool => is_string($goal) && Goal::tryFrom($goal) !== null)->values()->all(), fn (string $value): string => (string) __("welcome.goals.{$value}", [], 'en')),
            'referral_sources' => self::breakdown($owners, $subscribed, fn (User $user): array => [$user->referral_source?->value], fn (string $value): string => (string) __("referral_source.options.{$value}", [], 'en')),
            'signup_methods' => self::breakdown($owners, $subscribed, fn (User $user): array => [self::signupMethod($user)], fn (string $value): string => (string) data_get(self::SIGNUP_METHODS, $value, $value)),
            'answered' => [
                'persona' => $owners->whereNotNull('persona')->count(),
                'software' => $owners->whereNotNull('previous_software')->count(),
                'goals' => $owners->filter(fn (User $user): bool => filled($user->goals))->count(),
                'referral_source' => $owners->whereNotNull('referral_source')->count(),
            ],
        ];
    }

    /**
     * The users who signed up then and own their account.
     *
     * @return Collection<int, User>
     */
    private static function owners(CarbonImmutable $from, CarbonImmutable $until): Collection
    {
        return User::query()
            ->whereBetween('users.created_at', [$from, $until])
            ->whereIn('users.id', Account::query()->select('owner_id'))
            ->get(['users.id', 'users.account_id', 'users.created_at', 'users.persona', 'users.goals', 'users.previous_software', 'users.referral_source', 'users.google_id', 'users.github_id']);
    }

    /**
     * Each owner's account's first subscription (an incomplete checkout never
     * counts), keyed by account id: the accounts that subscribed.
     *
     * @param  Collection<int, User>  $owners
     * @return Collection<string, Subscription>
     */
    private static function subscribedAccounts(Collection $owners): Collection
    {
        return Subscription::query()
            ->whereIn('account_id', $owners->pluck('account_id'))
            ->where('type', Account::SUBSCRIPTION_NAME)
            ->whereNotIn('stripe_status', SubscriptionPlans::NEVER_BILLED_STATUSES)
            ->orderBy('created_at')
            ->get(['id', 'account_id', 'stripe_price', 'quantity', 'created_at'])
            ->unique('account_id')
            ->keyBy('account_id');
    }

    /**
     * What the first subscriptions bring each month at list price.
     *
     * @param  Collection<string, Subscription>  $subscriptions
     */
    private static function newMrrCents(Collection $subscriptions): int
    {
        return (int) round($subscriptions->sum(fn (Subscription $subscription): float => StripePriceAmounts::forSubscription($subscription) ?? 0.0));
    }

    /**
     * The median minutes from signing up to the first subscription, null when nobody subscribed.
     *
     * @param  Collection<int, User>  $owners
     * @param  Collection<string, Subscription>  $subscriptions
     */
    private static function medianMinutesToSubscribe(Collection $owners, Collection $subscriptions): ?float
    {
        $minutes = $owners
            ->filter(fn (User $user): bool => $subscriptions->has($user->account_id))
            ->map(fn (User $user): float => max(0.0, $user->created_at->diffInMinutes($subscriptions->get($user->account_id)->created_at)));

        if ($minutes->isEmpty()) {
            return null;
        }

        return round((float) $minutes->median(), 1);
    }

    /**
     * Each step the cohort reached, in the order Welcome and the app ask them.
     *
     * @param  Collection<int, User>  $owners
     * @param  Collection<string, Subscription>  $subscribed
     * @return list<array{key: string, label: string, count: int}>
     */
    private static function funnel(Collection $owners, Collection $subscribed): array
    {
        $accountIds = $owners->pluck('account_id');
        $workspaces = Workspace::query()->whereIn('account_id', $accountIds)->get(['id', 'account_id']);
        $accountOf = $workspaces->pluck('account_id', 'id');
        $connected = Workspace::query()
            ->whereIn('id', $workspaces->pluck('id'))
            ->whereHas('socialAccounts')
            ->pluck('account_id')
            ->unique();
        $published = Post::query()
            ->createdInTryPost()
            ->whereIn('workspace_id', $workspaces->pluck('id'))
            ->whereIn('publish_status', PublishStatus::wentOutCases())
            ->distinct()
            ->pluck('workspace_id')
            ->map(fn (string $workspaceId): ?string => $accountOf->get($workspaceId))
            ->filter()
            ->unique();

        return [
            ['key' => 'signed_up', 'label' => 'Signed up', 'count' => $owners->count()],
            ['key' => 'persona', 'label' => 'Said who they are', 'count' => $owners->whereNotNull('persona')->count()],
            ['key' => 'goals', 'label' => 'Picked their goals', 'count' => $owners->filter(fn (User $user): bool => filled($user->goals))->count()],
            ['key' => 'software', 'label' => 'Named their tool', 'count' => $owners->whereNotNull('previous_software')->count()],
            ['key' => 'subscribed', 'label' => 'Subscribed', 'count' => $subscribed->count()],
            ['key' => 'connected', 'label' => 'Connected a channel', 'count' => $connected->count()],
            ['key' => 'published', 'label' => 'Sent a post', 'count' => $published->count()],
        ];
    }

    /**
     * How many owners gave each answer, and how many of them subscribed. A
     * user may give several answers (goals), so the counts can add up to more
     * than the owners.
     *
     * @param  Collection<int, User>  $owners
     * @param  Collection<string, Subscription>  $subscribed
     * @param  callable(User): list<string|null>  $answers
     * @param  callable(string): string  $label
     * @return list<array{value: string, label: string, count: int, subscribed: int, subscribed_rate: float}>
     */
    private static function breakdown(Collection $owners, Collection $subscribed, callable $answers, callable $label): array
    {
        return $owners
            ->flatMap(fn (User $user): array => collect($answers($user))
                ->filter()
                ->map(fn (string $value): array => ['value' => $value, 'subscribed' => $subscribed->has($user->account_id)])
                ->all())
            ->groupBy('value')
            ->map(fn (Collection $group, string $value): array => [
                'value' => $value,
                'label' => $label($value),
                'count' => $group->count(),
                'subscribed' => $group->where('subscribed', true)->count(),
                'subscribed_rate' => Percent::of($group->where('subscribed', true)->count(), $group->count()),
            ])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    private static function signupMethod(User $user): string
    {
        if (filled($user->google_id)) {
            return 'google';
        }

        if (filled($user->github_id)) {
            return 'github';
        }

        return 'email';
    }

    private static function softwareName(string $value): string
    {
        $known = data_get(self::SOFTWARE_NAMES, $value);

        if (filled($known)) {
            return $known;
        }

        return KeyLabel::of($value);
    }
}
