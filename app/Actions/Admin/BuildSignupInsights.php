<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Dto\Analytics\DateRange;
use App\Enums\Post\PublishStatus;
use App\Enums\User\Goal;
use App\Enums\User\PreviousSoftware;
use App\Enums\User\SignInMethod;
use App\Models\Account;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Admin\StripePriceAmounts;
use App\Support\Percent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class BuildSignupInsights
{
    /**
     * Who signed up on the range's days of the viewer's zone and how far they
     * went: account owners only (an invited member skips Welcome and never
     * pays), followed as one cohort through the Welcome answers, a
     * subscription, a connected channel and a post sent. Each answer is split
     * out with how many of its owners went on to subscribe, and the period is
     * set against the one before it. `signup_methods` holds the ways each
     * owner can sign in now (Google, GitHub, a password), not how they
     * signed up: linking Google later adds it, so an owner may count under
     * several.
     *
     * @return array<string, mixed>
     */
    public static function execute(DateRange $range): array
    {
        $previous = $range->previous();

        $owners = self::owners($range->startsAt(), $range->endsAt());
        $subscribed = self::subscribedAccounts($owners);
        $previousOwners = self::owners($previous->startsAt(), $previous->endsAt());
        $previousSubscribed = self::subscribedAccounts($previousOwners);
        $answered = self::answered($owners);
        $signupsPerDay = $owners->countBy(fn (User $user): string => $range->localDate($user->created_at));
        $subscribedPerDay = $owners
            ->filter(fn (User $user): bool => $subscribed->has($user->account_id))
            ->countBy(fn (User $user): string => $range->localDate($user->created_at));

        return [
            'totals' => self::totals($owners, $subscribed),
            'previous' => self::totals($previousOwners, $previousSubscribed),
            'days' => $range->calendarDays(),
            'signups_by_day' => $range->perDay($signupsPerDay),
            'subscribed_by_day' => $range->perDay($subscribedPerDay),
            'funnel' => self::funnel($owners, $subscribed, $answered),
            'personas' => self::breakdown($owners, $subscribed, fn (User $user): array => [$user->persona?->value], fn (string $value): string => (string) __("welcome.personas.{$value}", [], 'en')),
            'software' => self::breakdown($owners, $subscribed, fn (User $user): array => [$user->previous_software?->value], fn (string $value): string => PreviousSoftware::from($value)->label()),
            'goals' => self::breakdown($owners, $subscribed, fn (User $user): array => collect($user->goals)->filter(fn (mixed $goal): bool => is_string($goal) && filled(Goal::tryFrom($goal)))->values()->all(), fn (string $value): string => (string) __("welcome.goals.{$value}", [], 'en')),
            'referral_sources' => self::breakdown($owners, $subscribed, fn (User $user): array => [$user->referral_source?->value], fn (string $value): string => (string) __("referral_source.options.{$value}", [], 'en')),
            'signup_methods' => self::breakdown($owners, $subscribed, fn (User $user): array => collect(SignInMethod::of($user))->map->value->all(), fn (string $value): string => SignInMethod::from($value)->label()),
            'answered' => $answered,
        ];
    }

    /**
     * How many of the owners signed up, subscribed, what that brings each
     * month and how long it took them.
     *
     * @param  Collection<int, User>  $owners
     * @param  Collection<string, Subscription>  $subscribed
     * @return array{signups: int, subscribed: int, new_mrr_cents: int, minutes_to_subscribe: ?float, subscribed_rate: float}
     */
    private static function totals(Collection $owners, Collection $subscribed): array
    {
        return [
            'signups' => $owners->count(),
            'subscribed' => $subscribed->count(),
            'new_mrr_cents' => StripePriceAmounts::monthlyCentsOf($subscribed),
            'minutes_to_subscribe' => self::medianMinutesToSubscribe($owners, $subscribed),
            'subscribed_rate' => Percent::of($subscribed->count(), $owners->count()),
        ];
    }

    /**
     * How many of the owners answered each Welcome question.
     *
     * @param  Collection<int, User>  $owners
     * @return array{persona: int, software: int, goals: int, referral_source: int}
     */
    private static function answered(Collection $owners): array
    {
        return [
            'persona' => $owners->whereNotNull('persona')->count(),
            'software' => $owners->whereNotNull('previous_software')->count(),
            'goals' => $owners->filter(fn (User $user): bool => filled($user->goals))->count(),
            'referral_source' => $owners->whereNotNull('referral_source')->count(),
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
            ->get(['users.id', 'users.account_id', 'users.created_at', 'users.persona', 'users.goals', 'users.previous_software', 'users.referral_source', 'users.google_id', 'users.github_id', 'users.password']);
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
            ->billed()
            ->whereIn('account_id', $owners->pluck('account_id'))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'account_id', 'stripe_price', 'quantity', 'created_at'])
            ->unique('account_id')
            ->keyBy('account_id');
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
     * @param  array{persona: int, software: int, goals: int, referral_source: int}  $answered
     * @return list<array{key: string, label: string, count: int}>
     */
    private static function funnel(Collection $owners, Collection $subscribed, array $answered): array
    {
        $workspaces = Workspace::query()->whereIn('account_id', $owners->pluck('account_id'));

        return [
            ['key' => 'signed_up', 'label' => 'Signed up', 'count' => $owners->count()],
            ['key' => 'persona', 'label' => 'Said who they are', 'count' => data_get($answered, 'persona')],
            ['key' => 'goals', 'label' => 'Picked their goals', 'count' => data_get($answered, 'goals')],
            ['key' => 'software', 'label' => 'Named their tool', 'count' => data_get($answered, 'software')],
            ['key' => 'subscribed', 'label' => 'Subscribed', 'count' => $subscribed->count()],
            ['key' => 'connected', 'label' => 'Connected a channel', 'count' => (clone $workspaces)
                ->whereHas('socialAccounts')
                ->distinct()
                ->count('account_id')],
            ['key' => 'published', 'label' => 'Sent a post', 'count' => (clone $workspaces)
                ->whereHas('posts', fn (Builder $posts): Builder => $posts->createdInTryPost()->whereIn('publish_status', PublishStatus::wentOutCases()))
                ->distinct()
                ->count('account_id')],
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
            ->map(function (Collection $group, string $value) use ($label): array {
                $subscribedCount = $group->where('subscribed', true)->count();

                return [
                    'value' => $value,
                    'label' => $label($value),
                    'count' => $group->count(),
                    'subscribed' => $subscribedCount,
                    'subscribed_rate' => Percent::of($subscribedCount, $group->count()),
                ];
            })
            ->sortByDesc('count')
            ->values()
            ->all();
    }
}
