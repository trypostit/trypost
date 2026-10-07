<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Post;

use App\Actions\Post\HostInlineMedia;
use App\Actions\Post\UpdatePost;
use App\Enums\Post\Action as PostAction;
use App\Enums\Post\QueuePosition;
use App\Enums\Post\Status;
use App\Exceptions\Post\QueueBusyException;
use App\Http\Resources\Api\PostResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Mcp\Concerns\DescribesPostMedia;
use App\Models\Post;
use App\Models\Workspace;
use App\Support\PostPlatformMetaRules;
use App\Support\PostStatusRules;
use App\Support\Requests\Post\PostRequestRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidatorContract;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Update one post for its existing social account. Caption, media, content_type, meta (platform settings, merged with the stored ones), status, schedule, queue and labels may change; content_type and meta are top-level fields because a post has exactly one social account, which is fixed. When the acting member needs approval in this workspace, a scheduled, queued or publish-now update is stored with status pending_approval instead. meta is merged with the stored settings: send null for a key to clear it; thread_replies replaces the whole list. To place the post in one specific free slot use move-post-to-slot-tool. Posts that are publishing, published, partially_published or failed cannot be changed or deleted. To publish a thread on X, Bluesky or Mastodon, put the follow-up posts in meta.thread_replies: up to 24 replies published under the post, each {text, media} with up to 4 media items given by url, id or upload_token, e.g. [{"text": "2/ ..."}, {"text": "3/ ...", "media": [{"url": "https://..."}]}]. Each reply must fit the text limit of the account and follows the media rules of a post on that network. Before a post can be scheduled or published it needs: TikTok meta.privacy_level (get-tiktok-creator-info-tool), Pinterest meta.board_id (list-pinterest-boards-tool), Discord meta.channel_id (list-discord-channels-tool), Google Business events and offers meta.event (title and dates), YouTube a title (meta.title, or the first line of the text); list-content-types-tool lists them per platform as required_meta. A scheduled or published post also needs text or media.')]
class UpdatePostTool extends Tool
{
    use AuthorizesMcpTool;
    use DescribesPostMedia;

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate(['post_id' => ['required', 'uuid']]);

        $workspace = $request->user()?->currentWorkspace;
        $post = $workspace instanceof Workspace
            ? Post::where('workspace_id', $workspace->id)->find(data_get($request->all(), 'post_id'))
            : null;

        if (! $post) {
            return Response::error('Post not found.');
        }

        if ($denied = $this->denyUnlessCan($request, 'update', $post, 'Post not found.')) {
            return $denied;
        }

        $input = PostRequestRules::updateInput($post, $request->all());

        $validated = Validator::make($input, PostRequestRules::update($workspace, $post, $input), PostRequestRules::messages(), PostRequestRules::attributes())
            ->after(fn (ValidatorContract $validator) => PostRequestRules::afterUpdate($validator, $post, $input))
            ->validate();

        $validated = HostInlineMedia::forPost($workspace, $post->allowedMediaTypes(), $validated);

        try {
            $result = UpdatePost::execute($workspace, $post, $validated, $request->user());
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
            'post_id' => $schema->string()->required()->description('UUID of the post to update.'),
            'content' => $schema->string()->description('The post text, in plain text; use \n for line breaks. When link defusing is on, an X post publishes its links non-clickable (example(.)com); preview-post-tool shows the exact text each network gets. The text must fit the account limit (max_content_length in list-social-accounts-tool; on X, 25000 for an account with long_posts, else 280), Instagram takes at most 5 hashtags, and stories publish no text.'),
            'media' => $this->mediaSchema($schema, 'Replaces the post media; omit to keep the current media.'),
            'scheduled_at' => $schema->string()->description('ISO 8601 datetime in the future and before 2038-01-19, e.g. 2026-05-10T15:30:00Z; without an offset it is read as UTC. Times in responses are UTC (Y-m-d H:i:s). Required for status scheduled unless the post already has a future schedule or queue is sent.'),
            'queue' => $schema->string()->enum(array_column(QueuePosition::cases(), 'value'))->description(PostStatusRules::QUEUE_DESCRIPTION),
            'status' => $schema->string()
                ->enum([Status::Draft->value, Status::Scheduled->value, Status::Publishing->value])
                ->description('Post status. Omit it to keep the current one. Use "draft" to keep editing (a scheduled post is unscheduled), "scheduled" to schedule the post at scheduled_at or in the queue, "publishing" to publish it now.'),
            'label_ids' => $schema->array()
                ->items($schema->string())
                ->description('Workspace label IDs to attach (replaces existing labels).'),
            'content_type' => $schema->string()->description('New format for the post’s existing social account. Omitted, the stored type is kept, except on pinterest and tiktok, where new media re-decides it as on create (video -> pinterest_video_pin / tiktok_video, several images -> pinterest_carousel, images only -> tiktok_photo).'),
            'meta' => $schema->object()->description('Settings for the existing account, merged with stored settings. '.PostPlatformMetaRules::documentation()),
        ];
    }
}
