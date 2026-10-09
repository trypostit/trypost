<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Post;

use App\Actions\Post\UpdatePostRecurrence;
use App\Http\Resources\Api\PostResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Post;
use App\Models\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Stop a post from repeating: removes its recurrence rule. The post itself and its next scheduled time stay as they are.')]
class ClearPostRecurrenceTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->currentWorkspace($request);

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $postId = $request->get('post_id');
        $post = is_string($postId) && Str::isUuid($postId)
            ? Post::where('workspace_id', $workspace->id)->find($postId)
            : null;

        if (! $post) {
            return Response::error('Post not found.');
        }

        if ($denied = $this->denyUnlessCan($request, 'update', $post, 'Post not found.')) {
            return $denied;
        }

        UpdatePostRecurrence::execute($post, null);

        $post = Post::query()->with(['socialAccount', 'labels'])->findOrFail($post->id);

        return Response::structured((new PostResource($post))->resolve());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->required()->description('UUID of the post.'),
        ];
    }
}
