<?php

declare(strict_types=1);

namespace App\Enums\Analytics;

enum MetricKey: string
{
    case Reactions = 'reactions';
    case Comments = 'comments';
    case Replies = 'replies';
    case Shares = 'shares';
    case Reposts = 'reposts';
    case Quotes = 'quotes';
    case Saves = 'saves';
    case Bookmarks = 'bookmarks';
    case Views = 'views';
    case VideoViews = 'video_views';
    case Impressions = 'impressions';
    case Reach = 'reach';
    case Engagements = 'engagements';
    case TotalInteractions = 'total_interactions';
    case EngagementRate = 'engagement_rate';
    case Clicks = 'clicks';
    case LinkClicks = 'link_clicks';
    case PinClicks = 'pin_clicks';
    case PinClickRate = 'pin_click_rate';
    case OutboundClicks = 'outbound_clicks';
    case OutboundClickRate = 'outbound_click_rate';
    case SaveRate = 'save_rate';
    case Follows = 'follows';
    case ProfileVisits = 'profile_visits';
    case ProfileActivity = 'profile_activity';
    case WatchTimeMilliseconds = 'watch_time_milliseconds';
    case AverageWatchTimeMilliseconds = 'average_watch_time_milliseconds';
    case AveragePercentageViewed = 'average_percentage_viewed';
    case SkipRate = 'skip_rate';
    case EngagedViews = 'engaged_views';
    case VideoViews10Seconds = 'video_views_10_seconds';
    case VideoViews95Percent = 'video_views_95_percent';
    case VideoQuartile25 = 'video_quartile_25';
    case VideoQuartile50 = 'video_quartile_50';
    case VideoQuartile75 = 'video_quartile_75';
    case VideoQuartile100 = 'video_quartile_100';
    case TotalPlayTimeMilliseconds = 'total_play_time_milliseconds';
    case AverageVideoPlayTimeMilliseconds = 'average_video_play_time_milliseconds';
    case TotalAudience = 'total_audience';
    case EngagedAudience = 'engaged_audience';
    case SubscribersGained = 'subscribers_gained';
    case SubscribersLost = 'subscribers_lost';
    case StoryNavigation = 'story_navigation';
    case StoryTapsForward = 'story_taps_forward';
    case StoryTapsBack = 'story_taps_back';
    case StoryExits = 'story_exits';
    case StorySwipesForward = 'story_swipes_forward';
    case UniqueViewers = 'unique_viewers';
}
