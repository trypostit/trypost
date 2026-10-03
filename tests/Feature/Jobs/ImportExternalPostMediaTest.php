<?php

declare(strict_types=1);

use App\Dto\RemoteFile;
use App\Enums\Analytics\PublicationContentType;
use App\Enums\PostPlatform\ContentType;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Jobs\Media\ImportRemoteMedia;
use App\Jobs\Post\ImportExternalPostMedia;
use App\Models\AnalyticsPublication;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Services\Post\ExternalMedia\ExternalMediaResolver;
use App\Services\Post\ExternalMedia\ExternalMediaResolverFactory;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;

beforeEach(function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    Storage::fake();
    fakePublicDns();
    $this->png = file_get_contents(base_path('tests/fixtures/1x1.png'));
    $this->account = SocialAccount::factory()->instagram()->create();
});

function importedPostWithPublication(SocialAccount $account, array $publication = []): Post
{
    $post = Post::factory()->imported()->create(['workspace_id' => $account->workspace_id]);
    $target = PostPlatform::factory()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
        'content_type' => ContentType::defaultFor($account->platform),
        'platform_post_id' => 'remote-1',
    ]);
    AnalyticsPublication::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'post_platform_id' => $target->id,
        'platform' => $account->platform,
        'network' => $account->platform->network(),
        'remote_id' => 'remote-1',
        'content_type' => PublicationContentType::Carousel,
        'preview_metadata' => ['thumbnail_url' => 'https://cdn.example.test/cover.png'],
        ...$publication,
    ]);

    return $post;
}

/**
 * @param  list<RemoteFile>  ...$responses
 */
function bindExternalFiles(array ...$responses): MockInterface
{
    $resolver = Mockery::mock(ExternalMediaResolver::class);
    $resolver->shouldReceive('files')->andReturn(...$responses);
    $factory = Mockery::mock(ExternalMediaResolverFactory::class);
    $factory->shouldReceive('for')->andReturn($resolver);
    app()->instance(ExternalMediaResolverFactory::class, $factory);

    return $resolver;
}

function runExternalMediaJob(Post $post): void
{
    app()->call([new ImportExternalPostMedia($post->id), 'handle']);
}

/**
 * @return list<string|null>
 */
function importedMediaNames(Post $post): array
{
    return collect($post->fresh()->media)->pluck('original_filename')->all();
}

test('the job runs on the media imports queue once per post', function () {
    $job = new ImportExternalPostMedia('post-1');

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->queue)->toBe(ImportRemoteMedia::QUEUE)
        ->and($job->uniqueId())->toBe('post-1')
        ->and($job->maxExceptions)->toBe(3)
        ->and($job->failOnTimeout)->toBeTrue();
});

test('carousel files are adopted in network order', function () {
    Http::fake([
        'https://cdn.example.test/first.png' => Http::response($this->png),
        'https://cdn.example.test/second.png' => Http::response($this->png),
    ]);
    bindExternalFiles([
        RemoteFile::fromUrl('https://cdn.example.test/first.png'),
        RemoteFile::fromUrl('https://cdn.example.test/second.png'),
    ]);
    $post = importedPostWithPublication($this->account);

    runExternalMediaJob($post);

    expect(importedMediaNames($post))->toBe(['first.png', 'second.png'])
        ->and(Media::query()->where('post_id', $post->id)->count())->toBe(2);
});

test('a video file is adopted as a video', function () {
    Http::fake(['https://cdn.example.test/clip.mp4' => Http::response(file_get_contents(base_path('tests/fixtures/sample.mp4')))]);
    bindExternalFiles([RemoteFile::fromUrl('https://cdn.example.test/clip.mp4')]);
    $post = importedPostWithPublication($this->account, ['content_type' => PublicationContentType::Reel]);

    runExternalMediaJob($post);

    expect(collect($post->fresh()->media)->pluck('type')->all())->toBe(['video']);
});

test('the cover is used when the network exposes no file', function () {
    Http::fake(['https://cdn.example.test/cover.png' => Http::response($this->png)]);
    bindExternalFiles([]);
    $post = importedPostWithPublication($this->account);

    runExternalMediaJob($post);

    expect(importedMediaNames($post))->toBe(['cover.png']);
});

test('the cover is used when the file is over the size limit', function () {
    config()->set('trypost.media.max_size_mb.video', 0);
    Http::fake([
        'https://cdn.example.test/clip.mp4' => Http::response(file_get_contents(base_path('tests/fixtures/sample.mp4'))),
        'https://cdn.example.test/cover.png' => Http::response($this->png),
    ]);
    bindExternalFiles([RemoteFile::fromUrl('https://cdn.example.test/clip.mp4')]);
    $post = importedPostWithPublication($this->account, ['content_type' => PublicationContentType::Reel]);

    runExternalMediaJob($post);

    expect(importedMediaNames($post))->toBe(['cover.png']);
});

test('the cover is used when the file type is not allowed', function () {
    Http::fake([
        'https://cdn.example.test/file.pdf' => Http::response("%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF"),
        'https://cdn.example.test/cover.png' => Http::response($this->png),
    ]);
    bindExternalFiles([RemoteFile::fromUrl('https://cdn.example.test/file.pdf')]);
    $post = importedPostWithPublication($this->account);

    runExternalMediaJob($post);

    expect(importedMediaNames($post))->toBe(['cover.png']);
});

test('an expired file url asks the network for a fresh list once', function () {
    Http::fake([
        'https://cdn.example.test/expired.png' => Http::response('', 403),
        'https://cdn.example.test/fresh.png' => Http::response($this->png),
    ]);
    $resolver = bindExternalFiles(
        [RemoteFile::fromUrl('https://cdn.example.test/expired.png')],
        [RemoteFile::fromUrl('https://cdn.example.test/fresh.png')],
    );
    $post = importedPostWithPublication($this->account);

    runExternalMediaJob($post);

    expect(importedMediaNames($post))->toBe(['fresh.png']);
    $resolver->shouldHaveReceived('files')->twice();
});

test('a file that keeps failing falls back to the cover after one refresh', function () {
    Http::fake([
        'https://cdn.example.test/gone.png' => Http::response('', 403),
        'https://cdn.example.test/cover.png' => Http::response($this->png),
    ]);
    $resolver = bindExternalFiles([RemoteFile::fromUrl('https://cdn.example.test/gone.png')]);
    $post = importedPostWithPublication($this->account);

    runExternalMediaJob($post);

    expect(importedMediaNames($post))->toBe(['cover.png']);
    $resolver->shouldHaveReceived('files')->twice();
});

test('the cover still downloads after the network files used up their time', function () {
    Http::fake([
        'https://cdn.example.test/slow.png' => function () {
            $this->travel(ImportRemoteMedia::timeoutSeconds())->seconds();

            return Http::response($this->png);
        },
        'https://cdn.example.test/cover.png' => Http::response($this->png),
    ]);
    bindExternalFiles([RemoteFile::fromUrl('https://cdn.example.test/slow.png')]);
    $post = importedPostWithPublication($this->account);

    runExternalMediaJob($post);

    expect(importedMediaNames($post))->toBe(['cover.png']);
});

test('a refreshed list shorter than the first one stops at its end', function () {
    Http::fake([
        'https://cdn.example.test/expired.png' => Http::response('', 403),
        'https://cdn.example.test/second.png' => Http::response($this->png),
        'https://cdn.example.test/fresh.png' => Http::response($this->png),
    ]);
    bindExternalFiles(
        [RemoteFile::fromUrl('https://cdn.example.test/expired.png'), RemoteFile::fromUrl('https://cdn.example.test/second.png')],
        [RemoteFile::fromUrl('https://cdn.example.test/fresh.png')],
    );
    $post = importedPostWithPublication($this->account);

    runExternalMediaJob($post);

    expect(importedMediaNames($post))->toBe(['fresh.png']);
});

test('a carousel keeps the files that downloaded', function () {
    Http::fake([
        'https://cdn.example.test/ok.png' => Http::response($this->png),
        'https://cdn.example.test/broken.png' => Http::response('', 500),
    ]);
    bindExternalFiles([
        RemoteFile::fromUrl('https://cdn.example.test/broken.png'),
        RemoteFile::fromUrl('https://cdn.example.test/ok.png'),
    ]);
    $post = importedPostWithPublication($this->account);

    runExternalMediaJob($post);

    expect(importedMediaNames($post))->toBe(['ok.png']);
});

test('a private address is refused and the cover is used', function () {
    Http::fake(['https://cdn.example.test/cover.png' => Http::response($this->png)]);
    bindExternalFiles([RemoteFile::fromUrl('http://127.0.0.1/secret.png')]);
    $post = importedPostWithPublication($this->account);

    runExternalMediaJob($post);

    expect(importedMediaNames($post))->toBe(['cover.png']);
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '127.0.0.1'));
});

test('a post that already has media is left alone', function () {
    Http::fake();
    $resolver = bindExternalFiles([RemoteFile::fromUrl('https://cdn.example.test/first.png')]);
    $post = importedPostWithPublication($this->account);
    $post->update(['media' => [['id' => 'kept', 'path' => 'p', 'url' => 'u', 'type' => 'image']]]);

    runExternalMediaJob($post);

    $resolver->shouldNotHaveReceived('files');
    Http::assertNothingSent();
});

test('text link and poll publications get no media at all', function (PublicationContentType $type) {
    Http::fake();
    $resolver = bindExternalFiles([]);
    $post = importedPostWithPublication($this->account, ['content_type' => $type]);

    runExternalMediaJob($post);

    expect($post->fresh()->media)->toBe([]);
    $resolver->shouldNotHaveReceived('files');
    Http::assertNothingSent();
})->with([PublicationContentType::Text, PublicationContentType::Link, PublicationContentType::Poll]);

test('a post whose account is gone uses the cover without asking the network', function () {
    Http::fake(['https://cdn.example.test/cover.png' => Http::response($this->png)]);
    $resolver = bindExternalFiles([]);
    $post = importedPostWithPublication($this->account);
    $post->postPlatforms()->update(['social_account_id' => null]);

    runExternalMediaJob($post);

    expect(importedMediaNames($post))->toBe(['cover.png']);
    $resolver->shouldNotHaveReceived('files');
});

test('a transient network error is retried and a permanent one falls back to the cover', function () {
    Http::fake(['https://cdn.example.test/cover.png' => Http::response($this->png)]);
    $post = importedPostWithPublication($this->account);

    $resolver = Mockery::mock(ExternalMediaResolver::class);
    $resolver->shouldReceive('files')->once()->andThrow(new RequestException(new Response(new Psr7Response(503))));
    $factory = Mockery::mock(ExternalMediaResolverFactory::class);
    $factory->shouldReceive('for')->andReturn($resolver);
    app()->instance(ExternalMediaResolverFactory::class, $factory);

    expect(fn () => runExternalMediaJob($post))->toThrow(RequestException::class)
        ->and($post->fresh()->media)->toBe([]);

    $resolver->shouldReceive('files')->once()->andThrow(new RequestException(new Response(new Psr7Response(404))));

    runExternalMediaJob($post);

    expect(importedMediaNames($post))->toBe(['cover.png']);
});

test('a job that exhausts its retries still attaches the cover', function () {
    Http::fake(['https://cdn.example.test/cover.png' => Http::response($this->png)]);
    $post = importedPostWithPublication($this->account);

    (new ImportExternalPostMedia($post->id))->failed(new RuntimeException('gave up'));

    expect(importedMediaNames($post))->toBe(['cover.png']);
});

test('jobs that ask the network for files use their own per network limiter', function (string $state, ?string $limiter) {
    $post = importedPostWithPublication(SocialAccount::factory()->{$state}()->create());

    $limiters = collect((new ImportExternalPostMedia($post->id))->middleware())
        ->map(fn (RateLimited $middleware): string => (fn (): string => $this->limiterName)->call($middleware))
        ->all();

    expect($limiters)->toBe($limiter === null ? [] : [$limiter]);
})->with([
    'instagram' => ['instagram', 'external-post-media'],
    'facebook' => ['facebook', 'external-post-media'],
    'x reads stored metadata' => ['x', null],
    'tiktok is cover only' => ['tiktok', null],
]);

test('the media limiter is keyed by network and sized by config', function () {
    config()->set('trypost.external_posts.media_requests_per_network_per_minute', 7);
    $post = importedPostWithPublication($this->account);

    $limit = RateLimiter::limiter('external-post-media')(new ImportExternalPostMedia($post->id));

    expect($limit->maxAttempts)->toBe(7)
        ->and($limit->decaySeconds)->toBe(60)
        ->and($limit->key)->toBe($this->account->platform->network());
});

test('the limiter middleware and its key read the post platform once per attempt', function () {
    $post = importedPostWithPublication($this->account);
    $job = new ImportExternalPostMedia($post->id);
    $reads = 0;
    DB::listen(function ($query) use (&$reads): void {
        $reads += str_contains($query->sql, 'post_platforms') ? 1 : 0;
    });

    $job->middleware();
    RateLimiter::limiter('external-post-media')($job);

    expect($reads)->toBe(1);
});
