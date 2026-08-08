<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Notification\Channel;
use App\Enums\Notification\Type;
use App\Enums\PostPlatform\Status as PostPlatformStatus;
use App\Enums\SocialAccount\Status as SocialAccountStatus;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\TokenExpiredException;
use App\Mail\PostAtRisk;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Social\ConnectionVerifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class VerifyUpcomingPostConnections implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public string $workspaceId) {}

    public function handle(ConnectionVerifier $verifier): void
    {
        $workspace = Workspace::find($this->workspaceId);

        if (! $workspace) {
            return;
        }

        $postPlatforms = $this->atRiskPostPlatforms();

        if ($postPlatforms->isEmpty()) {
            return;
        }

        $atRisk = new Collection;

        foreach ($postPlatforms->groupBy('social_account_id') as $group) {
            $account = $group->first()->socialAccount;

            if (in_array($account->status, [SocialAccountStatus::TokenExpired, SocialAccountStatus::Disconnected], true)) {
                // Already known broken from an earlier run — don't re-verify,
                // just warn about the posts that entered the window since then.
                $atRisk->push(['account' => $account, 'postPlatforms' => $group]);

                continue;
            }

            try {
                $verifier->verify($account);
            } catch (PlatformUnavailableException $e) {
                Log::warning('Upcoming-post connection check skipped: platform unavailable', [
                    'account_id' => $account->id,
                    'platform' => $account->platform->value,
                    'error' => $e->getMessage(),
                ]);

                continue;
            } catch (TokenExpiredException $e) {
                $account->markAsTokenExpired($e->getMessage(), notify: false);
                $atRisk->push(['account' => $account, 'postPlatforms' => $group]);
            }
        }

        if ($atRisk->isEmpty()) {
            return;
        }

        $warnedIds = $atRisk->flatMap(fn (array $group) => $group['postPlatforms']->pluck('id'));
        PostPlatform::whereIn('id', $warnedIds)->update(['connection_warning_sent_at' => now()]);

        $this->notifyOwner($workspace, $atRisk);
    }

    /**
     * @return Collection<int, PostPlatform>
     */
    private function atRiskPostPlatforms(): Collection
    {
        return PostPlatform::query()
            ->where('status', PostPlatformStatus::Pending)
            ->where('enabled', true) // PublishPost only iterates enabled=true platforms — an at-risk warning for a disabled one would be a false positive.
            ->whereNull('connection_warning_sent_at')
            ->whereHas('post', function ($query) {
                $query->where('workspace_id', $this->workspaceId)
                    ->scheduled()
                    ->whereBetween('scheduled_at', [now(), now()->addHour()]);
            })
            ->with(['socialAccount', 'post'])
            ->get();
    }

    /**
     * @param  Collection<int, array{account: SocialAccount, postPlatforms: Collection<int, PostPlatform>}>  $atRisk
     */
    private function notifyOwner(Workspace $workspace, Collection $atRisk): void
    {
        $owner = $workspace->owner;

        if (! $owner) {
            return;
        }

        $postCount = $atRisk->sum(fn (array $group) => $group['postPlatforms']->count());

        SendNotification::dispatch(
            user: $owner,
            workspaceId: $workspace->id,
            type: Type::PostAtRisk,
            channel: Channel::Both,
            title: $postCount === 1 ? '1 upcoming post is at risk' : "{$postCount} upcoming posts are at risk",
            body: $atRisk->map(fn (array $group) => $group['account']->platform->label().' (@'.($group['account']->username ?? $group['account']->display_name).')')->implode(', '),
            data: ['workspace_id' => $workspace->id],
            mailable: new PostAtRisk($workspace, $atRisk),
        );
    }
}
