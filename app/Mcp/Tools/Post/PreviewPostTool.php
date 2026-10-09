<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Post;

use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Post;
use App\Services\Post\PostPreviewer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Preview how a post will be sent to its channel without publishing: the exact text the network gets (X links defused when that is on), next to the original content, with length stats. Use it to check a text before scheduling.')]
class PreviewPostTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'post_id' => ['required', 'uuid'],
        ]);

        $workspace = $request->user()?->currentWorkspace;

        $post = $workspace
            ? Post::where('workspace_id', $workspace->id)
                ->with(['socialAccount'])
                ->find(data_get($validated, 'post_id'))
            : null;

        if (! $post) {
            return Response::error('Post not found.');
        }

        if ($denied = $this->denyUnlessCan($request, 'view', $post, 'Post not found.')) {
            return $denied;
        }

        return Response::structured(app(PostPreviewer::class)->forPost($post));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->required()->description('UUID of the post to preview.'),
        ];
    }
}
