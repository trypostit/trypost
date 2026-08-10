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
#[Description('Delete a post permanently. This cannot be undone.')]
class DeletePostTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate(['post_id' => ['required', 'string']]);

        $post = Post::where('workspace_id', $request->user()?->current_workspace_id)
            ->find(data_get($validated, 'post_id'));

        if (! $post) {
            return Response::error('Post not found.');
        }

        if ($denied = $this->denyUnlessCan($request, 'delete', $post, 'Not authorized to delete this post.')) {
            return $denied;
        }

        DeletePost::execute($post);

        return Response::structured(['deleted' => true]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->required()->description('The post ID to delete.'),
        ];
    }
}
