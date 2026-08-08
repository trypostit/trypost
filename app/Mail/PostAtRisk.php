<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\PostPlatform;
use App\Models\SocialAccount;
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
     * @param  Collection<int, array{account: SocialAccount, postPlatforms: Collection<int, PostPlatform>, postsLabel?: string}>  $atRiskGroups
     */
    public function __construct(
        public Workspace $workspace,
        public Collection $atRiskGroups
    ) {}

    public function envelope(): Envelope
    {
        $count = $this->atRiskGroups->sum(fn (array $group) => $group['postPlatforms']->count());

        return new Envelope(
            subject: $this->subjectFor($count),
        );
    }

    public function content(): Content
    {
        $count = $this->atRiskGroups->sum(fn (array $group) => $group['postPlatforms']->count());

        // Reassigning the public property (not a local variable) is required: Mailable::buildViewData()
        // overwrites `with()` data with public properties of the same name, so this must stay in sync.
        $this->atRiskGroups = $this->atRiskGroups->map(function (array $group) {
            $postCount = $group['postPlatforms']->count();
            $times = $group['postPlatforms']->map(fn ($pp) => $pp->post->scheduled_at->format('H:i'))->implode(', ');
            $noun = $postCount === 1 ? 'post' : 'posts';

            return [
                'account' => $group['account'],
                'postPlatforms' => $group['postPlatforms'],
                'postsLabel' => "{$postCount} {$noun} scheduled: {$times} UTC",
            ];
        });

        return new Content(
            view: 'mail.post-at-risk',
            with: [
                'title' => 'Posts May Fail to Publish',
                'previewText' => $this->subjectFor($count),
                'intro' => "The following social accounts in your {$this->workspace->name} workspace need to be reconnected before these scheduled posts can publish:",
                'reconnectCta' => 'Please reconnect these accounts now to avoid missing your scheduled posts.',
                'buttonText' => 'Reconnect Accounts',
                'workspace' => $this->workspace,
                'atRiskGroups' => $this->atRiskGroups,
                'url' => route('app.accounts'),
            ],
        );
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
