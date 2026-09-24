<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Metrics;

use App\Contracts\Analytics\PublicationMetricsCollector;
use App\Dto\Analytics\PublicationMetricObservation;
use App\Enums\Analytics\MetricKey;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\AnalyticsPublication;
use Carbon\CarbonImmutable;

class TikTokPublicationMetricsCollector extends AbstractPublicationMetricsCollector implements PublicationMetricsCollector
{
    public function collect(AnalyticsPublication $publication, CarbonImmutable $date): PublicationMetricObservation
    {
        $account = $this->account($publication);
        $videoId = $this->publicVideoId($publication);

        $response = $this->post($account,
            rtrim((string) config('trypost.platforms.tiktok.api'), '/').'/video/query/?fields=id,view_count,like_count,comment_count,share_count',
            ['filters' => ['video_ids' => [$videoId]]],
        );
        $errorCode = $response->json('error.code');

        if (is_string($errorCode) && ! in_array($errorCode, ['', 'ok'], true)) {
            throw new AnalyticsCollectionException(
                $errorCode === 'rate_limit_exceeded' ? 'rate_limited' : 'permission',
                'TikTok video metrics query rejected the request.',
            );
        }

        $video = collect((array) $response->json('data.videos', []))
            ->first(fn (mixed $item): bool => is_array($item) && (string) ($item['id'] ?? '') === $videoId);

        if (! is_array($video)) {
            throw AnalyticsCollectionException::malformed('TikTok video query did not return the requested video.');
        }

        return $this->observation($date, $this->withEngagements($this->present([
            $this->count(MetricKey::Views, $video, 'view_count'),
            $this->count(MetricKey::Reactions, $video, 'like_count'),
            $this->count(MetricKey::Comments, $video, 'comment_count'),
            $this->count(MetricKey::Shares, $video, 'share_count'),
        ])));
    }

    public function publicVideoId(AnalyticsPublication $publication): string
    {
        $videoId = $publication->provider_post_id;

        if (! ctype_digit($videoId)) {
            $status = $this->post($this->account($publication),
                rtrim((string) config('trypost.platforms.tiktok.api'), '/').'/post/publish/status/fetch/',
                ['publish_id' => $videoId],
            );
            $videoId = (string) $status->json('data.publicaly_available_post_id.0', '');

            if (! ctype_digit($videoId)) {
                throw new AnalyticsCollectionException('delayed', 'TikTok publication has no public video id yet.');
            }
        }

        return $videoId;
    }
}
