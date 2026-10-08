<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Post;

use App\Actions\Post\DeletePost;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Post;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Delete a post from TryPost permanently; this cannot be undone. A post is never removed from the network where it was published. Posts that are publishing, published or failed cannot be changed or deleted. A member who needs approval may delete only posts they wrote, or requests they made that are still pending approval.')]
class DeletePostTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate(['post_id' => ['required', 'uuid']]);

        $workspace = $request->user()?->currentWorkspace;
        $post = $workspace
            ? Post::where('workspace_id', $workspace->id)->find(data_get($validated, 'post_id'))
            : null;

        if (! $post) {
            return Response::error('Post not found.');
        }

        if ($denied = $this->denyUnlessCan($request, 'delete', $post, 'Post not found.')) {
            return $denied;
        }

        DeletePost::execute($post, respectStatus: true);

        return Response::structured(['deleted' => true]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->required()->description('The post ID to delete.'),
        ];
    }
}
