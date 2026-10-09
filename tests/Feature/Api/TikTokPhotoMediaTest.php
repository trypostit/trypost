<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\Post;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    Storage::fake(null, ['url' => 'https://cdn.example.com']);

    $result = createApiTestToken();
    $this->user = $result['user'];
    $this->workspace = $result['workspace'];
    $this->headers = ['Authorization' => 'Bearer '.$result['plain_token']];
    $this->tiktok = SocialAccount::factory()->tiktok()->create(['workspace_id' => $this->workspace->id]);

    Http::fake([
        'example.com/*' => Http::response(
            file_get_contents(base_path('tests/fixtures/1x1.png')),
            200,
            ['Content-Type' => 'image/png'],
        ),
    ]);
});

test('an image url on a tiktok post is hosted and makes a tiktok photo', function () {
    $response = $this->postJson(route('api.posts.store'), [
        'social_account_id' => $this->tiktok->id,
        'media' => [['url' => 'https://example.com/a.png']],
    ], $this->headers);

    $response->assertCreated();

    $post = Post::query()->findOrFail($response->json('id'));

    expect($post->media)->toHaveCount(1)
        ->and($post->content_type)->toBe(ContentType::TikTokPhoto);
});

test('an image url attached to a tiktok post is added to the post', function () {
    $post = Post::factory()->forAccount($this->tiktok, ContentType::TikTokPhoto)->create(['user_id' => $this->user->id]);

    $this->postJson(route('api.posts.attach-media-from-url', $post), [
        'urls' => [['url' => 'https://example.com/b.png']],
    ], $this->headers)->assertSuccessful();

    expect($post->fresh()->media)->toHaveCount(1);
});

test('the content types listing says tiktok takes images and videos', function () {
    $tiktok = collect($this->getJson(route('api.content-types'), $this->headers)->assertOk()->json('platforms'))
        ->firstWhere('platform', Platform::TikTok->value);

    expect($tiktok['allowed_media_types'])->toEqualCanonicalizing(['image', 'video']);
});

test('a tiktok video still refuses an image when it is scheduled', function () {
    $this->postJson(route('api.posts.store'), [
        'status' => 'scheduled',
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'social_account_id' => $this->tiktok->id,
        'content_type' => ContentType::TikTokVideo->value,
        'meta' => ['privacy_level' => 'SELF_ONLY'],
        'media' => [['url' => 'https://example.com/c.png']],
    ], $this->headers)->assertUnprocessable();
});
