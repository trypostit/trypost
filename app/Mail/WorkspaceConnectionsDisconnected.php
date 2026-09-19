<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class WorkspaceConnectionsDisconnected extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  Collection<int, SocialAccount>  $disconnectedAccounts
     */
    public function __construct(
        public Workspace $workspace,
        public Collection $disconnectedAccounts
    ) {}

    public function envelope(): Envelope
    {
        $count = $this->disconnectedAccounts->count();

        return new Envelope(
            subject: trans_choice('mail.workspace_connections_disconnected.subject', $count, [
                'count' => $count,
                'workspace' => $this->workspace->name,
            ]),
        );
    }

    public function content(): Content
    {
        $count = $this->disconnectedAccounts->count();
        $workspaceName = $this->workspace->name;

        return new Content(
            view: 'mail.workspace-connections-disconnected',
            with: [
                'title' => __('mail.workspace_connections_disconnected.title'),
                'previewText' => trans_choice('mail.workspace_connections_disconnected.subject', $count, [
                    'count' => $count,
                    'workspace' => $workspaceName,
                ]),
                'workspaceName' => $workspaceName,
                'disconnectedAccounts' => $this->disconnectedAccounts,
                'url' => route('app.accounts'),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
