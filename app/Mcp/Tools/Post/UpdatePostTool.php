<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Post;

use App\Actions\Post\UpdatePost;
use App\Enums\Post\Action as PostAction;
use App\Enums\Post\Status;
use App\Enums\PostPlatform\ContentType;
use App\Http\Resources\Api\PostResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Post;
use App\Models\Workspace;
use App\Rules\ContentTypeCompatibleWithMedia;
use App\Rules\ContentTypeMatchesPostPlatform;
use App\Support\PostPlatformMetaRules;
use App\Support\PostStatusRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Update a draft post — content, media, scheduled_at, labels, and which platforms are enabled. Cannot edit a post that has already been published.')]
class UpdatePostTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $request->user()?->currentWorkspace;
        $post = $workspace instanceof Workspace
            ? Post::where('workspace_id', $workspace->id)->find(data_get($request->all(), 'post_id'))
            : null;

        if (! $post) {
            return Response::error('Post not found.');
        }

        if ($denied = $this->denyUnlessCan($request, 'update', $post, 'Not authorized to update this post.')) {
            return $denied;
        }

        $status = data_get($request->all(), 'status');

        $validated = $request->validate(
            [
                'post_id' => ['required', 'uuid'],
                'content' => ['nullable', 'string', 'max:10000'],
                'scheduled_at' => PostStatusRules::scheduledAtRules($post, $status),
                'status' => ['sometimes', 'string', Rule::in([Status::Draft->value, Status::Scheduled->value])],
                'label_ids' => ['sometimes', 'array'],
                'label_ids.*' => ['uuid', Rule::exists('workspace_labels', 'id')->where('workspace_id', $workspace->id)],
                'platforms' => ['sometimes', 'array'],
                'platforms.*.id' => [
                    'required',
                    'uuid',
                    Rule::exists('post_platforms', 'id')->where('post_id', $post->id),
                ],
                'platforms.*.content_type' => [
                    'sometimes',
                    'string',
                    Rule::in(array_column(ContentType::cases(), 'value')),
                    new ContentTypeMatchesPostPlatform,
                ],
                ...PostPlatformMetaRules::rules(),
            ],
            PostPlatformMetaRules::messages(),
            PostPlatformMetaRules::attributes(),
        );

        // On schedule, validate each platform's effective content_type (resubmitted
        // here, or stored) against the post's stored media — the tool can't change
        // media, so a misconfigured post can't be scheduled even without resubmitting
        // content_type. Mirrors the public API's withValidator check.
        if ($status === Status::Scheduled->value) {
            $errors = ContentTypeCompatibleWithMedia::errorsFor(
                ContentTypeCompatibleWithMedia::entriesForUpdate($post, data_get($validated, 'platforms')),
                (array) ($post->media ?? []),
            );

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
        }

        $payload = collect($validated)->except('post_id')->all();

        $result = UpdatePost::execute($workspace, $post, $payload);

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
            'content' => $schema->string()->description('New caption/text body.'),
            'scheduled_at' => $schema->string()->description('Future ISO 8601 datetime. Required for status "scheduled" unless the post already has a future schedule.'),
            'status' => $schema->string()
                ->enum([Status::Draft->value, Status::Scheduled->value])
                ->description('Post status. Use "draft" to keep editing, "scheduled" to schedule the post. Use publish-post-tool for immediate publish.'),
            'label_ids' => $schema->array()
                ->items($schema->string())
                ->description('Workspace label IDs to attach (replaces existing labels).'),
            'platforms' => $schema->array()
                ->items($schema->object(fn ($p) => [
                    'id' => $p->string()->required()->description('UUID of the post_platform row (from get-post-tool / list-posts-tool).'),
                    'content_type' => $p->string()->description('New content_type for this platform.'),
                    'meta' => $p->object()->description('Per-platform metadata override. Instagram and Instagram via Facebook: aspect_ratio; collaborators (["username", ...], up to 3 — Reels/feed/carousel only, not Stories; invited accounts must accept). Facebook Page: aspect_ratio. TikTok: privacy_level (required to publish) + flags. Pinterest: board_id (required to publish — call ListPinterestBoardsTool first), title (≤100), link (destination URL). Pin description comes from the post content. Discord: channel_id (required to publish — call ListDiscordChannelsTool first), mentions, embeds. Merged with existing meta.'),
                ]))
                ->description('Platforms to enable for publishing. Any platform NOT listed will be disabled. Pass an empty array to disable all.'),
        ];
    }
}
