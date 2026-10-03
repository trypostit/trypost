<?php

declare(strict_types=1);

namespace App\Actions\Post\Queue;

use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class BuildQueueTimeline
{
    /**
     * @param  Collection<int, SocialAccount>  $channels  visible channels (already workspace-scoped)
     * @param  list<string>  $labelIds
     * @return list<array{date: string, items: list<array{type: 'post'|'slot', at: string, channel_id: string, post_id: ?string}>}>
     */
    public static function handle(Workspace $workspace, Collection $channels, string $displayTimezone, CarbonInterface $until, array $labelIds = [], bool $untagged = false): array
    {
        $channelIds = $channels->pluck('id')->all();

        $posts = Post::query()
            ->where('workspace_id', $workspace->id)
            ->holdingSlot()
            ->whereBetween('scheduled_at', [now(), $until])
            ->whereHas('postPlatforms', fn ($platforms) => $platforms->enabled()->whereIn('social_account_id', $channelIds))
            ->matchingLabelFilter($labelIds, $untagged)
            ->with(['postPlatforms' => fn ($platforms) => $platforms->enabled()->whereIn('social_account_id', $channelIds)->orderBy('created_at')->orderBy('id')])
            ->get();

        $items = [];
        $occupied = [];

        foreach ($posts as $post) {
            $channelId = data_get($post->postPlatforms->first(), 'social_account_id');

            if ($channelId === null) {
                continue;
            }

            $occupied[$channelId][$post->scheduled_at->getTimestamp()] = true;

            if ($post->status === PostStatus::Scheduled) {
                $items[] = [
                    'type' => 'post',
                    'at' => $post->scheduled_at->utc()->toIso8601String(),
                    'channel_id' => $channelId,
                    'post_id' => $post->id,
                ];
            }
        }

        if ($labelIds === [] && ! $untagged) {
            foreach ($channels as $channel) {
                $schedule = $channel->posting_schedule;

                if ($schedule === null) {
                    continue;
                }

                foreach ($schedule->slotsBetween(now()->addMinute(), $until, $channel->timezone) as $slot) {
                    if (isset($occupied[$channel->id][$slot->getTimestamp()])) {
                        continue;
                    }

                    $items[] = [
                        'type' => 'slot',
                        'at' => $slot->utc()->toIso8601String(),
                        'channel_id' => $channel->id,
                        'post_id' => null,
                    ];
                }
            }
        }

        usort($items, fn (array $a, array $b): int => [$a['at'], $a['type'] === 'slot' ? 1 : 0] <=> [$b['at'], $b['type'] === 'slot' ? 1 : 0]);

        $groups = [];

        foreach ($items as $item) {
            $date = CarbonImmutable::parse($item['at'])->setTimezone($displayTimezone)->format('Y-m-d');
            $groups[$date][] = $item;
        }

        ksort($groups);

        return array_map(
            fn (string $date, array $dayItems): array => ['date' => $date, 'items' => $dayItems],
            array_keys($groups),
            array_values($groups),
        );
    }
}
