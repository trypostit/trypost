<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\MessageDirection;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\User;
use App\Support\Mail\PostExcerpt;
use App\Support\Mail\RecipientTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\SentMessage;
use Illuminate\Queue\SerializesModels;

class InboxActivity extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * $peopleCount is the number of people counted when the email was claimed;
     * the conversation itself is read at send time.
     */
    public function __construct(
        public string $conversationId,
        public int $peopleCount,
        public User $recipient,
    ) {}

    /**
     * The conversation can be deleted (with its channel) while the email waits
     * in the queue; the email is then dropped.
     */
    public function send(mixed $mailer): ?SentMessage
    {
        if (blank($this->conversation())) {
            return null;
        }

        return parent::send($mailer);
    }

    public function envelope(): Envelope
    {
        $conversation = $this->conversation();

        if (blank($conversation)) {
            return new Envelope(subject: __('mail.inbox_activity.title'));
        }

        $stars = self::stars($conversation->rating);

        $subject = match (true) {
            ! $this->isReview($conversation) => trans_choice('mail.inbox_activity.subject', $this->peopleCount, [
                'count' => $this->peopleCount,
                'network' => $conversation->socialAccount->platform->label(),
            ]),
            $stars !== '' => __('mail.inbox_activity.review_subject_rated', ['stars' => $stars, 'channel' => $this->channelName($conversation)]),
            default => __('mail.inbox_activity.review_subject', ['channel' => $this->channelName($conversation)]),
        };

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $conversation = $this->conversation();

        if (blank($conversation)) {
            return new Content(htmlString: e(__('mail.inbox_activity.title')));
        }

        $isReview = $this->isReview($conversation);
        $review = $isReview ? $this->review($conversation) : null;
        $subject = $conversation->subject();
        $at = $isReview ? $review?->platform_created_at : $subject['published_at'];

        return new Content(
            view: 'mail.inbox-activity',
            with: [
                'title' => $isReview ? __('mail.inbox_activity.review_title') : __('mail.inbox_activity.title'),
                'previewText' => $isReview ? __('mail.inbox_activity.review_subtitle') : __('mail.inbox_activity.subtitle'),
                'isReview' => $isReview,
                'peopleCount' => $this->peopleCount,
                'stars' => self::stars($conversation->rating),
                'channel' => [
                    'platform' => $conversation->socialAccount->platform,
                    'name' => $this->channelName($conversation),
                ],
                'reviewerName' => $isReview ? ($conversation->participant_name ?? $review?->author_name) : null,
                'excerpt' => PostExcerpt::from($isReview ? $review?->body : $subject['text'], 280),
                'time' => $at ? RecipientTime::dateTime($at, $this->recipient) : null,
                'timezone' => RecipientTime::timezone($this->recipient),
                'url' => route('app.inbox.conversations.show', $conversation),
            ],
        );
    }

    public static function stars(?int $rating): string
    {
        if (blank($rating)) {
            return '';
        }

        $filled = max(1, min(5, $rating));

        return str_repeat('★', $filled).str_repeat('☆', 5 - $filled);
    }

    public function attachments(): array
    {
        return [];
    }

    private function conversation(): ?InboxConversation
    {
        return InboxConversation::query()->with('socialAccount')->has('socialAccount')->find($this->conversationId);
    }

    private function isReview(InboxConversation $conversation): bool
    {
        return $conversation->kind === ConversationKind::Review;
    }

    private function review(InboxConversation $conversation): ?InboxMessage
    {
        return $conversation->messages()
            ->where('direction', MessageDirection::Inbound->value)
            ->whereNull('parent_id')
            ->oldest('platform_created_at')
            ->first();
    }

    private function channelName(InboxConversation $conversation): string
    {
        $account = $conversation->socialAccount;

        return $account->display_name ?: ($account->username ?: $account->platform->label());
    }
}
