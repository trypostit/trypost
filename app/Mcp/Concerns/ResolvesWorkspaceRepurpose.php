<?php

declare(strict_types=1);

namespace App\Mcp\Concerns;

use App\Models\Repurpose;
use App\Models\Workspace;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

trait ResolvesWorkspaceRepurpose
{
    protected function repurposeInWorkspace(Workspace $workspace, mixed $id): Repurpose|Response|ResponseFactory
    {
        $repurpose = Repurpose::query()
            ->where('workspace_id', $workspace->id)
            ->find($id);

        if (! $repurpose instanceof Repurpose) {
            return Response::error('Repurpose not found.');
        }

        return $repurpose;
    }
}
