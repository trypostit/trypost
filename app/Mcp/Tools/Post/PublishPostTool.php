<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Post;

use App\Actions\Post\UpdatePost;
use App\Enums\Post\Action as PostAction;
use App\Enums\Post\QueuePosition;
use App\Enums\Post\Status;
use App\Exceptions\Post\QueueBusyException;
use App\Http\Resources\Api\PostResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Post;
use App\Support\PostStatusRules;
use App\Support\Requests\Post\PostRequestRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Publish a post now, schedule it at a custom time (scheduled_at) or put it in the channel queue (queue). Works on drafts and on scheduled posts. Use update-post-tool first to change its content, content_type or meta. It is validated like a scheduled post: text limits, required meta, the media rules of its content_type (see list-content-types-tool) and thread replies; a failure names the platform and field, and nothing is published. Posts that are publishing, published, partially_published or failed cannot be changed or deleted. When the acting member needs approval in this workspace, the post is stored with status pending_approval instead and waits for approve-post-tool. Before a post can be scheduled or published it needs: TikTok meta.privacy_level (get-tiktok-creator-info-tool), Pinterest meta.board_id (list-pinterest-boards-tool), Discord meta.channel_id (list-discord-channels-tool), Google Business events and offers meta.event (title and dates), YouTube a title (meta.title, or the first line of the text); list-content-types-tool lists them per platform as required_meta. A scheduled or published post also needs text or media.')]
class PublishPostTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate(PostRequestRules::publish(), PostRequestRules::messages());

        $workspace = $request->user()?->currentWorkspace;
        $post = $workspace
            ? Post::where('workspace_id', $workspace->id)->find(data_get($validated, 'post_id'))
            : null;

        if (! $post) {
            return Response::error('Post not found.');
        }

        if ($denied = $this->denyUnlessCan($request, 'update', $post, 'Post not found.')) {
            return $denied;
        }

        if (! $post->postPlatforms()->enabled()->exists()) {
            return Response::error(__('posts.errors.no_social_account'));
        }

        PostStatusRules::assertStoredPostPublishable($post);

        $scheduledAt = data_get($validated, 'scheduled_at');

        $queue = data_get($validated, 'queue');

        try {
            $result = UpdatePost::execute($workspace, $post, $queue ? [
                'status' => Status::Scheduled->value,
                'queue' => $queue,
            ] : [
                'status' => $scheduledAt ? Status::Scheduled->value : Status::Publishing->value,
                'scheduled_at' => $scheduledAt,
            ], $request->user());
        } catch (QueueBusyException) {
            return Response::error(__('posts.errors.queue_busy'));
        }

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
            'queue' => $schema->string()->enum(array_column(QueuePosition::cases(), 'value'))->description(PostStatusRules::QUEUE_DESCRIPTION),
            'scheduled_at' => $schema->string()->description('ISO 8601 datetime in the future and before 2038-01-19, e.g. 2026-05-10T15:30:00Z; without an offset it is read as UTC. Times in responses are UTC (Y-m-d H:i:s). If provided, the post is scheduled at that custom time. If omitted, publishing starts immediately.'),
        ];
    }
}
