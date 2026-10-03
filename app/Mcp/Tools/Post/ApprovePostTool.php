<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Post;

use App\Actions\Post\Approval\ApprovePost;
use App\Exceptions\Post\QueueBusyException;
use App\Http\Resources\Api\PostResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Post;
use App\Support\PostApproval;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Approve a post that is waiting for approval (status pending_approval). By default it is scheduled exactly as its author asked: added to the queue (next or top) or at the requested time. Pass scheduled_at (ISO 8601, in the future) to pick another time, or publish_now to publish immediately; one of them is required when the requested time has passed or the author asked to publish now. Only members who publish directly can approve.')]
class ApprovePostTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'post_id' => ['required', 'uuid'],
            ...PostApproval::rules(),
        ]);

        $workspace = $request->user()?->currentWorkspace;
        $post = $workspace
            ? Post::where('workspace_id', $workspace->id)->find(data_get($validated, 'post_id'))
            : null;

        if (! $post) {
            return Response::error('Post not found.');
        }

        if ($denied = $this->denyUnlessCan($request, 'approve', $post, 'Not authorized to approve this post.')) {
            return $denied;
        }

        try {
            $result = ApprovePost::execute($post, $request->user(), Arr::only($validated, ['publish_now', 'scheduled_at']));
        } catch (QueueBusyException) {
            return Response::error(__('posts.errors.queue_busy'));
        }

        /** @var Post $approved */
        $approved = data_get($result, 'post');
        $approved->load(['postPlatforms.socialAccount', 'labels']);

        return Response::structured((new PostResource($approved))->resolve());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->required()->description('UUID of the post waiting for approval.'),
            'scheduled_at' => $schema->string()->description('Optional ISO 8601 datetime in the future to schedule at instead of the requested time.'),
            'publish_now' => $schema->boolean()->description('Publish immediately instead of the requested time.'),
        ];
    }
}
