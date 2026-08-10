<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Post;

use App\Actions\Post\UpdatePost;
use App\Enums\Post\Action as PostAction;
use App\Enums\Post\Status;
use App\Http\Resources\Api\PostResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Post;
use App\Rules\ContentTypeCompatibleWithMedia;
use App\Support\PostPlatformMetaRules;
use App\Support\PostStatusRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Publish a draft post — either immediately or scheduled for a future time. The post must already have at least one enabled platform. Use update-post-tool first to set content/platforms.')]
class PublishPostTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'post_id' => ['required', 'uuid'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
        ]);

        $workspace = $request->user()?->currentWorkspace;
        $post = $workspace
            ? Post::where('workspace_id', $workspace->id)->find(data_get($validated, 'post_id'))
            : null;

        if (! $post) {
            return Response::error('Post not found.');
        }

        if ($denied = $this->denyUnlessCan($request, 'update', $post, 'Not authorized to publish this post.')) {
            return $denied;
        }

        if (! $post->postPlatforms()->enabled()->exists()) {
            return Response::error('Post has no enabled platforms. Use update-post-tool to enable at least one platform first.');
        }

        PostPlatformMetaRules::assertStoredPostPublishable($post);
        ContentTypeCompatibleWithMedia::assertStoredPostCompatible($post);

        $scheduledAt = data_get($validated, 'scheduled_at');

        $result = UpdatePost::execute($workspace, $post, [
            'status' => $scheduledAt ? Status::Scheduled->value : Status::Publishing->value,
            'scheduled_at' => $scheduledAt,
        ]);

        if (data_get($result, 'action') === PostAction::Finalized) {
            return Response::error(PostStatusRules::editBlockedMessage());
        }

        /** @var Post $updated */
        $updated = data_get($result, 'post');
        $updated->load(['postPlatforms.socialAccount', 'labels']);

        return Response::structured((new PostResource($updated))->resolve());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->required()->description('UUID of the post to publish.'),
            'scheduled_at' => $schema->string()->description('ISO 8601 datetime in the future. If provided, the post is queued for that time. If omitted, publishing starts immediately.'),
        ];
    }
}
