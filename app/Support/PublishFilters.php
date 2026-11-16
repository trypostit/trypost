<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The filters every view of the Publish page (List, Calendar, Board) reads from
 * the URL: labels, untagged, the requested channels on the all-channels page and
 * the display time zone.
 */
final class PublishFilters
{
    /**
     * @param  list<string>  $labelIds
     * @param  list<string>  $requestedChannelIds
     * @param  list<string>|null  $scopedChannelIds
     */
    private function __construct(
        public readonly array $labelIds,
        public readonly bool $untagged,
        public readonly array $requestedChannelIds,
        public readonly ?array $scopedChannelIds,
        public readonly string $displayTimezone,
    ) {}

    public static function fromRequest(Request $request, ?SocialAccount $channel, User $user): self
    {
        $requestedChannelIds = filled($channel) ? [] : RequestIds::uuidList($request->collect('channels')->unique());
        $timezone = $request->query('tz');

        return new self(
            labelIds: RequestIds::uuidList($request->collect('labels')),
            untagged: $request->boolean('untagged'),
            requestedChannelIds: $requestedChannelIds,
            scopedChannelIds: self::scopedChannelIds($channel, $requestedChannelIds),
            displayTimezone: Timezone::known(is_string($timezone) ? $timezone : null) ?? Timezone::normalize($user->timezone),
        );
    }

    /**
     * The channels the posts are read from: the page's channel, else the ones
     * asked for, else every channel (null).
     *
     * @param  list<string>  $requestedChannelIds
     * @return list<string>|null
     */
    private static function scopedChannelIds(?SocialAccount $channel, array $requestedChannelIds): ?array
    {
        if (filled($channel)) {
            return [$channel->id];
        }

        if (filled($requestedChannelIds)) {
            return $requestedChannelIds;
        }

        return null;
    }

    /**
     * @return array{labels: list<string>, untagged: bool, channels: list<string>}
     */
    public function toProp(): array
    {
        return [
            'labels' => $this->labelIds,
            'untagged' => $this->untagged,
            'channels' => $this->requestedChannelIds,
        ];
    }
}
