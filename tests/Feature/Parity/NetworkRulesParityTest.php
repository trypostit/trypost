<?php

declare(strict_types=1);

use App\Enums\Post\Status;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\CreatePostsTool;
use App\Mcp\Tools\Post\CreatePostTool;
use App\Mcp\Tools\Post\UpdatePostTool;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Support\PostPlatformMetaRules;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

beforeEach(function () {
    Storage::fake();
    ['user' => $this->user, 'workspace' => $this->workspace, 'token' => $this->token] = parityContext();
});

function netParityAccount(Platform $platform, array $meta = []): SocialAccount
{
    return SocialAccount::factory()->create([
        'workspace_id' => test()->workspace->id,
        'platform' => $platform,
        'meta' => $meta,
    ]);
}

function netParityImage(int $width = 1080, int $height = 1350): array
{
    $media = Media::factory()->stored()->temporaryUpload(test()->workspace)->create(['meta' => ['width' => $width, 'height' => $height]]);

    return ['id' => $media->id];
}

function netParityVideo(): array
{
    $media = Media::factory()->video()->stored()->temporaryUpload(test()->workspace)->create(['meta' => ['duration' => 30]]);

    return ['id' => $media->id];
}

function netParityDocument(): array
{
    $media = Media::factory()->document()->stored()->temporaryUpload(test()->workspace)->create();

    return ['id' => $media->id];
}

function netParityPayload(SocialAccount $account, ContentType $type, array $overrides = [], array $meta = []): array
{
    $destination = ['social_account_id' => $account->id, 'content_type' => $type->value];

    if ($meta !== []) {
        $destination['meta'] = $meta;
    }

    return [
        'status' => 'scheduled',
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'content' => 'Network rule',
        'destinations' => [$destination],
        ...$overrides,
    ];
}

function netParityApi(): TestCase
{
    auth()->forgetGuards();

    return test()->withHeaders(parityApi(test()->token));
}

function netParityPostCount(): int
{
    return Post::query()->where('workspace_id', test()->workspace->id)->count();
}

function netParityRefused(array $payload, string $message, ?string $apiKey = null): void
{
    $response = netParityApi()->postJson(route('api.posts.batch.store'), $payload)->assertUnprocessable();

    expect(collect($response->json('errors'))->flatten()->implode(' '))->toContain($message);

    if ($apiKey !== null) {
        $response->assertJsonValidationErrors([$apiKey]);
    }

    TryPostServer::actingAs(test()->user)->tool(CreatePostsTool::class, $payload)->assertHasErrors([$message]);

    expect(netParityPostCount())->toBe(0);
}

/**
 * @return array{0: Post, 1: Post}
 */
function netParityAccepted(array $apiPayload, ?array $mcpPayload = null): array
{
    $existing = Post::query()->where('workspace_id', test()->workspace->id)->pluck('id');

    netParityApi()->postJson(route('api.posts.batch.store'), $apiPayload)->assertCreated();
    TryPostServer::actingAs(test()->user)->tool(CreatePostsTool::class, $mcpPayload ?? $apiPayload)->assertOk();

    $posts = Post::query()->where('workspace_id', test()->workspace->id)->whereNotIn('id', $existing)->oldest()->get()->values();

    expect($posts)->toHaveCount(2)
        ->and($posts[0]->status)->toBe($posts[1]->status)
        ->and($posts[0]->content_type)->toBe($posts[1]->content_type)
        ->and($posts[0]->meta)->toEqual($posts[1]->meta);

    return [$posts[0], $posts[1]];
}

test('a scheduled pinterest pin without a board is refused by api and mcp and accepted with one', function () {
    $account = netParityAccount(Platform::Pinterest);
    $payload = fn (array $meta) => netParityPayload($account, ContentType::PinterestPin, ['media' => [netParityImage(1000, 1500)]], $meta);

    netParityRefused($payload([]), __('posts.form.pinterest.board_required'), 'destinations.0.meta.board_id');

    [$api] = netParityAccepted($payload(['board_id' => 'board-1']), $payload(['board_id' => 'board-1']));

    expect($api->meta['board_id'])->toBe('board-1');
});

test('a scheduled discord message without a channel is refused by api and mcp and accepted with one', function () {
    $account = netParityAccount(Platform::Discord);
    $payload = fn (array $meta) => netParityPayload($account, ContentType::DiscordMessage, [], $meta);

    netParityRefused($payload([]), __('posts.form.discord.channel_required'), 'destinations.0.meta.channel_id');

    [$api] = netParityAccepted($payload(['channel_id' => '123']), $payload(['channel_id' => '123']));

    expect($api->meta['channel_id'])->toBe('123');
});

test('tiktok privacy level is required to schedule and is refused with an unknown value by api and mcp', function () {
    $account = netParityAccount(Platform::TikTok);
    $payload = fn (array $meta) => netParityPayload($account, ContentType::TikTokVideo, ['media' => [netParityVideo()]], $meta);

    netParityRefused($payload([]), __('posts.form.tiktok.privacy_required'), 'destinations.0.meta.privacy_level');
    netParityRefused($payload(['privacy_level' => 'EVERYONE']), 'privacy_level', 'destinations.0.meta.privacy_level');
});

test('tiktok private posts cannot be branded content through api and mcp', function () {
    $account = netParityAccount(Platform::TikTok);
    $meta = ['privacy_level' => 'SELF_ONLY', 'brand_content_toggle' => true];

    netParityRefused(
        netParityPayload($account, ContentType::TikTokVideo, ['media' => [netParityVideo()]], $meta),
        __('posts.form.tiktok.privacy.private_disabled_branded'),
        'destinations.0.meta.privacy_level',
    );
});

test('tiktok flags and auto music are stored the same way through api and mcp', function () {
    $account = netParityAccount(Platform::TikTok);
    $meta = ['privacy_level' => 'PUBLIC_TO_EVERYONE', 'allow_comments' => true, 'allow_duet' => false, 'allow_stitch' => false, 'is_aigc' => true, 'auto_add_music' => true];
    $payload = fn () => netParityPayload($account, ContentType::TikTokVideo, ['media' => [netParityVideo()]], $meta);

    [$api] = netParityAccepted($payload(), $payload());

    expect($api->meta)->toEqual($meta);
});

test('tiktok photos need the photo content type and a video the video one, in api and mcp alike', function () {
    $account = netParityAccount(Platform::TikTok);
    $meta = ['privacy_level' => 'PUBLIC_TO_EVERYONE'];

    netParityRefused(
        netParityPayload($account, ContentType::TikTokVideo, ['media' => [netParityImage()]], $meta),
        __('posts.form.warnings.no_image_allowed'),
    );
    netParityRefused(
        netParityPayload($account, ContentType::TikTokPhoto, ['media' => [netParityVideo()]], $meta),
        __('posts.form.warnings.no_video_allowed'),
    );
});

test('tiktok photo carousels reject mixed media and more than 35 photos through api and mcp', function () {
    $account = netParityAccount(Platform::TikTok);
    $meta = ['privacy_level' => 'PUBLIC_TO_EVERYONE'];

    netParityRefused(
        netParityPayload($account, ContentType::TikTokPhoto, ['media' => [netParityImage(), netParityVideo()]], $meta),
        __('posts.form.warnings.no_mixed_media'),
    );

    $photos = fn (int $count) => collect(range(1, $count))->map(fn () => netParityImage())->all();

    netParityRefused(
        netParityPayload($account, ContentType::TikTokPhoto, ['media' => $photos(36)], $meta),
        __('posts.form.warnings.max_files_exceeded', ['max' => 35, 'current' => 36]),
    );

    [$api] = netParityAccepted(
        netParityPayload($account, ContentType::TikTokPhoto, ['media' => $photos(35)], $meta),
        netParityPayload($account, ContentType::TikTokPhoto, ['media' => $photos(35)], $meta),
    );

    expect($api->content_type)->toBe(ContentType::TikTokPhoto);
});

test('a destination without content_type gets the type the web composer would send, on every api and mcp create path', function (Platform $platform, Closure $media, ContentType $composer) {
    $account = netParityAccount($platform);
    $batch = fn (): array => ['status' => 'draft', 'content' => 'Derived', 'media' => $media(), 'destinations' => [['social_account_id' => $account->id]]];
    $single = fn (): array => ['status' => 'draft', 'content' => 'Derived', 'media' => $media(), 'social_account_id' => $account->id];

    netParityApi()->postJson(route('api.posts.batch.store'), $batch())->assertCreated();
    netParityApi()->postJson(route('api.posts.store'), $single())->assertCreated();
    TryPostServer::actingAs($this->user)->tool(CreatePostsTool::class, $batch())->assertOk();
    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, $single())->assertOk();

    $types = Post::query()->where('social_account_id', $account->id)->pluck('content_type');

    expect($types)->toHaveCount(4)
        ->and($types->unique()->all())->toBe([$composer]);
})->with([
    'tiktok photos' => [Platform::TikTok, fn () => [netParityImage(), netParityImage()], ContentType::TikTokPhoto],
    'tiktok video' => [Platform::TikTok, fn () => [netParityVideo()], ContentType::TikTokVideo],
    'tiktok without media' => [Platform::TikTok, fn () => [], ContentType::TikTokVideo],
    'pinterest image' => [Platform::Pinterest, fn () => [netParityImage(1000, 1500)], ContentType::PinterestPin],
    'pinterest images' => [Platform::Pinterest, fn () => [netParityImage(1000, 1500), netParityImage(1000, 1500)], ContentType::PinterestCarousel],
    'pinterest video' => [Platform::Pinterest, fn () => [netParityVideo()], ContentType::PinterestVideoPin],
    'instagram video' => [Platform::Instagram, fn () => [netParityVideo()], ContentType::InstagramFeed],
    'instagram via facebook image' => [Platform::InstagramFacebook, fn () => [netParityImage()], ContentType::InstagramFeed],
    'facebook image' => [Platform::Facebook, fn () => [netParityImage()], ContentType::FacebookPost],
    'youtube video' => [Platform::YouTube, fn () => [netParityVideo()], ContentType::YouTubeShort],
    'linkedin document' => [Platform::LinkedIn, fn () => [netParityDocument()], ContentType::LinkedInPost],
    'linkedin page image' => [Platform::LinkedInPage, fn () => [netParityImage()], ContentType::LinkedInPagePost],
    'threads text' => [Platform::Threads, fn () => [], ContentType::ThreadsPost],
    'x text' => [Platform::X, fn () => [], ContentType::XPost],
    'bluesky image' => [Platform::Bluesky, fn () => [netParityImage()], ContentType::BlueskyPost],
    'mastodon image' => [Platform::Mastodon, fn () => [netParityImage()], ContentType::MastodonPost],
    'telegram image' => [Platform::Telegram, fn () => [netParityImage()], ContentType::TelegramPost],
    'discord text' => [Platform::Discord, fn () => [], ContentType::DiscordMessage],
    'google business image' => [Platform::GoogleBusiness, fn () => [netParityImage()], ContentType::GoogleBusinessPost],
]);

test('a scheduled tiktok post without content_type is validated as the type its media decides through api and mcp', function () {
    $account = netParityAccount(Platform::TikTok);
    $meta = ['privacy_level' => 'PUBLIC_TO_EVERYONE'];
    $payload = function (array $media) use ($account, $meta): array {
        $payload = netParityPayload($account, ContentType::TikTokPhoto, ['media' => $media], $meta);
        unset($payload['destinations'][0]['content_type']);

        return $payload;
    };

    $photos = fn (int $count) => collect(range(1, $count))->map(fn () => netParityImage())->all();

    netParityRefused($payload($photos(36)), __('posts.form.warnings.max_files_exceeded', ['max' => 35, 'current' => 36]));

    [$api, $mcp] = netParityAccepted($payload($photos(2)), $payload($photos(2)));

    expect($api->content_type)->toBe(ContentType::TikTokPhoto)
        ->and($mcp->content_type)->toBe(ContentType::TikTokPhoto);
});

test('an explicit content_type that does not match the media is still refused with the media message by api and mcp', function () {
    $account = netParityAccount(Platform::Pinterest);

    netParityRefused(
        netParityPayload($account, ContentType::PinterestPin, ['media' => [netParityVideo()]], ['board_id' => 'board-1']),
        __('posts.form.warnings.no_video_allowed'),
        'destinations.0.content_type',
    );

    $tiktok = netParityAccount(Platform::TikTok);
    $payload = [
        'content' => 'Explicit',
        'status' => 'scheduled',
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'media' => [netParityImage()],
        'social_account_id' => $tiktok->id,
        'content_type' => ContentType::TikTokVideo->value,
        'meta' => ['privacy_level' => 'PUBLIC_TO_EVERYONE'],
    ];

    netParityApi()->postJson(route('api.posts.store'), $payload)
        ->assertUnprocessable()->assertJsonValidationErrors(['content_type' => __('posts.form.warnings.no_image_allowed')]);
    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, $payload)->assertHasErrors([__('posts.form.warnings.no_image_allowed')]);

    expect(netParityPostCount())->toBe(0);
});

test('new media without content_type re-decides a tiktok or pinterest post type on update through api and mcp, other networks keep theirs', function (Platform $platform, ContentType $stored, ContentType $expected, array $meta) {
    $account = netParityAccount($platform);
    $posts = collect(['api', 'mcp'])->mapWithKeys(function (string $surface) use ($account, $stored, $meta): array {
        return [$surface => Post::factory()->forAccount($account, $stored)->draft()->create(['user_id' => $this->user->id, 'meta' => $meta])];
    });
    $update = fn () => [
        'status' => 'scheduled',
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'content' => 'Now a video',
        'media' => [netParityVideo()],
    ];

    netParityApi()->putJson(route('api.posts.update', $posts['api']), $update())->assertOk();
    TryPostServer::actingAs($this->user)->tool(UpdatePostTool::class, ['post_id' => $posts['mcp']->id, ...$update()])->assertOk();

    expect($posts['api']->refresh()->content_type)->toBe($expected)
        ->and($posts['mcp']->refresh()->content_type)->toBe($expected);
})->with([
    'tiktok photo to video' => [Platform::TikTok, ContentType::TikTokPhoto, ContentType::TikTokVideo, ['privacy_level' => 'PUBLIC_TO_EVERYONE']],
    'pinterest pin to video pin' => [Platform::Pinterest, ContentType::PinterestPin, ContentType::PinterestVideoPin, ['board_id' => 'board-1']],
    'instagram reel stays a reel' => [Platform::Instagram, ContentType::InstagramReel, ContentType::InstagramReel, []],
]);

test('scheduling a youtube short with no text and no title is refused by api and mcp', function () {
    $account = netParityAccount(Platform::YouTube);
    $payload = fn (array $meta) => netParityPayload($account, ContentType::YouTubeShort, ['content' => '', 'media' => [netParityVideo()]], $meta);

    netParityRefused($payload([]), __('posts.form.youtube.title_required'), 'destinations.0.meta.title');

    [$api] = netParityAccepted($payload(['title' => 'A title']), $payload(['title' => 'A title']));

    expect($api->meta['title'])->toBe('A title');
});

test('a youtube title with angle brackets is refused even as a draft by api and mcp', function () {
    $account = netParityAccount(Platform::YouTube);
    $payload = netParityPayload($account, ContentType::YouTubeShort, ['status' => 'draft', 'scheduled_at' => null], ['title' => 'Bad <title>']);

    netParityRefused($payload, __('posts.form.youtube.title_invalid'), 'destinations.0.meta.title');
});

test('a youtube title with angle brackets is refused on the single create path by api and mcp', function () {
    $account = netParityAccount(Platform::YouTube);
    $payload = [
        'content' => 'Text',
        'social_account_id' => $account->id, 'content_type' => ContentType::YouTubeShort->value, 'meta' => ['title' => 'Bad <title>'],
    ];

    netParityApi()->postJson(route('api.posts.store'), $payload)
        ->assertUnprocessable()->assertJsonValidationErrors(['meta.title' => __('posts.form.youtube.title_invalid')]);
    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, $payload)->assertHasErrors([__('posts.form.youtube.title_invalid')]);

    expect(netParityPostCount())->toBe(0);
});

test('instagram allows five hashtags when scheduling and refuses six through api and mcp, drafts keep six', function () {
    $account = netParityAccount(Platform::Instagram);
    $hashtags = fn (int $count) => 'Caption '.collect(range(1, $count))->map(fn (int $number) => "#tag{$number}")->implode(' ');
    $payload = fn (int $count, string $status = 'scheduled') => netParityPayload($account, ContentType::InstagramFeed, [
        'content' => $hashtags($count),
        'media' => [netParityImage()],
        'status' => $status,
        'scheduled_at' => $status === 'scheduled' ? now()->addDay()->toIso8601String() : null,
    ]);

    netParityRefused($payload(6), __('posts.form.hashtags_exceed_platform', ['platform' => Platform::Instagram->label(), 'limit' => 5]), 'destinations.0.content');

    netParityAccepted($payload(5), $payload(5));
    netParityAccepted($payload(6, 'draft'), $payload(6, 'draft'));
});

test('instagram feed enforces the 3:4 to 1.91:1 ratio on scheduled posts through api and mcp', function (int $width, int $height) {
    $account = netParityAccount(Platform::Instagram);
    $message = $width / $height > 1 ? 'aspect_ratio_too_wide' : 'aspect_ratio_too_narrow';
    $payload = netParityPayload($account, ContentType::InstagramFeed, ['media' => [netParityImage($width, $height)]]);

    $response = netParityApi()->postJson(route('api.posts.batch.store'), $payload)->assertUnprocessable();
    $api = collect($response->json('errors'))->flatten()->implode(' ');

    expect($api)->toContain(trans("posts.form.warnings.{$message}", ['destination' => ContentType::InstagramFeed->destinationLabel(), 'current' => number_format($width / $height, 2, '.', ''), 'min' => '0.75', 'max' => '1.91']));

    TryPostServer::actingAs($this->user)->tool(CreatePostsTool::class, $payload)->assertHasErrors();

    expect(netParityPostCount())->toBe(0);
})->with([
    'too tall' => [500, 1000],
    'too wide' => [2500, 1000],
]);

test('instagram feed enforces the ratio on media referenced by upload_token through api and mcp', function () {
    $account = netParityAccount(Platform::Instagram);
    $media = Media::factory()->stored()->temporaryUpload($this->workspace)->create(['meta' => ['width' => 500, 'height' => 1000]]);
    $payload = netParityPayload($account, ContentType::InstagramFeed, ['media' => [['upload_token' => $media->upload_token]]]);

    netParityRefused($payload, trans('posts.form.warnings.aspect_ratio_too_narrow', ['destination' => ContentType::InstagramFeed->destinationLabel(), 'current' => '0.50', 'min' => '0.75', 'max' => '1.91']));
});

test('instagram feed refuses a batch mixing an upload_token item and an id item when either breaks the ratio', function (bool $tokenBreaks) {
    $account = netParityAccount(Platform::Instagram);
    $tokenMedia = Media::factory()->stored()->temporaryUpload($this->workspace)->create(['meta' => $tokenBreaks ? ['width' => 500, 'height' => 1000] : ['width' => 1080, 'height' => 1350]]);
    $idItem = $tokenBreaks ? netParityImage(1080, 1350) : netParityImage(2500, 1000);
    $payload = netParityPayload($account, ContentType::InstagramFeed, ['media' => [['upload_token' => $tokenMedia->upload_token], $idItem]]);
    $message = $tokenBreaks ? 'aspect_ratio_too_narrow' : 'aspect_ratio_too_wide';

    netParityRefused($payload, trans("posts.form.warnings.{$message}", ['destination' => ContentType::InstagramFeed->destinationLabel(), 'current' => $tokenBreaks ? '0.50' : '2.50', 'min' => '0.75', 'max' => '1.91']));
})->with(['token item breaks' => [true], 'id item breaks' => [false]]);

test('instagram feed accepts the boundary ratios through api and mcp', function (int $width, int $height) {
    $account = netParityAccount(Platform::Instagram);
    $payload = fn () => netParityPayload($account, ContentType::InstagramFeed, ['media' => [netParityImage($width, $height)]]);

    netParityAccepted($payload(), $payload());
})->with([
    'portrait 3:4' => [1080, 1440],
    'landscape 1.91:1' => [1910, 1000],
]);

test('facebook and instagram stories accept a photo through api and mcp', function (Platform $platform, ContentType $type) {
    $account = netParityAccount($platform);
    $payload = fn () => netParityPayload($account, $type, ['media' => [netParityImage(1920, 1080)]]);

    [$api] = netParityAccepted($payload(), $payload());

    expect($api->content_type)->toBe($type);
})->with([
    'facebook' => [Platform::Facebook, ContentType::FacebookStory],
    'instagram' => [Platform::Instagram, ContentType::InstagramStory],
]);

test('a story skips caption length and hashtag limits that a feed post enforces, in api and mcp alike', function () {
    $account = netParityAccount(Platform::Instagram);
    $long = str_repeat('a', 2300);
    $tagged = 'Caption '.collect(range(1, 8))->map(fn (int $number) => "#tag{$number}")->implode(' ');

    netParityRefused(
        netParityPayload($account, ContentType::InstagramFeed, ['content' => $long, 'media' => [netParityImage()]]),
        __('posts.form.content_exceeds_platform', ['platform' => Platform::Instagram->label(), 'limit' => 2200, 'over' => 100]),
    );
    netParityRefused(
        netParityPayload($account, ContentType::InstagramFeed, ['content' => $tagged, 'media' => [netParityImage()]]),
        __('posts.form.hashtags_exceed_platform', ['platform' => Platform::Instagram->label(), 'limit' => 5]),
    );

    $payload = fn () => netParityPayload($account, ContentType::InstagramStory, ['content' => "{$long} {$tagged}", 'media' => [netParityImage(1080, 1920)]]);

    netParityAccepted($payload(), $payload());
});

test('an x account without long posts is capped at 280 and one with a premium subscription at 25000 through api and mcp', function () {
    $standard = netParityAccount(Platform::X);
    $premium = netParityAccount(Platform::X, ['x_subscription_type' => 'Premium']);
    $content = str_repeat('a', 281);

    netParityRefused(
        netParityPayload($standard, ContentType::XPost, ['content' => $content]),
        __('posts.form.content_exceeds_platform', ['platform' => Platform::X->label(), 'limit' => 280, 'over' => 1]),
        'destinations.0.content',
    );

    $payload = fn () => netParityPayload($premium, ContentType::XPost, ['content' => $content]);

    [$api] = netParityAccepted($payload(), $payload());

    expect($api->content)->toBe($content);
});

test('the mastodon content warning counts toward the 500 character limit through api and mcp', function () {
    $account = netParityAccount(Platform::Mastodon);

    netParityRefused(
        netParityPayload($account, ContentType::MastodonPost, ['content' => str_repeat('a', 499)], ['spoiler_text' => 'cw']),
        __('posts.form.content_exceeds_platform', ['platform' => Platform::Mastodon->label(), 'limit' => 500, 'over' => 1]),
        'destinations.0.content',
    );

    $payload = fn () => netParityPayload($account, ContentType::MastodonPost, ['content' => str_repeat('a', 498)], ['spoiler_text' => 'cw']);

    [$api] = netParityAccepted($payload(), $payload());

    expect($api->meta['spoiler_text'])->toBe('cw');
});

test('google business event and offer posts need a title and dates, and a url action needs a url, in api and mcp alike', function () {
    $account = netParityAccount(Platform::GoogleBusiness);
    $type = ContentType::GoogleBusinessPost;

    netParityRefused(
        netParityPayload($account, $type, [], ['topic_type' => 'EVENT']),
        __('posts.form.google_business.event_title_required'),
        'destinations.0.meta.event.title',
    );
    netParityRefused(
        netParityPayload($account, $type, [], ['topic_type' => 'OFFER']),
        __('posts.form.google_business.offer_title_required'),
        'destinations.0.meta.event.title',
    );
    netParityRefused(
        netParityPayload($account, $type, [], ['topic_type' => 'EVENT', 'event' => ['title' => 'Launch', 'start_date' => '2037-01-02', 'end_date' => '2037-01-01']]),
        __('posts.form.google_business.event_end_date_before_start'),
        'destinations.0.meta.event.end_date',
    );
    netParityRefused(
        netParityPayload($account, $type, [], ['call_to_action' => ['action_type' => 'LEARN_MORE']]),
        __('posts.form.google_business.cta_url_required'),
        'destinations.0.meta.call_to_action.url',
    );
});

test('a google business event is stored the same way through api and mcp', function () {
    $account = netParityAccount(Platform::GoogleBusiness);
    $meta = ['topic_type' => 'EVENT', 'event' => ['title' => 'Launch', 'start_date' => '2037-01-01', 'end_date' => '2037-01-02']];
    $payload = fn () => netParityPayload($account, ContentType::GoogleBusinessPost, [], $meta);

    [$api] = netParityAccepted($payload(), $payload());

    expect($api->meta['event']['title'])->toBe('Launch');
});

test('a linkedin document post stores its title the same way and refuses one over 300 characters through api and mcp', function () {
    $account = netParityAccount(Platform::LinkedIn);
    $payload = fn (string $title) => netParityPayload($account, ContentType::LinkedInPost, ['media' => [netParityDocument()]], ['document_title' => $title]);

    netParityRefused($payload(str_repeat('t', 301)), 'destinations.0.meta.document_title', 'destinations.0.meta.document_title');

    [$api] = netParityAccepted($payload('Deck'), $payload('Deck'));

    expect($api->meta['document_title'])->toBe('Deck');
});

test('a threads topic tag is normalised and validated the same way through api and mcp', function () {
    $account = netParityAccount(Platform::Threads);
    $payload = fn (string $tag) => netParityPayload($account, ContentType::ThreadsPost, ['status' => 'draft', 'scheduled_at' => null], ['topic_tag' => $tag]);

    netParityRefused($payload('bad.tag'), __('posts.form.threads.topic_invalid'), 'destinations.0.meta.topic_tag');

    [$api] = netParityAccepted($payload('#ideas'), $payload('#ideas'));

    expect($api->meta['topic_tag'])->toBe('ideas');
});

test('link preview false is stored the same way and a non boolean is refused through api and mcp', function () {
    $account = netParityAccount(Platform::Bluesky);
    $payload = fn (mixed $value) => netParityPayload($account, ContentType::BlueskyPost, ['status' => 'draft', 'scheduled_at' => null], ['link_preview' => $value]);

    netParityRefused($payload('false'), 'destinations.0.meta.link_preview', 'destinations.0.meta.link_preview');

    [$api] = netParityAccepted($payload(false), $payload(false));

    expect($api->meta['link_preview'])->toBeFalse();
});

test('meta aspect_ratio is dropped on the single create path by api and mcp', function () {
    $account = netParityAccount(Platform::Instagram);
    $payload = fn () => [
        'content' => 'Ratio',
        'media' => [netParityImage()],
        'social_account_id' => $account->id, 'content_type' => ContentType::InstagramFeed->value, 'meta' => ['aspect_ratio' => '1:1', 'share_to_feed' => true],
    ];

    netParityApi()->postJson(route('api.posts.store'), $payload())->assertCreated();
    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, $payload())->assertOk();

    $metas = Post::query()->where('social_account_id', $account->id)->get()->pluck('meta');

    expect($metas)->toHaveCount(2)
        ->and($metas[0])->not->toHaveKey('aspect_ratio')
        ->and($metas[1])->not->toHaveKey('aspect_ratio')
        ->and($metas[0]['share_to_feed'])->toBeTrue();
});

test('meta aspect_ratio is stripped on the batch create path by api and mcp alike, known keys survive', function () {
    $account = netParityAccount(Platform::Instagram);
    $payload = fn () => netParityPayload($account, ContentType::InstagramFeed, ['status' => 'draft', 'scheduled_at' => null, 'media' => [netParityImage()]], ['aspect_ratio' => '1:1', 'share_to_feed' => false]);

    [$api, $mcp] = netParityAccepted($payload(), $payload());

    expect($api->meta)->not->toHaveKey('aspect_ratio')
        ->and($mcp->meta)->not->toHaveKey('aspect_ratio')
        ->and($api->meta['share_to_feed'])->toBeFalse()
        ->and($mcp->meta['share_to_feed'])->toBeFalse();
});

test('updating a draft with an invalid threads topic tag is refused by api and mcp', function () {
    $account = netParityAccount(Platform::Threads);
    $apiPost = Post::factory()->forAccount($account, ContentType::ThreadsPost)->draft()->create(['user_id' => $this->user->id]);
    $mcpPost = Post::factory()->forAccount($account, ContentType::ThreadsPost)->draft()->create(['user_id' => $this->user->id]);

    netParityApi()
        ->putJson(route('api.posts.update', $apiPost), ['status' => 'draft', 'meta' => ['topic_tag' => 'bad.tag']])
        ->assertUnprocessable()->assertJsonValidationErrors(['meta.topic_tag' => __('posts.form.threads.topic_invalid')]);

    TryPostServer::actingAs($this->user)
        ->tool(UpdatePostTool::class, ['post_id' => $mcpPost->id, 'meta' => ['topic_tag' => 'bad.tag']])
        ->assertHasErrors([__('posts.form.threads.topic_invalid')]);

    expect($apiPost->refresh()->meta['topic_tag'] ?? null)->toBeNull()
        ->and($mcpPost->refresh()->meta['topic_tag'] ?? null)->toBeNull()
        ->and($apiPost->status)->toBe(Status::Draft);
});

test('updating a draft with a non boolean link preview is refused with the same message by api and mcp', function () {
    $account = netParityAccount(Platform::Bluesky);
    $apiPost = Post::factory()->forAccount($account, ContentType::BlueskyPost)->draft()->create(['user_id' => $this->user->id]);
    $mcpPost = Post::factory()->forAccount($account, ContentType::BlueskyPost)->draft()->create(['user_id' => $this->user->id]);

    $message = netParityApi()
        ->putJson(route('api.posts.update', $apiPost), ['status' => 'draft', 'meta' => ['link_preview' => 'false']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['meta.link_preview'])
        ->json('errors')['meta.link_preview'][0];

    TryPostServer::actingAs($this->user)
        ->tool(UpdatePostTool::class, ['post_id' => $mcpPost->id, 'meta' => ['link_preview' => 'false']])
        ->assertHasErrors([$message]);

    expect($apiPost->refresh()->meta['link_preview'] ?? null)->toBeNull()
        ->and($mcpPost->refresh()->meta['link_preview'] ?? null)->toBeNull();
});

test('onlyKnown keeps every key that has a meta rule and drops the rest', function () {
    $meta = [
        'thread_replies' => [['text' => 'one', 'media' => []]],
        'board_id' => 'b1',
        'channel_id' => 'c1',
        'mentions' => [['token' => '@everyone']],
        'embeds' => [['title' => 't']],
        'privacy_level' => 'SELF_ONLY',
        'title' => 'Hello',
        'category_id' => '22',
        'event' => ['title' => 'x'],
        'offer' => ['coupon_code' => 'A'],
        'call_to_action' => ['action_type' => 'BOOK'],
        'link_preview' => false,
        'is_ai_generated' => true,
    ];

    expect(PostPlatformMetaRules::onlyKnown([...$meta, 'aspect_ratio' => '1:1', 'bogus' => 1]))->toEqual($meta);
});

test('the single create path measures caption limits by status, not by a schedule time, through api and mcp', function () {
    Queue::fake();
    $account = netParityAccount(Platform::X);
    $message = __('posts.form.content_exceeds_platform', ['platform' => Platform::X->label(), 'limit' => 280, 'over' => 1]);
    $payload = fn (array $overrides) => [
        'content' => str_repeat('a', 281),
        'social_account_id' => $account->id, 'content_type' => ContentType::XPost->value,
        ...$overrides,
    ];

    netParityApi()->postJson(route('api.posts.store'), $payload(['status' => 'publishing']))
        ->assertUnprocessable()->assertJsonValidationErrors(['content' => $message]);
    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, $payload(['status' => 'publishing']))->assertHasErrors([$message]);

    expect(netParityPostCount())->toBe(0);

    $draft = $payload(['status' => 'draft', 'scheduled_at' => now()->addDay()->toIso8601String()]);
    netParityApi()->postJson(route('api.posts.store'), $draft)->assertCreated();
    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, $draft)->assertOk();

    expect(Post::query()->where('workspace_id', $this->workspace->id)->pluck('status')->unique()->all())->toBe([Status::Draft]);
});
