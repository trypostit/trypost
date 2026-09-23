<?php

declare(strict_types=1);

namespace App\Jobs\Analytics;

use App\Enums\Analytics\PublicationContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\AnalyticsPublication;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ScheduleInstagramStoryMetrics implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $publicationId)
    {
        $this->onQueue('analytics');
    }

    public function handle(): void
    {
        $publication = AnalyticsPublication::query()->available()->find($this->publicationId);

        if (! $publication
            || $publication->content_type !== PublicationContentType::Story
            || ! in_array($publication->platform, [Platform::Instagram, Platform::InstagramFacebook], true)) {
            return;
        }

        $now = CarbonImmutable::now('UTC');
        $publishedAt = $publication->provider_published_at;
        $expiresAt = $publishedAt->addDay();

        if ($now->greaterThanOrEqualTo($expiresAt)) {
            return;
        }

        $checkpoints = [
            $now,
            $publishedAt->addHours(6),
            $expiresAt->subMinutes(30),
        ];

        foreach ($checkpoints as $checkpoint) {
            if ($checkpoint->lessThan($now) || $checkpoint->greaterThanOrEqualTo($expiresAt)) {
                continue;
            }

            CollectPublicationMetrics::dispatch(
                [$publication->id],
                $checkpoint->toDateString(),
                false,
                true,
            )->delay($checkpoint)->afterCommit();
        }
    }
}
