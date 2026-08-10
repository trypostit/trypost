<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\PostPlatform;
use App\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class PostAtRisk extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Rehydrated, grouped-by-account rows — computed once (lazily, at send
     * time, never on the queue payload).
     */
    private ?Collection $atRiskGroups = null;

    /**
     * Only the workspace (a real Eloquent model, reduced to a lightweight
     * identifier by SerializesModels), the post_platform IDs, and the count
     * observed at dispatch time are carried on the queue payload. The rows
     * themselves are rehydrated in atRiskGroups() so the queued job's
     * serialized size stays small and the account/post details reflect
     * state as of send time, not as of dispatch time.
     *
     * $count drives the subject and preview text specifically — kept as the
     * dispatch-time value (rather than re-derived from the rehydrated rows)
     * so it always matches the in-app notification's title, which is built
     * from this same count in VerifyUpcomingPostConnections::notifyOwner().
     * If a row disappears between dispatch and send, the subject may then
     * differ from the number of rows actually listed in the body — an
     * acceptable rare edge case, in exchange for the subject never
     * disagreeing with the in-app notification.
     *
     * @param  array<int, string>  $postPlatformIds
     */
    public function __construct(
        public Workspace $workspace,
        public array $postPlatformIds,
        public int $count
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectFor($this->count),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.post-at-risk',
            with: [
                'title' => 'Posts May Fail to Publish',
                'previewText' => $this->subjectFor($this->count),
                'intro' => "The following social accounts in your {$this->workspace->name} workspace need to be reconnected before these scheduled posts can publish:",
                'reconnectCta' => 'Please reconnect these accounts now to avoid missing your scheduled posts.',
                'buttonText' => 'Reconnect Accounts',
                'workspace' => $this->workspace,
                'atRiskGroups' => $this->atRiskGroups(),
                'url' => route('app.accounts'),
            ],
        );
    }

    /**
     * @return Collection<int, array{account: mixed, postPlatforms: Collection<int, PostPlatform>, postsLabel: string}>
     */
    private function atRiskGroups(): Collection
    {
        if ($this->atRiskGroups !== null) {
            return $this->atRiskGroups;
        }

        $postPlatforms = PostPlatform::query()
            ->with(['socialAccount', 'post'])
            ->whereIn('id', $this->postPlatformIds)
            ->get();

        return $this->atRiskGroups = $postPlatforms->groupBy('social_account_id')
            // The account can be null if it was hard-deleted between dispatch
            // and send — nothing meaningful to render for it (no platform, no
            // handle), so it's dropped rather than crashing the render.
            ->filter(fn (Collection $group) => $group->first()->socialAccount !== null)
            ->map(function (Collection $group) {
                $postCount = $group->count();
                $times = $group->sortBy(fn ($pp) => $pp->post->scheduled_at)
                    ->map(fn ($pp) => $pp->post->scheduled_at->format('H:i'))
                    ->implode(', ');
                $noun = $postCount === 1 ? 'post' : 'posts';

                return [
                    'account' => $group->first()->socialAccount,
                    'postPlatforms' => $group,
                    'postsLabel' => "{$postCount} {$noun} scheduled: {$times} UTC",
                ];
            })->values();
    }

    private function subjectFor(int $count): string
    {
        $noun = $count === 1 ? 'post is' : 'posts are';

        return "{$count} {$noun} at risk in {$this->workspace->name}";
    }

    public function attachments(): array
    {
        return [];
    }
}
