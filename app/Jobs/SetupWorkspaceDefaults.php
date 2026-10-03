<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Idea\CreateDefaultIdeaStages;
use App\Models\Workspace;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SetupWorkspaceDefaults implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function __construct(public string $workspaceId) {}

    public function handle(): void
    {
        $workspace = Workspace::query()->with('owner')->find($this->workspaceId);

        if ($workspace === null) {
            return;
        }

        CreateDefaultIdeaStages::execute($workspace);
    }
}
