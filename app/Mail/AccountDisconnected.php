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
            subject: "Your {$platformName} account in {$workspaceName} needs to be reconnected",
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
                'title' => "Your {$platformName} account needs to be reconnected",
                'previewText' => "Please reconnect your {$platformName} account in {$workspaceName} to continue scheduling posts.",
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
