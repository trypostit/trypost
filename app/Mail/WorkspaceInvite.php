<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Invite;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WorkspaceInvite extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Invite $invite
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail.workspace_invite.subject', ['account' => $this->invite->account->name]),
        );
    }

    public function content(): Content
    {
        $accountName = $this->invite->account->name;
        $roleLabel = $this->invite->role?->label() ?? '';

        return new Content(
            view: 'mail.workspace-invite',
            with: [
                'title' => __('mail.workspace_invite.title', ['account' => $accountName]),
                'previewText' => __('mail.workspace_invite.preview', ['account' => $accountName]),
                'accountName' => $accountName,
                'roleLabel' => $roleLabel,
                'url' => route('app.invites.show', $this->invite),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
