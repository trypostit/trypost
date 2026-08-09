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
use Exception;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

    // How long to wait before re-notifying about an account that's already
    // known broken, even if new posts keep entering the risk window in the
    // meantime — otherwise a busy schedule with a dead token sends a fresh
    // email every 15 minutes for as long as the account stays broken.
    private const RENOTIFY_COOLDOWN_MINUTES = 60;

    // Grace period after an account transitions to broken during which we
    // skip our own notification, on the assumption another process (the
    // daily sweep, RefreshExpiringTokens) just sent AccountDisconnected for
    // the same event — avoids two different emails landing at once.
    private const RECENTLY_DISCONNECTED_GRACE_MINUTES = 5;

    // How long a successful verify() is trusted (via SocialAccount::last_verified_at)
    // before re-checking the same account again. Combined with VERIFY_LEAD_MINUTES
    // below, this caps a healthy account at ~1 real API call per at-risk post
    // instead of one every 15-minute tick for the full 1-hour risk window —
    // platform rate-limit budget is finite and shared across the whole app.
    private const VERIFIED_WITHIN_MINUTES = 40;

    // Don't spend an API call verifying an account until its nearest at-risk
    // post is this close to publishing. A token that's already dead stays
    // dead, so checking earlier buys no extra safety — only less API budget
    // spent per post, while still leaving enough lead time to reconnect.
    private const VERIFY_LEAD_MINUTES = 30;

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

            if (! $account) {
                // The account was hard-deleted between the main query and its
                // eager-loaded relation resolving (two separate queries) —
                // nothing left to verify or warn about for this group.
                continue;
            }

            $group = $group->filter(fn (PostPlatform $pp) => $pp->post !== null);

            if ($group->isEmpty()) {
                // Same race as above, but for the post: every row in this
                // batch was hard-deleted between the main query and its
                // eager-loaded relation resolving.
                continue;
            }

            // atRiskPostPlatforms()'s is_active guard only runs at query
            // time; this job can take real wall-clock time working through
            // a workspace, so re-check fresh (paused/deleted since then
            // shouldn't burn an API call or warn about it). Keep workspace
            // eager-loaded — SocialAccountObserver reads it when
            // markAsTokenExpired() below updates the account (#255).
            $account = SocialAccount::active()->with('workspace')->find($account->id);

            if (! $account) {
                continue;
            }

            if (in_array($account->status, [SocialAccountStatus::TokenExpired, SocialAccountStatus::Disconnected], true)) {
                if ($this->recentlyWarnedAbout($account) || $this->recentlyDisconnected($account)) {
                    continue;
                }

                // Already known broken from an earlier run — don't re-verify,
                // just warn about the posts that entered the window since then.
                $atRisk->push(['account' => $account, 'postPlatforms' => $group]);

                continue;
            }

            if ($account->last_verified_at?->isAfter(now()->subMinutes(self::VERIFIED_WITHIN_MINUTES))) {
                // Confirmed healthy recently enough — trust it instead of
                // hitting the platform API again on every 15-minute tick.
                continue;
            }

            $nearestScheduledAt = $group->min(fn (PostPlatform $pp) => $pp->post->scheduled_at);

            if ($nearestScheduledAt->isAfter(now()->addMinutes(self::VERIFY_LEAD_MINUTES))) {
                // Not close enough to publishing yet — defer the actual API
                // call to a later run instead of spending budget checking
                // every 15-minute tick for the full 1-hour risk window.
                continue;
            }

            try {
                $verifier->verify($account);
                $account->update(['last_verified_at' => now()]);
            } catch (PlatformUnavailableException $e) {
                Log::warning('Upcoming-post connection check skipped: platform unavailable', [
                    'account_id' => $account->id,
                    'platform' => $account->platform->value,
                    'error' => $e->getMessage(),
                ]);

                continue;
            } catch (TokenExpiredException $e) {
                try {
                    // Re-check right before mutating: if the account is no longer
                    // Connected here, a concurrent process (e.g. RefreshExpiringTokens)
                    // beat us to discovering and announcing this same break via its
                    // own AccountDisconnected email. Only skip in that case — not
                    // when disconnected_at is fresh purely because our own update
                    // below is about to set it for the first time.
                    if ($account->refresh()->status !== SocialAccountStatus::Connected && $this->recentlyDisconnected($account)) {
                        continue;
                    }

                    $account->markAsTokenExpired($e->getMessage(), notify: false);
                    $account->refresh();
                } catch (Exception $lockOrDbError) {
                    // Covers the account being deleted mid-run (refresh()
                    // throws ModelNotFoundException) as well as infrastructure
                    // failures inside markAsTokenExpired() itself (its
                    // Cache::lock() or ->update() call). An exception thrown
                    // from inside a catch block isn't routed to a sibling
                    // catch, so this must be handled here to avoid aborting
                    // the run for every other account in this workspace.
                    Log::error('Failed to mark account token_expired for upcoming-post check', [
                        'account_id' => $account->id,
                        'platform' => $account->platform->value,
                        'error' => $lockOrDbError->getMessage(),
                    ]);

                    continue;
                }

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
            } catch (Exception $e) {
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

        // Conditioned on the same "unwarned" window atRiskPostPlatforms() selected
        // on, so a concurrent run that already claimed some or all of these
        // exact rows (the ShouldBeUnique lock's TTL matches the schedule
        // cadence, so two instances can briefly overlap if a run takes
        // unusually long) never gets re-claimed here. lockForUpdate() closes
        // the gap between reading which rows are still claimable and
        // stamping them — without it, two overlapping runs could both read
        // "unclaimed" for the same row before either writes.
        $warnedIds = $atRisk->flatMap(fn (array $group) => $group['postPlatforms']->pluck('id'));
        $claimedIds = DB::transaction(function () use ($warnedIds) {
            $claimableIds = PostPlatform::whereIn('id', $warnedIds)
                ->where(function ($query) {
                    $query->whereNull('connection_warning_sent_at')
                        ->orWhere('connection_warning_sent_at', '<', now()->subDay());
                })
                // Two overlapping runs can both claim rows here (see comment
                // above the transaction) — locking in a consistent order
                // (primary key) prevents them from deadlocking by acquiring
                // the same two rows' locks in opposite order.
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id');

            if ($claimableIds->isEmpty()) {
                return $claimableIds;
            }

            PostPlatform::whereIn('id', $claimableIds)->update(['connection_warning_sent_at' => now()]);

            return $claimableIds;
        }, attempts: 3);

        if ($claimedIds->isEmpty()) {
            return;
        }

        // (Pre-existing trade-off, not introduced by this transaction: a
        // crash between the DB transaction above and notifyOwner() below
        // loses the warning for 24h, until atRiskPostPlatforms()'s re-check window.)

        // A concurrent run may have already claimed some (not all) of these
        // rows between when $atRisk was built and the claim above — narrow
        // the notification down to what THIS run actually claimed, so the
        // email never lists an account/post pair another run is already
        // notifying about. $claimedIds is a non-empty subset of $warnedIds,
        // which is exactly the union of every group's post_platform ids, so
        // at least one group is guaranteed to survive this filter.
        $atRisk = $atRisk
            ->map(function (array $group) use ($claimedIds) {
                $group['postPlatforms'] = $group['postPlatforms']->filter(
                    fn (PostPlatform $pp) => $claimedIds->containsStrict($pp->id)
                );

                return $group;
            })
            ->filter(fn (array $group) => $group['postPlatforms']->isNotEmpty());

        $this->notifyOwner($owner, $workspace, $atRisk);
    }

    /**
     * Whether we've already sent a PostAtRisk notification covering this
     * account within the cooldown window — checked against any of its
     * post_platforms, not just the ones in the current batch.
     */
    private function recentlyWarnedAbout(SocialAccount $account): bool
    {
        return PostPlatform::query()
            ->where('social_account_id', $account->id)
            ->where('connection_warning_sent_at', '>=', now()->subMinutes(self::RENOTIFY_COOLDOWN_MINUTES))
            ->exists();
    }

    /**
     * Whether the account broke recently enough that another process (the
     * daily sweep, a proactive token refresh) likely just sent its own
     * AccountDisconnected email for the same event.
     */
    private function recentlyDisconnected(SocialAccount $account): bool
    {
        return $account->disconnected_at?->isAfter(now()->subMinutes(self::RECENTLY_DISCONNECTED_GRACE_MINUTES)) ?? false;
    }

    /**
     * @return Collection<int, PostPlatform>
     */
    private function atRiskPostPlatforms(): Collection
    {
        return PostPlatform::query()
            ->where('status', PostPlatformStatus::Pending)
            ->enabled() // PublishPost only iterates enabled platforms — an at-risk warning for a disabled one would be a false positive.
            // A paused account already fails at publish time with
            // posts.errors.account_inactive before any platform API call
            // (PublishToSocialPlatform::handle()) — verifying it here would
            // waste a real API call and, if the token also happens to be
            // dead, warn the owner to "reconnect" an account they paused on
            // purpose. whereHas() already excludes a null social_account_id
            // (nothing to join to).
            ->whereHas('socialAccount', fn ($query) => $query->where('is_active', true))
            ->where(function ($query) {
                $query->whereNull('connection_warning_sent_at')
                    ->orWhere('connection_warning_sent_at', '<', now()->subDay());
            })
            ->whereHas('post', function ($query) {
                $query->where('workspace_id', $this->workspaceId)
                    ->scheduled()
                    ->whereBetween('scheduled_at', [now(), now()->addHour()]);
            })
            // socialAccount.workspace is eager-loaded even though this job
            // never reads it directly — SocialAccountObserver::notifyOnboarding()
            // (fired by the ->update() calls below via markAsTokenExpired())
            // accesses $account->workspace, and lazy loading is disabled
            // app-wide. Dropping this eager load throws LazyLoadingViolationException
            // the moment a second account in the same run gets updated (see #255).
            ->with(['socialAccount.workspace', 'post'])
            ->get();
    }

    /**
     * @param  Collection<int, array{account: SocialAccount, postPlatforms: Collection<int, PostPlatform>}>  $atRisk
     */
    private function notifyOwner(User $owner, Workspace $workspace, Collection $atRisk): void
    {
        $postPlatforms = $atRisk->flatMap(fn (array $group) => $group['postPlatforms']);
        $postCount = $postPlatforms->pluck('post_id')->unique()->count();
        $postPlatformIds = $postPlatforms->pluck('id')->all();

        SendNotification::dispatch(
            user: $owner,
            workspaceId: $workspace->id,
            type: Type::PostAtRisk,
            channel: Channel::Both,
            title: trans_choice('notifications.post_at_risk.title', $postCount, ['count' => $postCount]),
            body: $atRisk->map(fn (array $group) => $group['account']->platform->label().' ('.$group['account']->handle().')')->implode(', '),
            data: ['workspace_id' => $workspace->id],
            mailable: new PostAtRisk($workspace, $postPlatformIds, $postCount),
        );
    }
}
