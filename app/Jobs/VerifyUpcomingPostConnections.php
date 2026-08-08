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
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\ConnectionVerifier;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class VerifyUpcomingPostConnections implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    // Covers the timeout plus queue-wait headroom, up to the full 15-minute
    // schedule cadence — a stuck/slow run can't leave a stale lock blocking
    // the next legitimate dispatch for this workspace.
    public int $uniqueFor = 900;

    public function __construct(public string $workspaceId) {}

    public function uniqueId(): string
    {
        return $this->workspaceId;
    }

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
                $account->refresh();

                // markAsTokenExpired() no-ops if it couldn't acquire the
                // account's status lock (another process — e.g. a concurrent
                // publish attempt or the daily check — holds it). Only warn
                // once the status change is confirmed; a lost race here just
                // means this account is picked up again on the next run.
                if ($account->status !== SocialAccountStatus::TokenExpired) {
                    Log::warning('Upcoming-post connection check: could not mark account token_expired (status lock contended), deferring to next run', [
                        'account_id' => $account->id,
                        'platform' => $account->platform->value,
                    ]);

                    continue;
                }

                $atRisk->push(['account' => $account, 'postPlatforms' => $group]);
            } catch (\Exception $e) {
                Log::error('Failed to verify social account connection for upcoming-post check', [
                    'account_id' => $account->id,
                    'platform' => $account->platform->value,
                    'error' => $e->getMessage(),
                ]);

                // Unknown error — don't mark as broken, retry next run.
                continue;
            }
        }

        if ($atRisk->isEmpty()) {
            return;
        }

        $owner = $workspace->owner;

        if (! $owner) {
            // No owner to notify — leave these rows unwarned so a future run
            // (once the workspace has an owner) can pick them back up.
            return;
        }

        $warnedIds = $atRisk->flatMap(fn (array $group) => $group['postPlatforms']->pluck('id'));
        PostPlatform::whereIn('id', $warnedIds)->update(['connection_warning_sent_at' => now()]);

        $this->notifyOwner($owner, $workspace, $atRisk);
    }

    /**
     * @return Collection<int, PostPlatform>
     */
    private function atRiskPostPlatforms(): Collection
    {
        return PostPlatform::query()
            ->where('status', PostPlatformStatus::Pending)
            ->enabled() // PublishPost only iterates enabled platforms — an at-risk warning for a disabled one would be a false positive.
            ->whereNotNull('social_account_id')
            ->where(function ($query) {
                $query->whereNull('connection_warning_sent_at')
                    ->orWhere('connection_warning_sent_at', '<', now()->subDay());
            })
            ->whereHas('post', function ($query) {
                $query->where('workspace_id', $this->workspaceId)
                    ->scheduled()
                    ->whereBetween('scheduled_at', [now(), now()->addHour()]);
            })
            ->with(['socialAccount.workspace', 'post'])
            ->get();
    }

    /**
     * @param  Collection<int, array{account: SocialAccount, postPlatforms: Collection<int, PostPlatform>}>  $atRisk
     */
    private function notifyOwner(User $owner, Workspace $workspace, Collection $atRisk): void
    {
        $postCount = $atRisk->sum(fn (array $group) => $group['postPlatforms']->count());

        SendNotification::dispatch(
            user: $owner,
            workspaceId: $workspace->id,
            type: Type::PostAtRisk,
            channel: Channel::Both,
            title: $postCount === 1 ? '1 upcoming post is at risk' : "{$postCount} upcoming posts are at risk",
            body: $atRisk->map(fn (array $group) => $group['account']->platform->label().' ('.$group['account']->handle().')')->implode(', '),
            data: ['workspace_id' => $workspace->id],
            mailable: new PostAtRisk($workspace, $atRisk),
        );
    }
}
