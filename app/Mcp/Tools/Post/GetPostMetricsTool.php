<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Post;

use App\Actions\Analytics\ReadPublicationAnalytics;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Post;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Read the latest saved analytics of a published TryPost post, including reactions, comments, saves, reach, views, and watch time where supported. Values may lag the provider until the next analytics job. metrics is "unsupported" for an unpublished post or a network without analytics.')]
class GetPostMetricsTool extends Tool
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

        return Response::structured(app(ReadPublicationAnalytics::class)->forPost($post));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->required()->description('UUID of the published post.'),
        ];
    }
}
