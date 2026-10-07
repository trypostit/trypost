<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Post;

use App\Actions\Post\CreatePosts;
use App\Actions\Post\HostInlineMedia;
use App\Enums\Post\CreatedVia;
use App\Enums\Post\QueuePosition;
use App\Enums\Post\Status;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Post\QueueBusyException;
use App\Http\Resources\Api\PostResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Mcp\Concerns\DescribesPostMedia;
use App\Models\Post;
use App\Models\SocialAccount;
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

#[Description('Create one post for one social account in the current workspace: a draft, a post scheduled at a custom time, a post queued in the channel\'s next free slot (queue: next) or first slot (queue: top), a queue post in one specific free slot (queue_slot, an instant from list-free-slots-tool), or a post published now. When the acting member needs approval in this workspace, a scheduled, queued or publish-now post is stored with status pending_approval instead and waits for approve-post-tool. Use create-posts-tool to create a batch. Use list-content-types-tool to discover valid content_types. To publish a thread on X, Bluesky or Mastodon, put the follow-up posts in meta.thread_replies: up to 24 replies published under the post, each {text, media} with up to 4 media items given by url, id or upload_token, e.g. [{"text": "2/ ..."}, {"text": "3/ ...", "media": [{"url": "https://..."}]}]. Each reply must fit the text limit of the account and follows the media rules of a post on that network. Before a post can be scheduled or published it needs: TikTok meta.privacy_level (get-tiktok-creator-info-tool), Pinterest meta.board_id (list-pinterest-boards-tool), Discord meta.channel_id (list-discord-channels-tool), Google Business events and offers meta.event (title and dates), YouTube a title (meta.title, or the first line of the text); list-content-types-tool lists them per platform as required_meta. A scheduled or published post also needs text or media.')]
class CreatePostTool extends Tool
{
    use AuthorizesMcpTool;
    use DescribesPostMedia;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'createPost');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $validated = $request->validate(
            PostRequestRules::store($workspace, $request->all()),
            PostRequestRules::messages(),
            PostRequestRules::attributes(),
        );

        $platforms = PostRequestRules::selectedAccounts($workspace, $validated)
            ->map(fn (SocialAccount $account): Platform => $account->platform)
            ->values();

        $validated = HostInlineMedia::forPost($workspace, Post::allowedMediaTypesFor($platforms), $validated);

        try {
            $post = CreatePosts::execute($workspace, $request->user(), [
                'status' => $validated['status'] ?? Status::Draft->value,
                'content' => $validated['content'] ?? '',
                'media' => $validated['media'] ?? [],
                'scheduled_at' => $validated['scheduled_at'] ?? $validated['queue_slot'] ?? null,
                'queue' => $validated['queue'] ?? null,
                'queue_slot' => $validated['queue_slot'] ?? null,
                'label_ids' => $validated['label_ids'] ?? [],
                'created_via' => CreatedVia::Mcp,
                'destinations' => [$validated['platforms'][0]],
            ])->sole();
        } catch (QueueBusyException) {
            return Response::error(__('posts.errors.queue_busy'));
        }

        $post->load(['postPlatforms.socialAccount', 'labels']);

        return Response::structured((new PostResource($post))->resolve());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'content' => $schema->string()->description('The post text: plain text or HTML (<p>, <br>, <strong>, <em>, <ul>/<ol>/<li>, <a>). Each network gets its own rendering (preview-post-tool shows it): LinkedIn turns bold into Unicode bold, Telegram keeps bold, italic, underline and links, and when link defusing is on an X post publishes its links non-clickable (example(.)com). Write a literal < or > as &lt; or &gt;, or it is read as a tag and removed. The text must fit the account limit (max_content_length in list-social-accounts-tool), Instagram takes at most 5 hashtags, and stories publish no text.'),
            'media' => $this->mediaSchema($schema, 'Media for the post.'),
            'status' => $schema->string()
                ->enum([Status::Draft->value, Status::Scheduled->value, Status::Publishing->value])
                ->description('draft (default) keeps the post editable, scheduled schedules it at scheduled_at or in the queue, publishing publishes it now.'),
            'scheduled_at' => $schema->string()->description('ISO 8601 datetime in the future and before 2038-01-19, e.g. 2026-05-10T15:30:00Z; without an offset it is read as UTC. Times in responses are UTC (Y-m-d H:i:s). Required when status is scheduled without queue or queue_slot.'),
            'queue' => $schema->string()->enum(array_column(QueuePosition::cases(), 'value'))->description(PostStatusRules::QUEUE_DESCRIPTION),
            'queue_slot' => $schema->string()->description(PostStatusRules::QUEUE_SLOT_DESCRIPTION),
            'label_ids' => $schema->array()
                ->items($schema->string())
                ->description('Workspace label IDs to attach to the post.'),
            'platforms' => $schema->array()
                ->items($schema->object(fn ($p) => [
                    'social_account_id' => $p->string()->required()->description('UUID of the connected social account.'),
                    'content_type' => $p->string()->description('Format for this platform (e.g. linkedin_post, x_post, instagram_feed). Optional. Omitted, it is chosen as the web composer does: on pinterest a video makes pinterest_video_pin, several images pinterest_carousel, else pinterest_pin; on tiktok images only make tiktok_photo, else tiktok_video; every other network takes its default_content_type (list-content-types-tool). A type sent explicitly is validated against the media and refused when they do not match.'),
                    'meta' => $p->object()->description(PostPlatformMetaRules::documentation()),
                ]))
                ->required()
                ->description('Exactly one social account. Use create-posts-tool for multiple accounts.'),
        ];
    }
}
