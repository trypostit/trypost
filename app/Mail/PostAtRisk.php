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
     * @param  Collection<int, array{account: SocialAccount, postPlatforms: Collection<int, PostPlatform>}>  $atRiskGroups
     */
    public function __construct(
        public Workspace $workspace,
        public Collection $atRiskGroups
    ) {
        $this->locale = config('app.locale');
    }

    public function envelope(): Envelope
    {
        $count = $this->atRiskGroups->sum(fn (array $group) => $group['postPlatforms']->count());

        return new Envelope(
            subject: trans_choice('mail.post_at_risk.subject', $count, [
                'count' => $count,
                'workspace' => $this->workspace->name,
            ], $this->locale),
        );
    }

    public function content(): Content
    {
        $count = $this->atRiskGroups->sum(fn (array $group) => $group['postPlatforms']->count());
        $workspaceName = $this->workspace->name;
        $locale = $this->locale;

        // Reassigning the public property (not a local variable) is required: Mailable::buildViewData()
        // overwrites `with()` data with public properties of the same name, so this must stay in sync.
        $this->atRiskGroups = $this->atRiskGroups->map(function (array $group) use ($locale) {
            $postCount = $group['postPlatforms']->count();
            $times = $group['postPlatforms']->map(fn ($pp) => $pp->post->scheduled_at->format('H:i'))->implode(', ');

            return [
                'account' => $group['account'],
                'postPlatforms' => $group['postPlatforms'],
                'postsLabel' => trans_choice('mail.post_at_risk.posts_label', $postCount, [
                    'count' => $postCount,
                    'times' => $times,
                ], $locale),
            ];
        });

        return new Content(
            view: 'mail.post-at-risk',
            with: [
                'title' => __('mail.post_at_risk.title', [], $locale),
                'previewText' => trans_choice('mail.post_at_risk.subject', $count, [
                    'count' => $count,
                    'workspace' => $workspaceName,
                ], $locale),
                'intro' => __('mail.post_at_risk.intro', ['workspace' => $workspaceName], $locale),
                'reconnectCta' => __('mail.post_at_risk.reconnect_cta', [], $locale),
                'buttonText' => __('mail.post_at_risk.button', [], $locale),
                'workspace' => $this->workspace,
                'atRiskGroups' => $this->atRiskGroups,
                'url' => route('app.accounts'),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
