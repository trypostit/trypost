<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\SetupWorkspaceDefaults;
use App\Models\Workspace;

class WorkspaceObserver
{
    public function created(Workspace $workspace): void
    {
        SetupWorkspaceDefaults::dispatch($workspace->id)->afterCommit();
    }
}
