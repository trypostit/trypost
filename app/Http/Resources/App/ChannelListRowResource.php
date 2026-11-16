<?php

declare(strict_types=1);

namespace App\Http\Resources\App;

use App\Models\SocialAccount;
use App\Support\Timezone;
use Illuminate\Http\Request;

/**
 * A channel as a row of the channels settings list: the channel, its time
 * zone, its posting times a week and where its weekly goal stands (attached by
 * ListWorkspaceChannels::page()).
 *
 * @mixin SocialAccount
 */
class ChannelListRowResource extends SocialAccountResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'timezone_option' => Timezone::option($this->timezone),
            'posting_times_per_week' => $this->posting_schedule?->slotCount() ?? 0,
            'posting_goal' => $this->posting_goal,
            'sent_this_week' => $this->sent_this_week,
            'scheduled_this_week' => $this->scheduled_this_week,
        ];
    }
}
