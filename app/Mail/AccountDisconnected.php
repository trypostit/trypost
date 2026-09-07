<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\SocialAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AccountDisconnected extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public SocialAccount $account
    ) {}

    public function envelope(): Envelope
    {
        $platformName = $this->account->platform->label();
        $workspaceName = $this->account->workspace->name;

        return new Envelope(
            subject: __('mail.account_disconnected.subject', [
                'platform' => $platformName,
                'workspace' => $workspaceName,
            ]),
        );
    }

    public function content(): Content
    {
        $platformName = $this->account->platform->label();
        $accountName = $this->account->accountDisplayName();
        $workspaceName = $this->account->workspace->name;

        return new Content(
            view: 'mail.account-disconnected',
            with: [
                'title' => __('mail.account_disconnected.title', ['platform' => $platformName]),
                'previewText' => __('mail.account_disconnected.preview', [
                    'platform' => $platformName,
                    'workspace' => $workspaceName,
                ]),
                'account' => $this->account,
                'platformName' => $platformName,
                'accountName' => $accountName,
                'workspaceName' => $workspaceName,
                'url' => route('app.accounts'),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
