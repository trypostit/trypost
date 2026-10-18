<?php

declare(strict_types=1);

namespace App\Observers;

use App\Http\Resources\App\InboxSavedRepliesResource;
use App\Models\WorkspaceSavedReply;

/**
 * Bumps the workspace's saved replies version whenever one is saved or
 * deleted, so edits within the same second still change the inbox's
 * `savedReplies` once-prop key.
 */
class SavedReplyVersionObserver
{
    public function saved(WorkspaceSavedReply $savedReply): void
    {
        InboxSavedRepliesResource::bumpVersion($savedReply->workspace_id);
    }

    public function deleted(WorkspaceSavedReply $savedReply): void
    {
        InboxSavedRepliesResource::bumpVersion($savedReply->workspace_id);
    }
}
