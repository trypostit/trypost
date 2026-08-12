<?php

declare(strict_types=1);

namespace App\Actions\Invite;

use App\Enums\UserWorkspace\Role as WorkspaceRole;
use App\Mail\WorkspaceInvite as WorkspaceInviteMail;
use App\Models\Invite;
use App\Models\Workspace;
use Illuminate\Support\Facades\Mail;

class CreateInvite
{
    public static function execute(Workspace $workspace, array $data): Invite
    {
        $invite = Invite::create([
            'account_id' => $workspace->account_id,
            'invited_by' => auth()->id(),
            'email' => data_get($data, 'email'),
            'role' => WorkspaceRole::from(data_get($data, 'role')),
            'workspaces' => [$workspace->id],
        ]);

        Mail::to($invite->email)->send(new WorkspaceInviteMail($invite));

        return $invite;
    }
}
