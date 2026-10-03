<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Post;

use App\Actions\Post\Approval\RejectPost;
use App\Exceptions\Post\QueueBusyException;
use App\Http\Resources\Api\PostResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Post;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Reject a post that is waiting for approval (status pending_approval). The post goes back to drafts for its author; no reason is stored. Only members who publish directly can reject.')]
class RejectPostTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'post_id' => ['required', 'uuid'],
        ]);

        $workspace = $request->user()?->currentWorkspace;
        $post = $workspace
            ? Post::where('workspace_id', $workspace->id)->find(data_get($validated, 'post_id'))
            : null;

        if (! $post) {
            return Response::error('Post not found.');
        }

        if ($denied = $this->denyUnlessCan($request, 'approve', $post, 'Not authorized to reject this post.')) {
            return $denied;
        }

        try {
            $rejected = RejectPost::execute($post, $request->user());
        } catch (QueueBusyException) {
            return Response::error(__('posts.errors.queue_busy'));
        }

        $rejected->load(['postPlatforms.socialAccount', 'labels']);

        return Response::structured((new PostResource($rejected))->resolve());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->required()->description('UUID of the post waiting for approval.'),
        ];
    }
}
