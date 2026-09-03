<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Webhook;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WebhookPausedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Webhook $webhook) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('webhooks.mail.paused_subject', ['endpoint' => $this->webhook->endpoint]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.webhook-paused',
            with: [
                'title' => __('webhooks.mail.paused_title'),
                'previewText' => __('webhooks.mail.paused_preview'),
                'body' => __('webhooks.mail.paused_body', ['endpoint' => $this->webhook->endpoint]),
                'buttonText' => __('webhooks.mail.paused_cta'),
                'url' => route('app.webhooks.show', $this->webhook),
            ],
        );
    }
}
