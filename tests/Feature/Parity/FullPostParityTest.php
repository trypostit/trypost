<?php

declare(strict_types=1);

use App\Enums\Post\PublishStatus as PostPlatformStatus;
use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\CreatePostsTool;
use App\Mcp\Tools\Post\CreatePostTool;
use App\Mcp\Tools\Post\GetPostTool;
use App\Mcp\Tools\Post\PublishPostTool;
use App\Mcp\Tools\Post\UpdatePostTool;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\WorkspaceLabel;
use App\Services\Media\MediaOptimizer;
use App\Support\PostPlatformMetaRules;
use Google\Client;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

function fullPostRouter(): Closure
{
    $counter = 0;

    return function (Request $request) use (&$counter) {
        $counter++;
        fullPostWire([strtoupper($request->method()), $request->url(), fullPostBody($request)]);
        $url = $request->url();
        $method = strtoupper($request->method());
        $png = file_get_contents(base_path('tests/fixtures/1x1.png'));
        $data = rescue(fn () => $request->isJson() || $request->isForm() ? $request->data() : [], [], report: false);
        $facebook = config('trypost.platforms.facebook.graph_api');
        $rupload = config('trypost.platforms.facebook.rupload_host');
        $instagram = config('trypost.platforms.instagram.graph_api');
        $threads = config('trypost.platforms.threads.graph_api');
        $linkedin = config('trypost.platforms.linkedin.api');
        $pinterest = config('trypost.platforms.pinterest.api');
        $tiktok = config('trypost.platforms.tiktok.api');
        $discord = config('trypost.platforms.discord.api');
        $telegram = config('trypost.platforms.telegram.api');
        $google = config('trypost.platforms.google_business.local_posts_api');

        return match (true) {
            str_contains($url, 'storage/') && str_ends_with($url, '.mp4') => Http::response(str_repeat("\x00\xff", 2048), 200, ['Content-Type' => 'video/mp4']),
            str_contains($url, 'storage/') => Http::response($png, 200, ['Content-Type' => 'image/png']),
            str_contains($url, 'api.x.com/2/media/upload') => Http::response(['data' => ['id' => "x-media-{$counter}"]], 200),
            str_contains($url, 'api.x.com/2/media/metadata') => Http::response([], 200),
            str_contains($url, 'api.x.com/2/tweets') => Http::response(['data' => ['id' => "x-{$counter}"]], 201),
            str_contains($url, 'uploadBlob') => Http::response(['blob' => ['$type' => 'blob', 'ref' => ['$link' => "blob-{$counter}"], 'mimeType' => 'image/png', 'size' => 1]], 200),
            str_contains($url, 'createRecord') => Http::response(['uri' => "at://did:plc:me/app.bsky.feed.post/p{$counter}", 'cid' => "cid-{$counter}"], 200),
            str_contains($url, '/api/v1/media') => Http::response(['id' => "masto-media-{$counter}"], 200),
            str_contains($url, '/api/v1/statuses') => Http::response(['id' => "masto-{$counter}", 'url' => "https://mastodon.social/@me/{$counter}"], 200),
            str_starts_with($url, $threads) && str_ends_with(parse_url($url, PHP_URL_PATH), '/threads') => Http::response(['id' => "threads-container-{$counter}"], 200),
            str_starts_with($url, $threads) && str_ends_with(parse_url($url, PHP_URL_PATH), '/threads_publish') => Http::response(['id' => "threads-post-{$counter}"], 200),
            str_starts_with($url, $threads) => Http::response(['status' => 'FINISHED', 'permalink' => 'https://www.threads.net/@me/post/1'], 200),
            str_starts_with($url, $instagram) && str_ends_with(parse_url($url, PHP_URL_PATH), '/media') => Http::response(['id' => "ig-container-{$counter}"], 200),
            str_starts_with($url, $instagram) && str_ends_with(parse_url($url, PHP_URL_PATH), '/media_publish') => Http::response(['id' => "ig-media-{$counter}"], 200),
            str_starts_with($url, $instagram) => Http::response(['status_code' => 'FINISHED', 'status' => 'Finished', 'permalink' => 'https://www.instagram.com/p/1/'], 200),
            str_contains($url, $rupload) => Http::response(['success' => true], 200),
            str_starts_with($url, $facebook) && data_get($data, 'upload_phase') === 'start' => Http::response(['video_id' => "fb-video-{$counter}", 'upload_url' => "https://{$rupload}/video-upload/v25.0/fb-video-{$counter}"], 200),
            str_starts_with($url, $facebook) && data_get($data, 'upload_phase') === 'finish' => Http::response(['success' => true, 'id' => "fb-reel-{$counter}", 'post_id' => "fb-story-{$counter}"], 200),
            str_starts_with($url, $facebook) && str_contains($url, 'fields=status') => Http::response(['status' => ['video_status' => 'ready', 'uploading_phase' => ['status' => 'complete']]], 200),
            str_starts_with($url, $facebook) && str_ends_with(parse_url($url, PHP_URL_PATH), '/photos') => Http::response(['id' => "fb-photo-{$counter}", 'post_id' => "page_1_fb-photo-post-{$counter}"], 200),
            str_starts_with($url, $facebook) && str_ends_with(parse_url($url, PHP_URL_PATH), '/photo_stories') => Http::response(['success' => true, 'post_id' => "fb-photo-story-{$counter}"], 200),
            str_starts_with($url, $facebook) => Http::response(['id' => "page_1_fb-post-{$counter}"], 200),
            str_starts_with($url, $linkedin) && str_contains($url, 'action=initializeUpload') => Http::response(['value' => ['uploadUrl' => "https://www.linkedin.com/dms-uploads/{$counter}", 'document' => "urn:li:document:{$counter}", 'image' => "urn:li:image:{$counter}"]], 200),
            str_starts_with($url, $linkedin) && str_contains($url, '/rest/posts') => Http::response(null, 201, ['x-restli-id' => "urn:li:share:{$counter}"]),
            str_contains($url, 'linkedin.com/dms-uploads') => Http::response(null, 201),
            str_starts_with($url, $linkedin) => Http::response(['status' => 'AVAILABLE'], 200),
            str_starts_with($url, $pinterest) && str_ends_with(parse_url($url, PHP_URL_PATH), '/media') && $method === 'POST' => Http::response(['media_id' => "pin-media-{$counter}", 'upload_url' => 'https://pinterest-media-upload.s3.amazonaws.com/', 'upload_parameters' => ['key' => 'k']], 201),
            str_contains($url, 'pinterest-media-upload') => Http::response(null, 204),
            str_starts_with($url, $pinterest) && str_contains($url, '/media/') => Http::response(['status' => 'succeeded'], 200),
            str_starts_with($url, $pinterest) && str_ends_with(parse_url($url, PHP_URL_PATH), '/pins') => Http::response(['id' => "pin-{$counter}"], 201),
            str_starts_with($url, $tiktok) && str_contains($url, 'creator_info') => Http::response(['data' => ['privacy_level_options' => ['PUBLIC_TO_EVERYONE', 'MUTUAL_FOLLOW_FRIENDS', 'FOLLOWER_OF_CREATOR', 'SELF_ONLY'], 'comment_disabled' => false, 'duet_disabled' => false, 'stitch_disabled' => false, 'max_video_post_duration_sec' => 600], 'error' => ['code' => 'ok']], 200),
            str_starts_with($url, $tiktok) && str_contains($url, '/init/') => Http::response(['data' => ['publish_id' => "tiktok-{$counter}"], 'error' => ['code' => 'ok']], 200),
            str_starts_with($url, $tiktok) && str_contains($url, '/status/fetch/') => Http::response(['data' => ['status' => 'PUBLISH_COMPLETE', 'publicaly_available_post_id' => ['7000000000000000001']], 'error' => ['code' => 'ok']], 200),
            str_contains($url, 'youtube.googleapis.com/upload') || str_contains($url, 'googleapis.com/upload/youtube') => Http::response('', 200, ['Location' => 'https://upload.example.test/session']),
            str_contains($url, 'upload.example.test/session') => Http::response(['id' => 'yt-short-id']),
            str_starts_with($url, $discord) && str_contains($url, '/guilds/') && str_ends_with($url, '/channels') => Http::response([['id' => '444555666', 'name' => 'general', 'type' => 0]], 200),
            str_starts_with($url, $discord) && str_contains($url, '/roles') => Http::response([['id' => '999000111', 'name' => '@everyone', 'permissions' => '248832']], 200),
            str_starts_with($url, $discord) && str_contains($url, '/members/') => Http::response(['roles' => []], 200),
            str_starts_with($url, $discord) && str_contains($url, '/messages') => Http::response(['id' => "discord-{$counter}", 'channel_id' => '444555666'], 200),
            str_starts_with($url, $telegram) => Http::response(['ok' => true, 'result' => ['message_id' => $counter]], 200),
            str_starts_with($url, $google) => Http::response(['name' => "accounts/123456789/locations/987654321/localPosts/{$counter}", 'state' => 'LIVE', 'searchUrl' => 'https://local.google.com/place?id=1'], 200),
            default => Http::response(['id' => "id-{$counter}"], 200),
        };
    };
}

/**
 * Every request the networks received, in order, recorded while its body is still readable.
 *
 * @param  array{0: string, 1: string, 2: mixed}|null  $request
 * @return list<array{0: string, 1: string, 2: mixed}>
 */
function fullPostWire(?array $request = null, bool $reset = false): array
{
    static $wire = [];

    if ($reset) {
        $wire = [];
    }

    if ($request !== null) {
        $wire[] = $request;
    }

    return $wire;
}

function fullPostFake(): void
{
    static $fakedFor = null;

    if ($fakedFor === spl_object_id(app())) {
        return;
    }

    $fakedFor = spl_object_id(app());
    Http::fake(fullPostRouter());

    $google = new Client;
    $google->setHttpClient(Http::buildClient());
    app()->instance(Client::class, $google);
}

function fullPostBody(Request $request): mixed
{
    if ($request->isMultipart()) {
        return collect($request->data())
            ->mapWithKeys(fn (array $part): array => [data_get($part, 'name') => is_string(data_get($part, 'contents')) && mb_check_encoding(data_get($part, 'contents'), 'UTF-8') ? data_get($part, 'contents') : 'binary'])
            ->all();
    }

    if ($request->isJson() || $request->isForm()) {
        return $request->data();
    }

    $body = rescue(fn (): string => $request->body(), '', report: false);

    return mb_check_encoding($body, 'UTF-8') ? mb_substr($body, 0, 300) : 'binary';
}

beforeEach(function () {
    Storage::fake();
    Sleep::fake();
    fullPostWire(reset: true);
    config(['trypost.platforms.telegram.bot_token' => 'TESTTOKEN', 'trypost.platforms.discord.bot_token' => 'DISCORDTOKEN']);
    ['user' => $this->user, 'workspace' => $this->workspace, 'token' => $this->token] = parityContext();

    $optimizer = Mockery::mock(MediaOptimizer::class)->makePartial();
    $optimizer->shouldReceive('optimizeImage')->andReturnUsing(function (string $file): string {
        $copy = tempnam(sys_get_temp_dir(), 'full_post_');
        copy($file, $copy);

        return $copy;
    });
    $optimizer->shouldReceive('fitToCanvas')->andReturnUsing(function (string $file): string {
        $copy = tempnam(sys_get_temp_dir(), 'full_post_story_');
        copy($file, $copy);

        return $copy;
    });
    app()->instance(MediaOptimizer::class, $optimizer);
});

function fullPostImage(array $meta = ['width' => 1080, 'height' => 1080]): Media
{
    return Media::factory()->temporaryUpload(test()->workspace)->stored()->create(['meta' => $meta, 'original_filename' => 'photo.jpg', 'size' => 200_000]);
}

function fullPostVideo(): Media
{
    return Media::factory()->temporaryUpload(test()->workspace)->stored()->video()->create(['meta' => ['duration' => 20, 'width' => 1080, 'height' => 1920], 'size' => 5_000_000]);
}

function fullPostDocument(): Media
{
    return Media::factory()->temporaryUpload(test()->workspace)->stored()->document()->create(['size' => 100_000]);
}

/**
 * @return array{account: SocialAccount, content_type: string, content: string, media: list<array<string, mixed>>, meta: array<string, mixed>}
 */
function fullPostCase(string $name): array
{
    $workspace = test()->workspace;
    $account = fn (string $state, array $attributes = []): SocialAccount => SocialAccount::query()
        ->where('workspace_id', $workspace->id)
        ->where('platform_user_id', data_get($attributes, 'platform_user_id'))
        ->first() ?? SocialAccount::factory()->{$state}()->create(['workspace_id' => $workspace->id, ...$attributes]);

    return match ($name) {
        'x' => [
            'account' => $account('x', ['token_expires_at' => now()->addDays(30), 'meta' => ['x_subscription_type' => 'Premium']]),
            'content_type' => 'x_post',
            'content' => rtrim(str_repeat('Long X post. ', 30)),
            'media' => [['upload_token' => fullPostImage()->upload_token, 'alt' => 'X alt']],
            'meta' => ['is_ai_generated' => true, 'thread_replies' => [['text' => 'X reply', 'media' => [['upload_token' => fullPostImage()->upload_token]]]]],
        ],
        'bluesky' => [
            'account' => $account('bluesky'),
            'content_type' => 'bluesky_post',
            'content' => 'Root on bluesky',
            'media' => [['upload_token' => fullPostImage()->upload_token, 'alt' => 'Bluesky alt']],
            'meta' => ['thread_replies' => [['text' => 'Bluesky reply', 'media' => [['upload_token' => fullPostImage()->upload_token, 'meta' => ['alt_text' => 'Reply alt']]]]]],
        ],
        'bluesky link without preview' => [
            'account' => $account('bluesky'),
            'content_type' => 'bluesky_post',
            'content' => 'Read https://example.com/article',
            'media' => [],
            'meta' => ['link_preview' => false],
        ],
        'mastodon' => [
            'account' => $account('mastodon'),
            'content_type' => 'mastodon_post',
            'content' => 'Root on mastodon',
            'media' => [['upload_token' => fullPostImage()->upload_token, 'alt' => 'Mastodon alt']],
            'meta' => ['spoiler_text' => 'CW here', 'thread_replies' => ['Mastodon reply']],
        ],
        'threads' => [
            'account' => $account('threads', ['platform_user_id' => 'threads_user']),
            'content_type' => 'threads_post',
            'content' => 'Root on threads',
            'media' => [['upload_token' => fullPostImage()->upload_token, 'alt' => 'Threads alt']],
            'meta' => ['topic_tag' => '#Launch Day'],
        ],
        'threads ghost post' => [
            'account' => $account('threads', ['platform_user_id' => 'threads_user']),
            'content_type' => 'threads_ghost_post',
            'content' => 'Gone in 24 hours',
            'media' => [],
            'meta' => [],
        ],
        'instagram carousel' => [
            'account' => $account('instagram', ['platform_user_id' => 'ig_user']),
            'content_type' => 'instagram_feed',
            'content' => 'Carousel caption #one',
            'media' => [
                ['upload_token' => fullPostImage()->upload_token, 'alt' => 'First alt', 'meta' => ['user_tags' => [['username' => 'friend.one', 'x' => 0.25, 'y' => 0.75]]]],
                ['upload_token' => fullPostImage()->upload_token, 'alt' => 'Second alt'],
            ],
            'meta' => ['is_ai_generated' => true],
        ],
        'instagram reel' => [
            'account' => $account('instagram', ['platform_user_id' => 'ig_user']),
            'content_type' => 'instagram_reel',
            'content' => 'Reel caption',
            'media' => [['upload_token' => fullPostVideo()->upload_token, 'meta' => ['cover_offset_ms' => 3500]]],
            'meta' => ['is_ai_generated' => true, 'share_to_feed' => false],
        ],
        'instagram story' => [
            'account' => $account('instagram', ['platform_user_id' => 'ig_user']),
            'content_type' => 'instagram_story',
            'content' => '',
            'media' => [['upload_token' => fullPostImage(['width' => 1080, 'height' => 1920])->upload_token]],
            'meta' => [],
        ],
        'facebook post' => [
            'account' => $account('facebook', ['platform_user_id' => 'page_1', 'token_expires_at' => null, 'meta' => ['page_id' => 'page_1']]),
            'content_type' => 'facebook_post',
            'content' => 'Facebook photo post',
            'media' => [['upload_token' => fullPostImage()->upload_token, 'alt' => 'Facebook alt']],
            'meta' => [],
        ],
        'facebook link without preview' => [
            'account' => $account('facebook', ['platform_user_id' => 'page_1', 'token_expires_at' => null, 'meta' => ['page_id' => 'page_1']]),
            'content_type' => 'facebook_post',
            'content' => 'Read https://example.com/article',
            'media' => [],
            'meta' => ['link_preview' => false],
        ],
        'facebook reel' => [
            'account' => $account('facebook', ['platform_user_id' => 'page_1', 'token_expires_at' => null, 'meta' => ['page_id' => 'page_1']]),
            'content_type' => 'facebook_reel',
            'content' => 'Facebook reel caption',
            'media' => [['upload_token' => fullPostVideo()->upload_token]],
            'meta' => [],
        ],
        'facebook photo story' => [
            'account' => $account('facebook', ['platform_user_id' => 'page_1', 'token_expires_at' => null, 'meta' => ['page_id' => 'page_1']]),
            'content_type' => 'facebook_story',
            'content' => '',
            'media' => [['upload_token' => fullPostImage(['width' => 1080, 'height' => 1920])->upload_token]],
            'meta' => [],
        ],
        'linkedin document' => [
            'account' => $account('linkedin', ['platform_user_id' => 'li_person']),
            'content_type' => 'linkedin_post',
            'content' => 'Our report',
            'media' => [['upload_token' => fullPostDocument()->upload_token]],
            'meta' => ['document_title' => 'Annual report 2026'],
        ],
        'linkedin link without preview' => [
            'account' => $account('linkedin', ['platform_user_id' => 'li_person']),
            'content_type' => 'linkedin_post',
            'content' => 'Read https://example.com/article',
            'media' => [],
            'meta' => ['link_preview' => false],
        ],
        'linkedin page images' => [
            'account' => $account('linkedinPage', ['platform_user_id' => '123456', 'meta' => ['organization_id' => '123456']]),
            'content_type' => 'linkedin_page_post',
            'content' => 'Company update',
            'media' => [['upload_token' => fullPostImage()->upload_token, 'alt' => 'LinkedIn alt']],
            'meta' => [],
        ],
        'pinterest video pin' => [
            'account' => $account('pinterest'),
            'content_type' => 'pinterest_video_pin',
            'content' => 'Pin description',
            'media' => [['upload_token' => fullPostVideo()->upload_token, 'meta' => ['cover_offset_ms' => 4200]]],
            'meta' => ['board_id' => 'board_9', 'title' => 'Pin title', 'link' => 'https://example.com/landing'],
        ],
        'pinterest image pin' => [
            'account' => $account('pinterest'),
            'content_type' => 'pinterest_pin',
            'content' => 'Image pin description',
            'media' => [['upload_token' => fullPostImage()->upload_token, 'alt' => 'Pin alt']],
            'meta' => ['board_id' => 'board_9', 'title' => 'Image pin', 'link' => 'https://example.com/landing'],
        ],
        'tiktok video' => [
            'account' => $account('tiktok', ['username' => 'tiktoker']),
            'content_type' => 'tiktok_video',
            'content' => 'TikTok caption',
            'media' => [['upload_token' => fullPostVideo()->upload_token, 'meta' => ['cover_offset_ms' => 1500]]],
            'meta' => ['privacy_level' => 'MUTUAL_FOLLOW_FRIENDS', 'allow_comments' => true, 'allow_duet' => true, 'allow_stitch' => false, 'is_aigc' => true, 'disclose' => true, 'brand_content_toggle' => false, 'brand_organic_toggle' => true],
        ],
        'tiktok photo' => [
            'account' => $account('tiktok', ['username' => 'tiktoker']),
            'content_type' => 'tiktok_photo',
            'content' => 'TikTok photo caption',
            'media' => [['upload_token' => fullPostImage()->upload_token], ['upload_token' => fullPostImage()->upload_token]],
            'meta' => ['privacy_level' => 'PUBLIC_TO_EVERYONE', 'allow_comments' => false, 'auto_add_music' => true, 'disclose' => true, 'brand_content_toggle' => true],
        ],
        'youtube short' => [
            'account' => $account('youtube'),
            'content_type' => 'youtube_short',
            'content' => 'Short body',
            'media' => [['upload_token' => fullPostVideo()->upload_token]],
            'meta' => ['title' => 'My Short', 'description' => 'Short description', 'category_id' => '27', 'privacy_status' => 'unlisted', 'license' => 'creativeCommon', 'notify_subscribers' => false, 'embeddable' => false, 'made_for_kids' => true, 'is_ai_generated' => true],
        ],
        'discord' => [
            'account' => $account('discord', ['platform_user_id' => '999000111']),
            'content_type' => 'discord_message',
            'content' => 'Discord message',
            'media' => [['upload_token' => fullPostImage()->upload_token, 'alt' => 'Discord alt']],
            'meta' => [
                'channel_id' => '444555666',
                'channel_name' => 'general',
                'mentions' => [['token' => '@everyone', 'label' => 'everyone']],
                'embeds' => [['title' => 'Embed title', 'description' => 'Embed body', 'url' => 'https://example.com', 'image' => 'https://example.com/i.png', 'color' => '#FF8800']],
            ],
        ],
        'telegram' => [
            'account' => $account('telegram'),
            'content_type' => 'telegram_post',
            'content' => 'Telegram caption',
            'media' => [['upload_token' => fullPostImage()->upload_token]],
            'meta' => [],
        ],
        'google business offer' => [
            'account' => $account('googleBusiness', ['platform_user_id' => 'accounts/123456789/locations/987654321', 'token_expires_at' => now()->addDay()]),
            'content_type' => 'google_business_post',
            'content' => 'Half price this week',
            'media' => [['upload_token' => fullPostImage()->upload_token]],
            'meta' => [
                'topic_type' => 'OFFER',
                'event' => ['title' => 'Half price', 'start_date' => '2037-01-10', 'end_date' => '2037-01-20', 'start_time' => '09:00', 'end_time' => '18:00'],
                'offer' => ['coupon_code' => 'HALF', 'redeem_online_url' => 'https://example.com/redeem', 'terms_conditions' => 'One per customer'],
            ],
        ],
        'google business event' => [
            'account' => $account('googleBusiness', ['platform_user_id' => 'accounts/123456789/locations/987654321', 'token_expires_at' => now()->addDay()]),
            'content_type' => 'google_business_post',
            'content' => 'Join our launch',
            'media' => [],
            'meta' => [
                'topic_type' => 'EVENT',
                'event' => ['title' => 'Launch party', 'start_date' => '2037-02-01', 'end_date' => '2037-02-01', 'start_time' => '18:00', 'end_time' => '22:00'],
                'call_to_action' => ['action_type' => 'SIGN_UP', 'url' => 'https://example.com/signup'],
            ],
        ],
        'google business standard' => [
            'account' => $account('googleBusiness', ['platform_user_id' => 'accounts/123456789/locations/987654321', 'token_expires_at' => now()->addDay()]),
            'content_type' => 'google_business_post',
            'content' => 'Call us today',
            'media' => [],
            'meta' => ['topic_type' => 'STANDARD', 'call_to_action' => ['action_type' => 'CALL']],
        ],
    };
}

/**
 * The requests that carried the post (every non-GET call), with the values
 * that differ between runs replaced by placeholders.
 *
 * @return list<array{0: string, 1: string, 2: mixed}>
 */
function fullPostPublished(): array
{
    return collect(fullPostWire())
        ->reject(fn (array $request): bool => $request[0] === 'GET' || str_contains($request[1], 'video/query'))
        ->map(fn (array $request): array => [$request[0], fullPostNormalize($request[1]), fullPostNormalize($request[2])])
        ->values()
        ->all();
}

function fullPostNormalize(mixed $value): mixed
{
    if (is_array($value)) {
        unset($value['access_token']);

        if (is_string(data_get($value, 'payload_json'))) {
            $value['payload_json'] = json_decode($value['payload_json'], true);
        }

        return array_map(fullPostNormalize(...), $value);
    }

    if (! is_string($value)) {
        return $value;
    }

    return preg_replace(
        [
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/',
            '/media\/\d{4}-\d{2}\//',
            '/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z/',
            '/\b([a-z]+(?:-[a-z]+)*)-\d+\b/',
            '/(urn:li:(?:document|image|share):|dms-uploads\/|app\.bsky\.feed\.post\/p)\d+/',
        ],
        ['{uuid}', 'media/{month}/', '{now}', '$1-#', '$1#'],
        $value,
    );
}

/**
 * The single-post create payload of a case, as both the API and MCP take it.
 *
 * @param  array<string, mixed>  $case
 * @return array<string, mixed>
 */
function fullPostPayload(array $case, string $status, string $labelId): array
{
    return [
        'content' => $case['content'],
        'status' => $status,
        'scheduled_at' => $status === 'scheduled' ? now()->addHour()->toIso8601String() : null,
        'label_ids' => [$labelId],
        'media' => $case['media'],
        'platforms' => [['social_account_id' => $case['account']->id, 'content_type' => $case['content_type'], 'meta' => $case['meta']]],
    ];
}

function fullPostCreate(string $surface, array $payload): Post
{
    $before = Post::query()->pluck('id')->all();

    if ($surface === 'api') {
        test()->withHeaders(parityApi(test()->token))->postJson(route('api.posts.store'), $payload)->assertCreated();
    } else {
        TryPostServer::actingAs(test()->user)->tool(CreatePostTool::class, $payload)->assertOk();
    }

    return Post::query()->whereNotIn('id', $before)->sole();
}

/**
 * The requests each network receives for its case, with run-specific values as placeholders.
 *
 * @return list<array{0: string, 1: string, 2: mixed}>
 */
function fullPostExpected(string $name): array
{
    return [
        'x' => [
            [
                'POST',
                'https://api.x.com/2/media/upload',
                [
                    'media_category' => 'tweet_image',
                    'media' => 'binary',
                ],
            ],
            [
                'POST',
                'https://api.x.com/2/media/metadata',
                [
                    'id' => 'x-media-#',
                    'metadata' => [
                        'alt_text' => [
                            'text' => 'X alt',
                        ],
                    ],
                ],
            ],
            [
                'POST',
                'https://api.x.com/2/tweets',
                [
                    'text' => rtrim(str_repeat('Long X post. ', 30)),
                    'media' => [
                        'media_ids' => [
                            'x-media-#',
                        ],
                    ],
                    'made_with_ai' => true,
                ],
            ],
            [
                'POST',
                'https://api.x.com/2/media/upload',
                [
                    'media_category' => 'tweet_image',
                    'media' => 'binary',
                ],
            ],
            [
                'POST',
                'https://api.x.com/2/tweets',
                [
                    'text' => 'X reply',
                    'media' => [
                        'media_ids' => [
                            'x-media-#',
                        ],
                    ],
                    'reply' => [
                        'in_reply_to_tweet_id' => 'x-#',
                    ],
                ],
            ],
        ],
        'bluesky' => [
            [
                'POST',
                'https://bsky.social/xrpc/com.atproto.repo.uploadBlob',
                'binary',
            ],
            [
                'POST',
                'https://bsky.social/xrpc/com.atproto.repo.createRecord',
                [
                    'repo' => '{uuid}',
                    'collection' => 'app.bsky.feed.post',
                    'record' => [
                        '$type' => 'app.bsky.feed.post',
                        'text' => 'Root on bluesky',
                        'createdAt' => '{now}',
                        'embed' => [
                            '$type' => 'app.bsky.embed.images',
                            'images' => [
                                [
                                    'alt' => 'Bluesky alt',
                                    'image' => [
                                        '$type' => 'blob',
                                        'ref' => [
                                            '$link' => 'blob-#',
                                        ],
                                        'mimeType' => 'image/png',
                                        'size' => 1,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'POST',
                'https://bsky.social/xrpc/com.atproto.repo.uploadBlob',
                'binary',
            ],
            [
                'POST',
                'https://bsky.social/xrpc/com.atproto.repo.createRecord',
                [
                    'repo' => '{uuid}',
                    'collection' => 'app.bsky.feed.post',
                    'record' => [
                        '$type' => 'app.bsky.feed.post',
                        'text' => 'Bluesky reply',
                        'createdAt' => '{now}',
                        'reply' => [
                            'root' => [
                                'uri' => 'at://did:plc:me/app.bsky.feed.post/p#',
                                'cid' => 'cid-#',
                            ],
                            'parent' => [
                                'uri' => 'at://did:plc:me/app.bsky.feed.post/p#',
                                'cid' => 'cid-#',
                            ],
                        ],
                        'embed' => [
                            '$type' => 'app.bsky.embed.images',
                            'images' => [
                                [
                                    'alt' => 'Reply alt',
                                    'image' => [
                                        '$type' => 'blob',
                                        'ref' => [
                                            '$link' => 'blob-#',
                                        ],
                                        'mimeType' => 'image/png',
                                        'size' => 1,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'bluesky link without preview' => [
            [
                'POST',
                'https://bsky.social/xrpc/com.atproto.repo.createRecord',
                [
                    'repo' => '{uuid}',
                    'collection' => 'app.bsky.feed.post',
                    'record' => [
                        '$type' => 'app.bsky.feed.post',
                        'text' => 'Read https://example.com/article',
                        'createdAt' => '{now}',
                        'facets' => [
                            [
                                'index' => [
                                    'byteStart' => 5,
                                    'byteEnd' => 32,
                                ],
                                'features' => [
                                    [
                                        '$type' => 'app.bsky.richtext.facet#link',
                                        'uri' => 'https://example.com/article',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'mastodon' => [
            [
                'POST',
                'https://mastodon.social/api/v1/media',
                [
                    'file' => 'binary',
                    'description' => 'Mastodon alt',
                ],
            ],
            [
                'POST',
                'https://mastodon.social/api/v1/statuses',
                [
                    'status' => 'Root on mastodon',
                    'visibility' => 'public',
                    'media_ids' => [
                        'masto-media-#',
                    ],
                    'spoiler_text' => 'CW here',
                ],
            ],
            [
                'POST',
                'https://mastodon.social/api/v1/statuses',
                [
                    'status' => 'Mastodon reply',
                    'visibility' => 'public',
                    'in_reply_to_id' => 'masto-#',
                    'spoiler_text' => 'CW here',
                ],
            ],
        ],
        'threads' => [
            [
                'POST',
                'https://graph.threads.net/v1.0/threads_user/threads',
                [
                    'media_type' => 'IMAGE',
                    'image_url' => '/storage/media/{month}/{uuid}.jpg',
                    'text' => 'Root on threads',
                    'topic_tag' => 'Launch Day',
                    'alt_text' => 'Threads alt',
                ],
            ],
            [
                'POST',
                'https://graph.threads.net/v1.0/threads_user/threads_publish',
                [
                    'creation_id' => 'threads-container-#',
                ],
            ],
        ],
        'threads ghost post' => [
            [
                'POST',
                'https://graph.threads.net/v1.0/threads_user/threads',
                [
                    'media_type' => 'TEXT',
                    'text' => 'Gone in 24 hours',
                    'is_ghost_post' => 'true',
                ],
            ],
            [
                'POST',
                'https://graph.threads.net/v1.0/threads_user/threads_publish',
                [
                    'creation_id' => 'threads-container-#',
                ],
            ],
        ],
        'instagram carousel' => [
            [
                'POST',
                'https://graph.instagram.com/v25.0/ig_user/media',
                [
                    'is_carousel_item' => 'true',
                    'image_url' => '/storage/media/{month}/{uuid}.jpg',
                    'alt_text' => 'First alt',
                    'user_tags' => '[{"username":"friend.one","x":0.25,"y":0.75}]',
                ],
            ],
            [
                'POST',
                'https://graph.instagram.com/v25.0/ig_user/media',
                [
                    'is_carousel_item' => 'true',
                    'image_url' => '/storage/media/{month}/{uuid}.jpg',
                    'alt_text' => 'Second alt',
                ],
            ],
            [
                'POST',
                'https://graph.instagram.com/v25.0/ig_user/media',
                [
                    'media_type' => 'CAROUSEL',
                    'caption' => 'Carousel caption #one',
                    'children' => 'ig-container-#,ig-container-#',
                    'is_ai_generated' => 'true',
                ],
            ],
            [
                'POST',
                'https://graph.instagram.com/v25.0/ig_user/media_publish',
                [
                    'creation_id' => 'ig-container-#',
                ],
            ],
        ],
        'instagram reel' => [
            [
                'POST',
                'https://graph.instagram.com/v25.0/ig_user/media',
                [
                    'video_url' => '/storage/media/{month}/{uuid}.mp4',
                    'caption' => 'Reel caption',
                    'media_type' => 'REELS',
                    'thumb_offset' => 3500,
                    'is_ai_generated' => 'true',
                    'share_to_feed' => 'false',
                ],
            ],
            [
                'POST',
                'https://graph.instagram.com/v25.0/ig_user/media_publish',
                [
                    'creation_id' => 'ig-container-#',
                ],
            ],
        ],
        'instagram story' => [
            [
                'POST',
                'https://graph.instagram.com/v25.0/ig_user/media',
                [
                    'media_type' => 'STORIES',
                    'image_url' => '/storage/social-crops/{uuid}.jpg',
                ],
            ],
            [
                'POST',
                'https://graph.instagram.com/v25.0/ig_user/media_publish',
                [
                    'creation_id' => 'ig-container-#',
                ],
            ],
        ],
        'facebook post' => [
            [
                'POST',
                'https://graph.facebook.com/v25.0/page_1/photos',
                [
                    'url' => '/storage/media/{month}/{uuid}.jpg',
                    'message' => 'Facebook photo post',
                    'alt_text_custom' => 'Facebook alt',
                ],
            ],
        ],
        'facebook link without preview' => [
            [
                'POST',
                'https://graph.facebook.com/v25.0/page_1/feed',
                [
                    'message' => 'Read https://example.com/article',
                ],
            ],
        ],
        'facebook reel' => [
            [
                'POST',
                'https://graph.facebook.com/v25.0/page_1/video_reels',
                [
                    'upload_phase' => 'start',
                ],
            ],
            [
                'POST',
                'https://rupload.facebook.com/video-upload/v25.0/fb-video-#',
                '',
            ],
            [
                'POST',
                'https://graph.facebook.com/v25.0/page_1/video_reels',
                [
                    'upload_phase' => 'finish',
                    'video_id' => 'fb-video-#',
                    'video_state' => 'PUBLISHED',
                    'description' => 'Facebook reel caption',
                ],
            ],
        ],
        'facebook photo story' => [
            [
                'POST',
                'https://graph.facebook.com/v25.0/page_1/photos',
                [
                    'url' => '/storage/social-crops/{uuid}.jpg',
                    'published' => 'false',
                ],
            ],
            [
                'POST',
                'https://graph.facebook.com/v25.0/page_1/photo_stories',
                [
                    'photo_id' => 'fb-photo-#',
                ],
            ],
        ],
        'linkedin document' => [
            [
                'POST',
                'https://api.linkedin.com/rest/documents?action=initializeUpload',
                [
                    'initializeUploadRequest' => [
                        'owner' => 'urn:li:person:li_person',
                    ],
                ],
            ],
            [
                'PUT',
                'https://www.linkedin.com/dms-uploads/#',
                'binary',
            ],
            [
                'POST',
                'https://api.linkedin.com/rest/posts',
                [
                    'author' => 'urn:li:person:li_person',
                    'commentary' => 'Our report',
                    'visibility' => 'PUBLIC',
                    'distribution' => [
                        'feedDistribution' => 'MAIN_FEED',
                        'targetEntities' => [],
                        'thirdPartyDistributionChannels' => [],
                    ],
                    'lifecycleState' => 'PUBLISHED',
                    'content' => [
                        'media' => [
                            'id' => 'urn:li:document:#',
                            'title' => 'Annual report 2026',
                        ],
                    ],
                ],
            ],
        ],
        'linkedin link without preview' => [
            [
                'POST',
                'https://api.linkedin.com/rest/posts',
                [
                    'author' => 'urn:li:person:li_person',
                    'commentary' => 'Read https://example.com/article',
                    'visibility' => 'PUBLIC',
                    'distribution' => [
                        'feedDistribution' => 'MAIN_FEED',
                        'targetEntities' => [],
                        'thirdPartyDistributionChannels' => [],
                    ],
                    'lifecycleState' => 'PUBLISHED',
                ],
            ],
        ],
        'linkedin page images' => [
            [
                'POST',
                'https://api.linkedin.com/rest/images?action=initializeUpload',
                [
                    'initializeUploadRequest' => [
                        'owner' => 'urn:li:organization:123456',
                    ],
                ],
            ],
            [
                'PUT',
                'https://www.linkedin.com/dms-uploads/#',
                'binary',
            ],
            [
                'POST',
                'https://api.linkedin.com/rest/posts',
                [
                    'author' => 'urn:li:organization:123456',
                    'commentary' => 'Company update',
                    'visibility' => 'PUBLIC',
                    'distribution' => [
                        'feedDistribution' => 'MAIN_FEED',
                        'targetEntities' => [],
                        'thirdPartyDistributionChannels' => [],
                    ],
                    'lifecycleState' => 'PUBLISHED',
                    'content' => [
                        'media' => [
                            'id' => 'urn:li:image:#',
                            'altText' => 'LinkedIn alt',
                        ],
                    ],
                ],
            ],
        ],
        'pinterest video pin' => [
            [
                'POST',
                'https://api.pinterest.com/v5/media',
                [
                    'media_type' => 'video',
                ],
            ],
            [
                'POST',
                'https://pinterest-media-upload.s3.amazonaws.com/',
                [
                    'key' => 'k',
                    'file' => 'binary',
                ],
            ],
            [
                'POST',
                'https://api.pinterest.com/v5/pins',
                [
                    'board_id' => 'board_9',
                    'media_source' => [
                        'source_type' => 'video_id',
                        'media_id' => 'pin-media-#',
                        'cover_image_key_frame_time' => 4,
                    ],
                    'description' => 'Pin description',
                    'title' => 'Pin title',
                    'link' => 'https://example.com/landing',
                ],
            ],
        ],
        'pinterest image pin' => [
            [
                'POST',
                'https://api.pinterest.com/v5/pins',
                [
                    'board_id' => 'board_9',
                    'media_source' => [
                        'source_type' => 'image_base64',
                        'content_type' => 'image/jpeg',
                        'data' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4//8/AAX+Av6jNYGEAAAAAElFTkSuQmCC',
                    ],
                    'description' => 'Image pin description',
                    'title' => 'Image pin',
                    'link' => 'https://example.com/landing',
                    'alt_text' => 'Pin alt',
                ],
            ],
        ],
        'tiktok video' => [
            [
                'POST',
                'https://open.tiktokapis.com/v2/post/publish/video/init/',
                [
                    'post_info' => [
                        'title' => 'TikTok caption',
                        'privacy_level' => 'MUTUAL_FOLLOW_FRIENDS',
                        'disable_duet' => false,
                        'disable_comment' => false,
                        'disable_stitch' => true,
                        'is_aigc' => true,
                        'brand_organic_toggle' => true,
                        'video_cover_timestamp_ms' => 1500,
                    ],
                    'source_info' => [
                        'source' => 'PULL_FROM_URL',
                        'video_url' => '/storage/media/{month}/{uuid}.mp4',
                    ],
                ],
            ],
            [
                'POST',
                'https://open.tiktokapis.com/v2/post/publish/status/fetch/',
                [
                    'publish_id' => 'tiktok-#',
                ],
            ],
        ],
        'tiktok photo' => [
            [
                'POST',
                'https://open.tiktokapis.com/v2/post/publish/content/init/',
                [
                    'post_info' => [
                        'description' => 'TikTok photo caption',
                        'privacy_level' => 'PUBLIC_TO_EVERYONE',
                        'disable_comment' => true,
                        'brand_content_toggle' => true,
                        'auto_add_music' => true,
                    ],
                    'source_info' => [
                        'source' => 'PULL_FROM_URL',
                        'photo_cover_index' => 0,
                        'photo_images' => [
                            '/storage/media/{month}/{uuid}.jpg',
                            '/storage/media/{month}/{uuid}.jpg',
                        ],
                    ],
                    'post_mode' => 'DIRECT_POST',
                    'media_type' => 'PHOTO',
                ],
            ],
            [
                'POST',
                'https://open.tiktokapis.com/v2/post/publish/status/fetch/',
                [
                    'publish_id' => 'tiktok-#',
                ],
            ],
        ],
        'youtube short' => [
            [
                'POST',
                'https://youtube.googleapis.com/upload/youtube/v3/videos?part=snippet%2Cstatus&notifySubscribers=false&uploadType=resumable',
                [
                    'snippet' => [
                        'categoryId' => '27',
                        'description' => 'Short description',
                        'title' => 'My Short',
                    ],
                    'status' => [
                        'containsSyntheticMedia' => true,
                        'embeddable' => false,
                        'license' => 'creativeCommon',
                        'privacyStatus' => 'unlisted',
                        'selfDeclaredMadeForKids' => true,
                    ],
                ],
            ],
            [
                'PUT',
                'https://upload.example.test/session',
                'binary',
            ],
        ],
        'discord' => [
            [
                'POST',
                'https://discord.com/api/v10/channels/444555666/messages',
                [
                    'payload_json' => [
                        'content' => "Discord message\n\n@everyone",
                        'embeds' => [
                            [
                                'title' => 'Embed title',
                                'description' => 'Embed body',
                                'url' => 'https://example.com',
                                'color' => 16746496,
                                'image' => [
                                    'url' => 'https://example.com/i.png',
                                ],
                            ],
                        ],
                        'allowed_mentions' => [
                            'parse' => [
                                'everyone',
                            ],
                        ],
                        'attachments' => [
                            [
                                'id' => 0,
                                'filename' => 'photo.jpg',
                                'description' => 'Discord alt',
                            ],
                        ],
                    ],
                    'files[0]' => 'binary',
                ],
            ],
        ],
        'telegram' => [
            [
                'POST',
                'https://api.telegram.org/botTESTTOKEN/sendPhoto',
                [
                    'chat_id' => '-1001234567890',
                    'photo' => '/storage/media/{month}/{uuid}.jpg',
                    'caption' => 'Telegram caption',
                    'parse_mode' => 'HTML',
                ],
            ],
        ],
        'google business offer' => [
            [
                'POST',
                'https://mybusiness.googleapis.com/v4/accounts/123456789/locations/987654321/localPosts',
                [
                    'languageCode' => 'en',
                    'summary' => 'Half price this week',
                    'topicType' => 'OFFER',
                    'media' => [
                        [
                            'mediaFormat' => 'PHOTO',
                            'sourceUrl' => '/storage/google-business-derivatives/{uuid}.jpg',
                        ],
                    ],
                    'event' => [
                        'title' => 'Half price',
                        'schedule' => [
                            'startDate' => [
                                'year' => 2037,
                                'month' => 1,
                                'day' => 10,
                            ],
                            'endDate' => [
                                'year' => 2037,
                                'month' => 1,
                                'day' => 20,
                            ],
                            'startTime' => [
                                'hours' => 9,
                                'minutes' => 0,
                                'seconds' => 0,
                                'nanos' => 0,
                            ],
                            'endTime' => [
                                'hours' => 18,
                                'minutes' => 0,
                                'seconds' => 0,
                                'nanos' => 0,
                            ],
                        ],
                    ],
                    'offer' => [
                        'couponCode' => 'HALF',
                        'redeemOnlineUrl' => 'https://example.com/redeem',
                        'termsConditions' => 'One per customer',
                    ],
                ],
            ],
        ],
        'google business event' => [
            [
                'POST',
                'https://mybusiness.googleapis.com/v4/accounts/123456789/locations/987654321/localPosts',
                [
                    'languageCode' => 'en',
                    'summary' => 'Join our launch',
                    'topicType' => 'EVENT',
                    'callToAction' => [
                        'actionType' => 'SIGN_UP',
                        'url' => 'https://example.com/signup',
                    ],
                    'event' => [
                        'title' => 'Launch party',
                        'schedule' => [
                            'startDate' => [
                                'year' => 2037,
                                'month' => 2,
                                'day' => 1,
                            ],
                            'endDate' => [
                                'year' => 2037,
                                'month' => 2,
                                'day' => 1,
                            ],
                            'startTime' => [
                                'hours' => 18,
                                'minutes' => 0,
                                'seconds' => 0,
                                'nanos' => 0,
                            ],
                            'endTime' => [
                                'hours' => 22,
                                'minutes' => 0,
                                'seconds' => 0,
                                'nanos' => 0,
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'google business standard' => [
            [
                'POST',
                'https://mybusiness.googleapis.com/v4/accounts/123456789/locations/987654321/localPosts',
                [
                    'languageCode' => 'en',
                    'summary' => 'Call us today',
                    'topicType' => 'STANDARD',
                    'callToAction' => [
                        'actionType' => 'CALL',
                    ],
                ],
            ],
        ],
    ][$name];
}

dataset('complete posts', [
    'x',
    'bluesky',
    'bluesky link without preview',
    'mastodon',
    'threads',
    'threads ghost post',
    'instagram carousel',
    'instagram reel',
    'instagram story',
    'facebook post',
    'facebook link without preview',
    'facebook reel',
    'facebook photo story',
    'linkedin document',
    'linkedin link without preview',
    'linkedin page images',
    'pinterest video pin',
    'pinterest image pin',
    'tiktok video',
    'tiktok photo',
    'youtube short',
    'discord',
    'telegram',
    'google business offer',
    'google business event',
    'google business standard',
]);

dataset('surfaces', ['api', 'mcp']);

test('a complete post published now through the api and through mcp reaches the network with every setting', function (string $name, string $surface) {
    fullPostFake();
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);

    $post = fullPostCreate($surface, fullPostPayload(fullPostCase($name), 'publishing', $label->id));

    expect($post->postPlatforms()->sole()->status)->toBe(PostPlatformStatus::Published)
        ->and($post->labels()->pluck('workspace_labels.id')->all())->toBe([$label->id])
        ->and(fullPostPublished())->toEqual(fullPostExpected($name));
})->with('complete posts')->with('surfaces');

test('a complete post scheduled through the api and through mcp reaches the network with every setting once due', function (string $name, string $surface) {
    fullPostFake();
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);

    $post = fullPostCreate($surface, fullPostPayload(fullPostCase($name), 'scheduled', $label->id));

    expect($post->status)->toBe(Status::Scheduled)
        ->and($post->schedule_mode)->toBe(ScheduleMode::Custom)
        ->and(fullPostPublished())->toBe([]);

    $this->travel(61)->minutes();
    $this->artisan('posts:process-scheduled')->assertSuccessful();

    expect($post->postPlatforms()->sole()->status)->toBe(PostPlatformStatus::Published)
        ->and(fullPostPublished())->toEqual(fullPostExpected($name));
})->with('complete posts')->with('surfaces');

test('a draft completed and published through api and mcp updates reaches the network with every setting', function (string $name, string $surface) {
    fullPostFake();
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    $case = fullPostCase($name);
    $post = fullPostCreate($surface, [...fullPostPayload($case, 'draft', $label->id), 'platforms' => [['social_account_id' => $case['account']->id, 'content_type' => $case['content_type']]]]);

    if ($surface === 'api') {
        $this->withHeaders(parityApi($this->token))
            ->putJson(route('api.posts.update', $post), ['meta' => $case['meta'], 'status' => 'publishing'])
            ->assertOk();
    } else {
        TryPostServer::actingAs($this->user)->tool(UpdatePostTool::class, ['post_id' => $post->id, 'meta' => $case['meta']])->assertOk();
        TryPostServer::actingAs($this->user)->tool(PublishPostTool::class, ['post_id' => $post->id])->assertOk();
    }

    expect($post->postPlatforms()->sole()->status)->toBe(PostPlatformStatus::Published)
        ->and(fullPostPublished())->toEqual(fullPostExpected($name));
})->with('complete posts')->with('surfaces');

test('a complete post reads back every setting the same way through the api and through mcp', function (string $name, string $surface) {
    fullPostFake();
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    $case = fullPostCase($name);
    $post = fullPostCreate($surface, fullPostPayload($case, 'draft', $label->id));

    $api = $this->withHeaders(parityApi($this->token))->getJson(route('api.posts.show', $post))->assertOk()->json();

    TryPostServer::actingAs($this->user)->tool(GetPostTool::class, ['post_id' => $post->id])
        ->assertOk()
        ->assertStructuredContent($api);

    $meta = PostPlatformMetaRules::normalize($case['meta']);
    $replies = data_get($meta, 'thread_replies');
    unset($meta['thread_replies']);

    expect(data_get($api, 'platforms.0.content_type'))->toBe($case['content_type'])
        ->and(Arr::except(data_get($api, 'platforms.0.meta'), ['thread_replies']))->toEqual($meta)
        ->and(collect(data_get($api, 'platforms.0.meta.thread_replies', []))->pluck('text')->all())->toBe(collect($replies ?? [])->pluck('text')->all())
        ->and(data_get($api, 'labels.0.id'))->toBe($label->id)
        ->and(data_get($api, 'content'))->toBe($case['content'])
        ->and(collect(data_get($api, 'media'))->map(fn (array $item): array => Arr::only((array) data_get($item, 'meta'), ['alt_text', 'user_tags', 'cover_offset_ms']))->all())
        ->toEqual(collect($case['media'])->map(fn (array $item): array => array_filter([
            'alt_text' => data_get($item, 'meta.alt_text', data_get($item, 'alt')),
            'user_tags' => data_get($item, 'meta.user_tags'),
            'cover_offset_ms' => data_get($item, 'meta.cover_offset_ms'),
        ], fn (mixed $value): bool => $value !== null))->all());
})->with('complete posts')->with('surfaces');

test('a batch with per-network caption, media and settings reaches each network as customized through the api and through mcp', function (string $surface) {
    fullPostFake();
    $mastodon = SocialAccount::factory()->mastodon()->create(['workspace_id' => $this->workspace->id]);
    $bluesky = SocialAccount::factory()->bluesky()->create(['workspace_id' => $this->workspace->id]);
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    $payload = [
        'status' => 'publishing',
        'content' => 'Shared caption',
        'label_ids' => [$label->id],
        'media' => [['upload_token' => fullPostImage()->upload_token, 'alt' => 'Shared alt']],
        'destinations' => [
            ['social_account_id' => $mastodon->id, 'content_type' => 'mastodon_post', 'content' => 'Mastodon caption', 'media' => [], 'meta' => ['spoiler_text' => 'Spoilers']],
            ['social_account_id' => $bluesky->id, 'content_type' => 'bluesky_post', 'media' => [['upload_token' => fullPostImage()->upload_token, 'alt' => 'Bluesky only']]],
        ],
    ];

    if ($surface === 'api') {
        $this->withHeaders(parityApi($this->token))->postJson(route('api.posts.batch.store'), $payload)->assertCreated();
    } else {
        TryPostServer::actingAs($this->user)->tool(CreatePostsTool::class, $payload)->assertOk();
    }

    $statuses = collect(fullPostPublished())->filter(fn (array $request): bool => str_ends_with($request[1], '/api/v1/statuses'))->values();
    $records = collect(fullPostPublished())->filter(fn (array $request): bool => str_ends_with($request[1], 'createRecord'))->values();

    expect(Post::query()->where('workspace_id', $this->workspace->id)->whereHas('labels', fn ($query) => $query->whereKey($label->id))->count())->toBe(2)
        ->and($statuses->all())->toEqual([['POST', 'https://mastodon.social/api/v1/statuses', ['status' => 'Mastodon caption', 'visibility' => 'public', 'spoiler_text' => 'Spoilers']]])
        ->and($records->pluck('2.record.text')->all())->toBe(['Shared caption'])
        ->and($records->pluck('2.record.embed.images.0.alt')->all())->toBe(['Bluesky only'])
        ->and(collect(fullPostPublished())->pluck(1)->filter(fn (string $url): bool => str_ends_with($url, '/api/v1/media'))->all())->toBe([]);
})->with('surfaces');

test('every per-platform setting the rules accept is documented for mcp clients', function () {
    $keys = collect(array_keys(PostPlatformMetaRules::rules()))
        ->filter(fn (string $key): bool => str_starts_with($key, 'platforms.*.meta.'))
        ->map(fn (string $key): string => explode('.', substr($key, strlen('platforms.*.meta.')))[0])
        ->unique()
        ->values();

    expect($keys)->not->toBeEmpty();

    foreach ($keys as $key) {
        expect(PostPlatformMetaRules::documentation())->toContain($key);
    }
});

test('every mcp tool that takes per-platform settings describes all of them', function (string $tool, string $path) {
    expect(data_get((new $tool)->toArray(), "inputSchema.properties.{$path}.description"))
        ->toContain(PostPlatformMetaRules::documentation());
})->with([
    'create-post-tool' => [CreatePostTool::class, 'platforms.items.properties.meta'],
    'create-posts-tool' => [CreatePostsTool::class, 'destinations.items.properties.meta'],
    'update-post-tool' => [UpdatePostTool::class, 'meta'],
]);
