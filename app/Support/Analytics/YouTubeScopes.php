<?php

declare(strict_types=1);

namespace App\Support\Analytics;

/**
 * The Google scope alternatives for each YouTube operation.
 *
 * @see https://developers.google.com/youtube/v3/guides/auth/server-side-web-apps#identify-access-scopes
 * @see https://developers.google.com/youtube/analytics/reference/reports/query
 */
final class YouTubeScopes
{
    /**
     * @see https://developers.google.com/youtube/data/reference/rest/v3/videos/insert
     * @see https://developers.google.com/youtube/data/reference/rest/v3/thumbnails/set
     *
     * @var list<string>
     */
    public const array UPLOAD = [
        'https://www.googleapis.com/auth/youtube.upload',
        'https://www.googleapis.com/auth/youtube',
        'https://www.googleapis.com/auth/youtube.force-ssl',
        'https://www.googleapis.com/auth/youtubepartner',
    ];

    /**
     * Any one of these lets the Data API read the channel, its uploads and its videos.
     *
     * @var list<string>
     */
    public const array READ = [
        'https://www.googleapis.com/auth/youtube.readonly',
        'https://www.googleapis.com/auth/youtube',
        'https://www.googleapis.com/auth/youtube.force-ssl',
    ];

    /**
     * Any one of these lets the Analytics API run a channel report.
     *
     * @var list<string>
     */
    public const array ANALYTICS = [
        'https://www.googleapis.com/auth/yt-analytics.readonly',
        'https://www.googleapis.com/auth/yt-analytics-monetary.readonly',
    ];
}
