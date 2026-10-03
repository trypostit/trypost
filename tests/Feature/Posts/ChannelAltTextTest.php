<?php

declare(strict_types=1);

use App\Actions\Post\UpdatePost;
use App\Dto\MediaItem;
use App\Enums\PostPlatform\ContentType;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Media\MediaOptimizer;
use App\Services\Social\BlueskyPublisher;
use App\Services\Social\LinkCard\LinkCardFetcher;
use App\Services\Social\XPublisher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id, 'account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $this->x = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id, 'token_expires_at' => now()->addHours(2)]);
    $this->bluesky = SocialAccount::factory()->bluesky()->create(['workspace_id' => $this->workspace->id, 'platform_user_id' => 'did:plc:alt']);
    $this->mastodon = SocialAccount::factory()->mastodon()->create(['workspace_id' => $this->workspace->id]);

    $this->upload = Media::factory()->temporaryUpload($this->workspace)->create([
        'type' => 'image',
        'mime_type' => 'image/png',
        'original_filename' => 'photo.png',
    ]);
    Storage::put($this->upload->path, (string) file_get_contents(base_path('tests/fixtures/1x1.png')));
});

/**
 * @param  array<string, mixed>  $meta
 * @return array<string, mixed>
 */
function channelAltTextItem(Media $media, array $meta = []): array
{
    $item = MediaItem::fromMedia($media)->toArray();

    return $meta === [] ? $item : [...$item, 'meta' => [...(array) data_get($item, 'meta', []), ...$meta]];
}

function channelAltTextPost(SocialAccount $account): Post
{
    return Post::query()
        ->whereHas('postPlatforms', fn ($query) => $query->where('social_account_id', $account->id))
        ->sole();
}

/**
 * Stores one composition from the composer: a shared alt text, X keeping it,
 * Bluesky overriding it and Mastodon customizing its media without an alt.
 */
function storeChannelAltTextComposition(object $test): void
{
    $test->actingAs($test->user)->post(route('app.posts.store'), [
        'status' => 'draft',
        'content' => 'Alt text per channel',
        'media' => [channelAltTextItem($test->upload, ['alt_text' => 'Shared description'])],
        'destinations' => [
            ['social_account_id' => $test->x->id, 'content_type' => ContentType::XPost->value, 'meta' => []],
            [
                'social_account_id' => $test->bluesky->id,
                'content_type' => ContentType::BlueskyPost->value,
                'meta' => [],
                'media' => [channelAltTextItem($test->upload, ['alt_text' => 'Bluesky description'])],
            ],
            [
                'social_account_id' => $test->mastodon->id,
                'content_type' => ContentType::MastodonPost->value,
                'meta' => [],
                'media' => [channelAltTextItem($test->upload)],
            ],
        ],
    ])->assertRedirect(route('app.posts.index'));
}

test('the shared alt text reaches every channel and a channel override stays on its own post', function () {
    storeChannelAltTextComposition($this);

    expect(data_get(channelAltTextPost($this->x)->media, '0.meta.alt_text'))->toBe('Shared description')
        ->and(data_get(channelAltTextPost($this->bluesky)->media, '0.meta.alt_text'))->toBe('Bluesky description')
        ->and(data_get(channelAltTextPost($this->mastodon)->media, '0.meta.alt_text'))->toBe('Shared description')
        ->and(Post::query()->count())->toBe(3);
});

test('editing one channel post changes only that channel alt text', function () {
    storeChannelAltTextComposition($this);
    $post = channelAltTextPost($this->x);

    UpdatePost::execute($this->workspace, $post, [
        'media' => [[...$post->media[0], 'meta' => [...$post->media[0]['meta'], 'alt_text' => 'X description']]],
    ]);

    expect(data_get($post->fresh()->media, '0.meta.alt_text'))->toBe('X description')
        ->and(data_get(channelAltTextPost($this->bluesky)->media, '0.meta.alt_text'))->toBe('Bluesky description')
        ->and(data_get(channelAltTextPost($this->mastodon)->media, '0.meta.alt_text'))->toBe('Shared description');
});

test('each publisher sends the alt text of its own channel', function () {
    storeChannelAltTextComposition($this);

    $this->mock(LinkCardFetcher::class)->shouldReceive('fetch')->andReturn(null);
    $this->mock(MediaOptimizer::class)->shouldReceive('optimizeImage')->andReturnUsing(function (string $file): string {
        $copy = tempnam(sys_get_temp_dir(), 'alt_');
        copy($file, $copy);

        return $copy;
    });

    Http::fake(function (Request $request) {
        $url = $request->url();

        return match (true) {
            str_contains($url, '/media/metadata') => Http::response([], 200),
            str_contains($url, '/media/upload') => Http::response(['data' => ['id' => 'x-media']], 200),
            str_contains($url, '/2/tweets') => Http::response(['data' => ['id' => '1', 'text' => 'Alt text per channel']], 200),
            str_contains($url, 'uploadBlob') => Http::response(['blob' => ['$type' => 'blob', 'ref' => ['$link' => 'bafk'], 'mimeType' => 'image/png', 'size' => 68]], 200),
            str_contains($url, 'createRecord') => Http::response(['uri' => 'at://did:plc:alt/app.bsky.feed.post/1', 'cid' => 'bafy'], 200),
            default => Http::response((string) file_get_contents(base_path('tests/fixtures/1x1.png')), 200, ['Content-Type' => 'image/png']),
        };
    });

    (new XPublisher)->publish(channelAltTextPost($this->x)->postPlatforms()->sole());
    (new BlueskyPublisher)->publish(channelAltTextPost($this->bluesky)->postPlatforms()->sole());

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/media/metadata')
        && data_get($request->data(), 'metadata.alt_text.text') === 'Shared description');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'createRecord')
        && data_get($request->data(), 'record.embed.images.0.alt') === 'Bluesky description');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/media/metadata')
        && data_get($request->data(), 'metadata.alt_text.text') === 'Bluesky description');
});
