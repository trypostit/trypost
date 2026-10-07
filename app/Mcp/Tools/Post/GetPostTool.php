<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Post;

use App\Http\Resources\Api\PostResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Post;
use App\Models\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Get a specific post by ID. Times are UTC (Y-m-d H:i:s). Returns its content, media, labels, status, schedule_mode, scheduled_at, published_at, recurrence, approval fields (approval_requested_by, approved_by and their times), origin, post_group_id, and for its platform the content_type, meta (thread_replies included), status, error_message and platform_url.')]
class GetPostTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate(['post_id' => ['required', 'uuid']]);

        $workspace = $this->currentWorkspace($request);

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $post = Post::where('workspace_id', $workspace->id)
            ->with(['postPlatforms.socialAccount', 'labels'])
            ->find(data_get($validated, 'post_id'));

        if (! $post) {
            return Response::error('Post not found.');
        }

        if ($denied = $this->denyUnlessCan($request, 'view', $post, 'Post not found.')) {
            return $denied;
        }

        return Response::structured((new PostResource($post))->resolve());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->required()->description('The post ID to retrieve.'),
        ];
    }
}
