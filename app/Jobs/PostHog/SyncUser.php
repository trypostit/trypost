<?php

declare(strict_types=1);

namespace App\Jobs\PostHog;

use App\Models\User;
use App\Services\PostHogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncUser implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public string $userId)
    {
        $this->onQueue('posthog');
    }

    public function handle(PostHogService $postHog): void
    {
        if (! PostHogService::isEnabled()) {
            return;
        }

        $user = User::find($this->userId);

        if (! $user) {
            return;
        }

        $attribution = array_filter([
            'utm_source' => $user->utm_source,
            'utm_medium' => $user->utm_medium,
            'utm_campaign' => $user->utm_campaign,
            'utm_term' => $user->utm_term,
            'utm_content' => $user->utm_content,
            'gclid' => $user->gclid,
            'fbclid' => $user->fbclid,
            'li_fat_id' => $user->li_fat_id,
            'ttclid' => $user->ttclid,
            'rdt_cid' => $user->rdt_cid,
            'epik' => $user->epik,
        ]);

        $postHog->identify($user->id, [
            '$email' => $user->email,
            '$name' => $user->name,
            '$set_once' => [
                'signed_up_at' => $user->created_at?->toIso8601String(),
                ...$attribution,
            ],
        ]);

        if ($user->account_id) {
            SyncAccountUsage::dispatch(
                $user->account_id,
                $user->current_workspace_id ? $user->current_workspace_id : null,
            );
        }
    }
}
