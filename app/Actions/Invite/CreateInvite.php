<?php

declare(strict_types=1);

namespace App\Actions\Invite;

use App\Enums\PostHog\WorkspaceEvent;
use App\Jobs\PostHog\CaptureAccountEvent;
use App\Mail\WorkspaceInvite as WorkspaceInviteMail;
use App\Models\Invite;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PostHogService;
use Illuminate\Support\Facades\Mail;

class CreateInvite
{
    public static function execute(Workspace $workspace, array $data): Invite
    {
        $inviter = auth()->user();

        $invite = Invite::create([
            'account_id' => $workspace->account_id,
            'invited_by' => $inviter->id,
            'email' => data_get($data, 'email'),
            'is_admin' => (bool) data_get($data, 'is_admin'),
            'requires_approval' => (bool) data_get($data, 'requires_approval'),
            'workspaces' => [$workspace->id],
        ]);

        Mail::to($invite->email)
            ->locale($inviter->preferredLocale())
            ->send(new WorkspaceInviteMail($invite));

        rescue(fn () => self::trackInvited($workspace, $invite, $inviter));

        return $invite;
    }

    private static function trackInvited(Workspace $workspace, Invite $invite, User $inviter): void
    {
        if (! PostHogService::isEnabled()) {
            return;
        }

        CaptureAccountEvent::dispatch(
            (string) $inviter->id,
            (string) $workspace->account_id,
            WorkspaceEvent::MemberInvited->value,
            [
                'is_admin' => $invite->is_admin,
                'requires_approval' => $invite->requires_approval,
            ],
        );
    }
}
