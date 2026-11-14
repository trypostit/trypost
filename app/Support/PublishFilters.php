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

        return new self(
            labelIds: RequestIds::uuidList($request->collect('labels')),
            untagged: $request->boolean('untagged'),
            requestedChannelIds: $requestedChannelIds,
            scopedChannelIds: match (true) {
                filled($channel) => [$channel->id],
                filled($requestedChannelIds) => $requestedChannelIds,
                default => null,
            },
            displayTimezone: self::displayTimezone($request->query('tz'), Timezone::normalize($user->timezone)),
        );
    }

    /**
     * The requested zone when it is a known one, else the user's zone.
     */
    private static function displayTimezone(mixed $requested, string $userTimezone): string
    {
        if (! is_string($requested) || blank($requested)) {
            return $userTimezone;
        }

        $normalized = Timezone::normalize($requested);

        return $normalized === Timezone::DEFAULT && $requested !== Timezone::DEFAULT ? $userTimezone : $normalized;
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
