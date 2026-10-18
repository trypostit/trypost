<?php

declare(strict_types=1);

namespace App\Actions\Inbox;

use App\Enums\Inbox\DateRange;
use App\Enums\Inbox\InboxTab;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;

class CountOpenInboxMessages
{
    /**
     * Open messages of one tab, or of every tab (the sidebar badge) when no
     * tab is given, summed per channel in one query. Without a range it reads
     * the conversations' cached counts (the sidebar badge counts everything
     * open); with one, the inbox page's counts, it counts the open messages
     * written in the range, as the list shows them.
     *
     * @param  list<string>  $channelIds  only these channels; empty means every channel
     * @return array{total: int, channels: array<string, int>}
     */
    public static function execute(Workspace $workspace, ?InboxTab $tab = null, array $channelIds = [], ?DateRange $range = null): array
    {
        $tabs = $tab ? [$tab] : InboxTab::cases();
        $visible = fn (Builder $conversations) => $conversations
            ->where(fn (Builder $visible) => collect($tabs)->each(
                fn (InboxTab $each) => $visible->orWhere(fn (Builder $inTab) => $inTab->visibleInTab($workspace, $each)),
            ))
            ->when(filled($channelIds), fn (Builder $query) => $query->whereIn('social_account_id', $channelIds));

        $query = $range
            ? InboxMessage::query()
                ->open()
                ->where('platform_created_at', '>=', $range->since())
                ->whereHas('conversation', $visible)
                ->groupBy('social_account_id')
                ->selectRaw('social_account_id, COUNT(*) as open_total')
            : InboxConversation::query()
                ->tap($visible)
                ->where('open_count', '>', 0)
                ->groupBy('social_account_id')
                ->selectRaw('social_account_id, SUM(open_count) as open_total');

        $channels = $query->toBase()
            ->pluck('open_total', 'social_account_id')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();

        return [
            'total' => array_sum($channels),
            'channels' => $channels,
        ];
    }
}
