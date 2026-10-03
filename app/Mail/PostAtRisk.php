<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\PostPlatform;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Mail\RecipientTime;
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

    public ?User $recipient = null;

    /**
     * Only the workspace and the recipient (real Eloquent models, reduced to
     * lightweight identifiers by SerializesModels), the post_platform IDs, and the count
     * observed at dispatch time are carried on the queue payload. The rows
     * themselves are rehydrated in atRiskGroups() so the queued job's
     * serialized size stays small and the account/post details reflect
     * state as of send time, not as of dispatch time.
     *
     * $count drives the subject and preview text and is the dispatch-time
     * value, not re-derived from the rehydrated rows. If a row disappears
     * between dispatch and send, the subject may differ from the number of
     * rows actually listed in the body — an acceptable rare edge case.
     *
     * @param  array<int, string>  $postPlatformIds
     */
    public function __construct(
        public Workspace $workspace,
        public array $postPlatformIds,
        public int $count,
        ?User $recipient = null,
    ) {
        $this->recipient = $recipient;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: trans_choice('mail.post_at_risk.subject', $this->count, [
                'count' => $this->count,
                'workspace' => $this->workspace->name,
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.post-at-risk',
            with: [
                'title' => __('mail.post_at_risk.title'),
                'previewText' => trans_choice('mail.post_at_risk.subject', $this->count, [
                    'count' => $this->count,
                    'workspace' => $this->workspace->name,
                ]),
                'workspaceName' => $this->workspace->name,
                'timezone' => RecipientTime::timezone($this->recipient()),
                'atRiskGroups' => $this->atRiskGroups(),
                'url' => route('app.workspace.channels'),
            ],
        );
    }

    /**
     * @return Collection<int, array{account: mixed, postPlatforms: Collection<int, PostPlatform>, postCount: int, times: string}>
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
                return [
                    'account' => $group->first()->socialAccount,
                    'postPlatforms' => $group,
                    'postCount' => $group->count(),
                    'times' => $group->sortBy(fn ($pp) => $pp->post->scheduled_at)
                        ->map(fn ($pp) => RecipientTime::clock($pp->post->scheduled_at, $this->recipient()))
                        ->implode(', '),
                ];
            })->values();
    }

    private function recipient(): User
    {
        return $this->recipient ?? $this->workspace->owner;
    }

    public function attachments(): array
    {
        return [];
    }
}
