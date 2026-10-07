<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Workspace;

use App\Http\Resources\Api\WorkspaceResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Workspace;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Get the current workspace (the one this connection acts in: id, name, timestamps) and what the caller may do there: me.is_admin (manages members, settings, channels and posting times), me.requires_approval (true when the caller\'s scheduled, queued and publish-now posts are stored as pending_approval until an approver uses approve-post-tool) and me.publishes_directly.')]
class GetWorkspaceTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'view');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        return Response::structured((new WorkspaceResource($workspace))->for($request->user())->resolve());
    }
}
