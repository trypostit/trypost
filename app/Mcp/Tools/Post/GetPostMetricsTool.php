<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Post;

use App\Models\Post;
use App\Services\Post\PostMetricsFetcher;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Read the latest saved engagement metrics for a post across its published platforms. Values may lag the provider until the next analytics job. Returns "unsupported" for excluded or unpublished platforms.')]
class GetPostMetricsTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'post_id' => ['required', 'uuid'],
        ]);

        $post = Post::where('workspace_id', $request->user()->current_workspace_id)
            ->with(['postPlatforms.socialAccount'])
            ->find(data_get($validated, 'post_id'));

        if (! $post) {
            return Response::error('Post not found.');
        }

        return Response::structured([
            'post_id' => $post->id,
            'platforms' => app(PostMetricsFetcher::class)->forPost($post)->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->required()->description('UUID of the published post.'),
        ];
    }
}
