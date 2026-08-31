<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Post;

use App\Actions\Post\CreatePost;
use App\Enums\Post\CreatedVia;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Http\Resources\Api\PostResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Rules\ContentTypeMatchesPlatform;
use App\Support\PostPlatformMetaRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create a draft post in the current workspace. Accepts content, scheduled_at, label_ids, and a list of platforms (social accounts to publish on, with their content_type). Use list-content-types-tool to discover valid content_types per platform.')]
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
                'scheduled_at' => ['nullable', 'date', 'after:now'],
                'label_ids' => ['sometimes', 'array'],
                'label_ids.*' => ['uuid', Rule::exists('workspace_labels', 'id')->where('workspace_id', $workspace->id)],
                'platforms' => ['sometimes', 'array'],
                'platforms.*.social_account_id' => [
                    'required',
                    'uuid',
                    Rule::exists('social_accounts', 'id')
                        ->where('workspace_id', $workspace->id)
                        ->where('is_active', true),
                ],
                'platforms.*.google_business_profile_location_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('google_business_profile_locations', 'id')],
                'platforms.*.content_type' => ['required', 'string', Rule::in(array_column(ContentType::cases(), 'value')), new ContentTypeMatchesPlatform],
                ...PostPlatformMetaRules::rules(),
            ],
            PostPlatformMetaRules::messages(),
            PostPlatformMetaRules::attributes(),
        );

        $validated['created_via'] = CreatedVia::Mcp;

        $accounts = SocialAccount::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', collect($validated['platforms'] ?? [])->pluck('social_account_id')->filter())
            ->get()
            ->keyBy('id');
        $locationErrors = [];
        foreach (($validated['platforms'] ?? []) as $index => $platform) {
            $account = $accounts->get(data_get($platform, 'social_account_id'));
            if ($account?->platform !== Platform::GoogleBusinessProfile) {
                if (filled(data_get($platform, 'google_business_profile_location_id'))) {
                    $locationErrors["platforms.{$index}.google_business_profile_location_id"] = 'A Google Business Profile location can only be used with a Google Business Profile connection.';
                }

                continue;
            }

            $locationId = data_get($platform, 'google_business_profile_location_id');
            if (blank($locationId) || ! $account->googleBusinessProfileLocations()->whereKey($locationId)->where('is_selected', true)->exists()) {
                $locationErrors["platforms.{$index}.google_business_profile_location_id"] = 'Choose a selected Google Business Profile location managed by this connection.';
            }
        }
        if ($locationErrors !== []) {
            throw ValidationException::withMessages($locationErrors);
        }

        PostPlatformMetaRules::assertGoogleBusinessProfilePayloads(
            $validated['platforms'] ?? [],
            fn ($platform) => ContentType::tryFrom((string) data_get($platform, 'content_type')),
        );

        $post = CreatePost::execute($workspace, $request->user(), $validated);

        $post->load(['postPlatforms.socialAccount', 'labels']);

        return Response::structured((new PostResource($post))->resolve());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'content' => $schema->string()->description('The post caption/text body. Optional — can be edited later.'),
            'scheduled_at' => $schema->string()->description('Optional ISO 8601 datetime in the future (e.g. 2026-05-10T15:30:00Z). Omit it or pass null to create an unscheduled draft.'),
            'label_ids' => $schema->array()
                ->items($schema->string())
                ->description('Workspace label IDs to attach to the post.'),
            'platforms' => $schema->array()
                ->items($schema->object(fn ($p) => [
                    'social_account_id' => $p->string()->required()->description('UUID of the connected social account.'),
                    'google_business_profile_location_id' => $p->string()->description('Required for Google Business Profile: UUID of the selected managed location returned by the social-account listing.'),
                    'content_type' => $p->string()->required()->description('Format for this platform (e.g. linkedin_post, x_post, instagram_feed).'),
                    'meta' => $p->object()->description('Per-platform metadata. Google Business Profile: CTA fields (cta_action_type, cta_url); Event/Offer require event_title, event_start_at, event_end_at; Offer supports offer_coupon_code, offer_redeem_url, offer_terms; Alert requires alert_type=COVID_19; recurrence supports daily, weekly, or monthly fields. Instagram/Facebook: aspect_ratio (1:1|4:5|16:9|original). TikTok: privacy_level (required to publish) + flags (allow_comments, allow_duet, allow_stitch, disclose, brand_content_toggle, brand_organic_toggle, is_aigc, auto_add_music). Pinterest: board_id (required to publish — call ListPinterestBoardsTool first), title (≤100), link (destination URL). Pin description comes from the post content. Discord: channel_id (required to publish — call ListDiscordChannelsTool first), mentions ([{token,label}]), embeds ([{title,description,url,image,color}]).'),
                ]))
                ->description('Platforms to publish on. Accounts not listed remain available but disabled.'),
        ];
    }
}
