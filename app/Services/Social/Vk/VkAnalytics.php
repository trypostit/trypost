<?php

declare(strict_types=1);

namespace App\Services\Social\Vk;

use App\Models\PostPlatform;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use Throwable;

class VkAnalytics
{
    /**
     * Account-level metrics: the community's member count (or the profile's
     * follower count). Deeper community stats (stats.get) need the `stats`
     * scope the connect flow doesn't request, so they are not fetched.
     *
     * @return array<int, array{label: string, value: int}>
     */
    public function getMetrics(SocialAccount $account): array
    {
        $ownerId = (int) (data_get($account->meta, 'owner_id') ?? $account->platform_user_id);

        try {
            if ($ownerId < 0) {
                $response = Http::asForm()->post(VkApi::endpoint('groups.getById'), [
                    'group_id' => abs($ownerId),
                    'fields' => 'members_count',
                ] + VkApi::baseParams($account->access_token))->json();

                // v5.199 отдаёт response.groups[], более старые версии — response[].
                $count = data_get($response, 'response.groups.0.members_count')
                    ?? data_get($response, 'response.0.members_count');
            } else {
                $response = Http::asForm()->post(VkApi::endpoint('users.get'), [
                    'user_ids' => $ownerId,
                    'fields' => 'followers_count',
                ] + VkApi::baseParams($account->access_token))->json();

                $count = data_get($response, 'response.0.followers_count');
            }
        } catch (Throwable) {
            return [];
        }

        if (! is_int($count)) {
            return [];
        }

        return [
            ['label' => __('analytics.metrics.subscribers'), 'value' => $count],
        ];
    }

    /**
     * Post-level metrics from wall.getById: views, likes, reposts, comments.
     *
     * @return array<int, array{label: string, value: int}>
     */
    public function fetchPostMetrics(PostPlatform $postPlatform): array
    {
        $account = $postPlatform->socialAccount;
        $postId = $postPlatform->platform_post_id;

        if (! $account || ! $postId) {
            return [];
        }

        $ownerId = (int) (data_get($account->meta, 'owner_id') ?? $account->platform_user_id);

        try {
            $response = Http::asForm()->post(VkApi::endpoint('wall.getById'), [
                'posts' => "{$ownerId}_{$postId}",
            ] + VkApi::baseParams($account->access_token))->json();
        } catch (Throwable) {
            return [];
        }

        // v5.199 отдаёт response.items[], более старые версии — response[].
        $post = data_get($response, 'response.items.0') ?? data_get($response, 'response.0');

        if (! is_array($post)) {
            return [];
        }

        $metrics = [];

        foreach ([
            'views.count' => 'analytics.metrics.views',
            'likes.count' => 'analytics.metrics.likes',
            'reposts.count' => 'analytics.metrics.reposts',
            'comments.count' => 'analytics.metrics.comments',
        ] as $path => $labelKey) {
            $value = data_get($post, $path);

            if (is_int($value)) {
                $metrics[] = ['label' => __($labelKey), 'value' => $value];
            }
        }

        return $metrics;
    }
}
