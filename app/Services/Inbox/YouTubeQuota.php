<?php

declare(strict_types=1);

namespace App\Services\Inbox;

use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\YouTubePublishException;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * The YouTube Data API units the inbox may spend per day, app-wide. Google
 * resets the project quota at midnight Pacific Time. Each connected YouTube
 * channel gets an equal share of the poll units, so the channels that poll
 * first cannot leave nothing for the rest; a channel past its share still
 * reads one page per poll.
 *
 * @see https://developers.google.com/youtube/v3/determine_quota_cost
 */
class YouTubeQuota
{
    private const string TIMEZONE = 'America/Los_Angeles';

    public function spendOnPoll(int $units, ?SocialAccount $account = null): void
    {
        $this->spend($units, $this->pollUnits());

        if ($account) {
            Cache::add($this->channelKey($account), 0, $this->resetsAt());
            Cache::increment($this->channelKey($account), $units);
        }
    }

    public function withinShare(SocialAccount $account): bool
    {
        return (int) Cache::get($this->channelKey($account), 0) < $this->channelShare();
    }

    /**
     * The poll units split among the connected channels that may read their
     * comments, counted once an hour instead of on every poll.
     */
    public function channelShare(): int
    {
        $channels = Cache::remember("{$this->key()}:channels", now()->addHour(), fn (): int => SocialAccount::query()
            ->connected()
            ->where('platform', Platform::YouTube)
            ->get()
            ->filter(fn (SocialAccount $account): bool => $account->hasInboxAccess())
            ->count());

        return max(1, intdiv($this->pollUnits(), max(1, (int) $channels)));
    }

    public function spendOnReply(int $units): void
    {
        $this->spend($units, $this->dailyUnits());
    }

    public function exhaust(): void
    {
        Cache::put($this->key(), $this->dailyUnits(), $this->resetsAt());
    }

    public function used(): int
    {
        return (int) Cache::get($this->key(), 0);
    }

    public function resetsAt(): CarbonImmutable
    {
        return now()->setTimezone(self::TIMEZONE)->addDay()->startOfDay()->utc()->toImmutable();
    }

    private function spend(int $units, int $ceiling): void
    {
        Cache::add($this->key(), 0, $this->resetsAt());

        if ((int) Cache::increment($this->key(), $units) > $ceiling) {
            Cache::decrement($this->key(), $units);

            throw $this->exhausted();
        }
    }

    private function pollUnits(): int
    {
        return $this->dailyUnits() - (int) config('trypost.inbox.youtube.reply_reserve_units');
    }

    private function channelKey(SocialAccount $account): string
    {
        return "{$this->key()}:channel:{$account->id}";
    }

    private function dailyUnits(): int
    {
        return (int) config('trypost.inbox.youtube.daily_units');
    }

    private function key(): string
    {
        $day = now()->setTimezone(self::TIMEZONE)->toDateString();

        return "inbox:youtube-quota:{$day}";
    }

    private function exhausted(): YouTubePublishException
    {
        $exception = new YouTubePublishException(
            userMessage: 'YouTube API quota exceeded. Try again later.',
            category: ErrorCategory::RateLimit,
            platformErrorCode: 'quotaExceeded',
        );
        $exception->retryAt = $this->resetsAt();

        return $exception;
    }
}
