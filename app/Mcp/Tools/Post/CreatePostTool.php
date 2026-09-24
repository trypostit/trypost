<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Post;

use App\Actions\Post\CreatePosts;
use App\Enums\Post\CreatedVia;
use App\Enums\PostPlatform\ContentType;
use App\Http\Resources\Api\PostResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Workspace;
use App\Rules\ContentTypeMatchesPlatform;
use App\Support\PostMediaRules;
use App\Support\PostPlatformMetaRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create one post for one social account in the current workspace. Use create-posts-tool to create a batch. Use list-content-types-tool to discover valid content_types.')]
class CreatePostTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace(
            $request,
            'createPost',
            'Not authorized to create posts.',
        );

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $validated = $request->validate(
            [
                'content' => ['nullable', 'string', 'max:10000'],
                ...PostMediaRules::rules(hosted: true),
                'scheduled_at' => ['nullable', 'date', 'after:now', 'before:2038-01-19'],
                'label_ids' => ['sometimes', 'array'],
                'label_ids.*' => ['uuid', Rule::exists('workspace_labels', 'id')->where('workspace_id', $workspace->id)],
                'platforms' => ['required', 'array', 'size:1'],
                'platforms.*.social_account_id' => [
                    'required',
                    'uuid',
                    Rule::exists('social_accounts', 'id')
                        ->where('workspace_id', $workspace->id)
                        ->where('is_active', true),
                ],
                'platforms.*.content_type' => ['required', 'string', Rule::in(array_column(ContentType::cases(), 'value')), new ContentTypeMatchesPlatform],
                ...PostPlatformMetaRules::rules(),
            ],
            PostPlatformMetaRules::messages(),
            PostPlatformMetaRules::attributes(),
        );

        $post = CreatePosts::execute($workspace, $request->user(), [
            'status' => 'draft',
            'content' => $validated['content'] ?? '',
            'media' => $validated['media'] ?? [],
            'scheduled_at' => $validated['scheduled_at'] ?? null,
            'label_ids' => $validated['label_ids'] ?? [],
            'created_via' => CreatedVia::Mcp,
            'destinations' => [$validated['platforms'][0]],
        ])->sole();

        $post->load(['postPlatforms.socialAccount', 'labels']);

        return Response::structured((new PostResource($post))->resolve());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'content' => $schema->string()->description('The post caption/text body. Optional — can be edited later.'),
            'media' => $schema->array()->items($schema->object())->description('Hosted media snapshots from this workspace.'),
            'scheduled_at' => $schema->string()->description('Optional ISO 8601 datetime in the future (e.g. 2026-05-10T15:30:00Z). Omit it or pass null to create an unscheduled draft.'),
            'label_ids' => $schema->array()
                ->items($schema->string())
                ->description('Workspace label IDs to attach to the post.'),
            'platforms' => $schema->array()
                ->items($schema->object(fn ($p) => [
                    'social_account_id' => $p->string()->required()->description('UUID of the connected social account.'),
                    'content_type' => $p->string()->required()->description('Format for this platform (e.g. linkedin_post, x_post, instagram_feed).'),
                    'meta' => $p->object()->description('Per-platform metadata. Instagram/Facebook: aspect_ratio (1:1|4:5|16:9|original). TikTok: privacy_level PUBLIC_TO_EVERYONE|MUTUAL_FOLLOW_FRIENDS|FOLLOWER_OF_CREATOR|SELF_ONLY (required to publish) + flags (allow_comments, allow_duet, allow_stitch, disclose, brand_content_toggle, brand_organic_toggle, is_aigc, auto_add_music). SELF_ONLY cannot be combined with brand_content_toggle. Pinterest: board_id (required to publish — call ListPinterestBoardsTool first), title (≤100), link (destination URL). Pin description comes from the post content. Discord: channel_id (required to publish — call ListDiscordChannelsTool first), mentions ([{token,label}]), embeds ([{title,description,url,image,color}]).'),
                ]))
                ->required()
                ->description('Exactly one social account. Use create-posts-tool for multiple accounts.'),
        ];
    }
}
