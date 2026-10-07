<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Post;

use App\Actions\Post\CreatePosts;
use App\Actions\Post\HostInlineMedia;
use App\Enums\Post\CreatedVia;
use App\Enums\Post\QueuePosition;
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
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create one independent post per destination in the current workspace, as the web composer does when several channels are selected: each destination becomes its own post on its own channel, and the posts share a post_group_id. Each destination may override the shared content, media, content_type and meta. status draft keeps them editable, scheduled schedules them at scheduled_at or in each channel queue (queue: next or top), publishing publishes them now; one specific slot (queue_slot) is not taken here, use create-post-tool or move-post-to-slot-tool for that. When the acting member needs approval in this workspace, scheduled, queued and publish-now posts are stored with status pending_approval and wait for approve-post-tool. To publish a thread on X, Bluesky or Mastodon, put the follow-up posts in meta.thread_replies: up to 24 replies published under the post, each {text, media} with up to 4 media items given by url, id or upload_token, e.g. [{"text": "2/ ..."}, {"text": "3/ ...", "media": [{"url": "https://..."}]}]. Each reply must fit the text limit of the account and follows the media rules of a post on that network. Before a post can be scheduled or published it needs: TikTok meta.privacy_level (get-tiktok-creator-info-tool), Pinterest meta.board_id (list-pinterest-boards-tool), Discord meta.channel_id (list-discord-channels-tool), Google Business events and offers meta.event (title and dates), YouTube a title (meta.title, or the first line of the text); list-content-types-tool lists them per platform as required_meta. A scheduled or published post also needs text or media.')]
class CreatePostsTool extends Tool
{
    use AuthorizesMcpTool;
    use DescribesPostMedia;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'createPost');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $validated = $request->validate(PostRequestRules::batch($workspace), PostRequestRules::messages());

        $validated = HostInlineMedia::forBatch($workspace, $validated);

        $validated['created_via'] = CreatedVia::Mcp;

        try {
            $posts = CreatePosts::execute($workspace, $request->user(), $validated);
        } catch (QueueBusyException) {
            return Response::error(__('posts.errors.queue_busy'));
        }

        return Response::structured([
            'posts' => $posts->map(function (Post $post): array {
                $post->load(['postPlatforms.socialAccount', 'labels']);

                return (new PostResource($post))->resolve();
            })->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->enum(['draft', 'scheduled', 'publishing'])->required()->description('draft keeps the posts editable, scheduled schedules them at scheduled_at or in the queue, publishing publishes them now.'),
            'content' => $schema->string()->description('Shared text; each destination may override it. The post text: plain text or HTML (<p>, <br>, <strong>, <em>, <ul>/<ol>/<li>, <a>). Each network gets its own rendering (preview-post-tool shows it): LinkedIn turns bold into Unicode bold, Telegram keeps bold, italic, underline and links, and when link defusing is on an X post publishes its links non-clickable (example(.)com). Write a literal < or > as &lt; or &gt;, or it is read as a tag and removed. The text must fit the account limit (max_content_length in list-social-accounts-tool), Instagram takes at most 5 hashtags, and stories publish no text.'),
            'media' => $this->mediaSchema($schema, 'Media shared by every destination by default.'),
            'scheduled_at' => $schema->string()->description('ISO 8601 datetime in the future and before 2038-01-19, e.g. 2026-05-10T15:30:00Z; without an offset it is read as UTC. Times in responses are UTC (Y-m-d H:i:s). Required when status is scheduled without queue.'),
            'queue' => $schema->string()->enum(array_column(QueuePosition::cases(), 'value'))->description(PostStatusRules::QUEUE_DESCRIPTION),
            'label_ids' => $schema->array()->items($schema->string())->description('Workspace label IDs attached to every post.'),
            'destinations' => $schema->array()
                ->items($schema->object(fn ($destination) => [
                    'social_account_id' => $destination->string()->required()->description('UUID of the connected social account (list-social-accounts-tool).'),
                    'content_type' => $destination->string()->description('Format for this destination. Optional. Omitted, it is chosen as the web composer does: on pinterest a video makes pinterest_video_pin, several images pinterest_carousel, else pinterest_pin; on tiktok images only make tiktok_photo, else tiktok_video; every other network takes its default_content_type (list-content-types-tool). A type sent explicitly is validated against the media and refused when they do not match.'),
                    'content' => $destination->string()->description('Text override for this destination, with the same rules as the shared content.'),
                    'media' => $this->mediaSchema($schema, 'Media override for this destination; same item shape as the shared media.'),
                    'meta' => $destination->object()->description(PostPlatformMetaRules::documentation()),
                ]))
                ->required(),
        ];
    }
}
