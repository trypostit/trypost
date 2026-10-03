<?php

declare(strict_types=1);

use App\Dto\MediaItem;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\PostPlatform\Status as PlatformStatus;
use App\Enums\SocialAccount\Platform;
use App\Jobs\PublishToSocialPlatform;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\InstagramPublisher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Mail::fake();
    Storage::fake();
    Http::fake();

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->account = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    $this->post = Post::factory()->scheduled()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
    $this->media = Media::factory()->stored()->ownedByPost($this->post)->create([
        'meta' => ['width' => 1080, 'height' => 1350],
    ]);
    $this->post->update(['media' => [MediaItem::fromMedia($this->media)->toArray()]]);
    $this->postPlatform = PostPlatform::factory()->instagram()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->account->id,
        'content_type' => ContentType::InstagramFeed,
        'enabled' => true,
    ]);
});

test('a scheduled instagram feed image that became too wide fails without calling instagram', function () {
    $this->media->update(['meta' => ['width' => 2000, 'height' => 1000]]);

    $publisher = Mockery::mock(InstagramPublisher::class);
    $publisher->shouldNotReceive('publish');
    $this->app->instance(InstagramPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->postPlatform))->handle();

    $platform = $this->postPlatform->fresh();
    $message = trans('posts.form.warnings.aspect_ratio_too_wide', [
        'destination' => ContentType::InstagramFeed->destinationLabel(),
        'current' => '2.00',
        'max' => '1.91',
    ]);

    expect($platform->status)->toBe(PlatformStatus::Failed)
        ->and($platform->error_message)->toBe($message)
        ->and(data_get($platform->error_context, 'reason'))->toBe('media_invalid')
        ->and($this->post->fresh()->status)->toBe(PostStatus::Failed);

    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET'
        && (str_starts_with($request->url(), (string) config('trypost.platforms.instagram.graph_api'))
            || str_starts_with($request->url(), (string) config('trypost.platforms.instagram-facebook.graph_api'))));
});

test('a still valid instagram feed image reaches the publisher', function () {
    $publisher = Mockery::mock(InstagramPublisher::class);
    $publisher->shouldReceive('publish')->once()->andReturn(['id' => 'ig-1', 'url' => 'https://instagram.com/p/ig-1']);
    $this->app->instance(InstagramPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->postPlatform))->handle();

    expect($this->postPlatform->fresh()->status)->toBe(PlatformStatus::Published)
        ->and($this->account->platform)->toBe(Platform::Instagram);
});

test('a resume of a publish the provider already accepted is not rechecked', function () {
    $this->media->update(['meta' => ['width' => 2000, 'height' => 1000]]);
    $this->postPlatform->update(['error_context' => [
        'category' => 'platform_unavailable',
        'instagram_workflow' => ['stage' => 'final_container', 'container_id' => 'container-1'],
    ]]);

    $publisher = Mockery::mock(InstagramPublisher::class);
    $publisher->shouldReceive('publish')->once()->andReturn(['id' => 'ig-1', 'url' => 'https://instagram.com/p/ig-1']);
    $this->app->instance(InstagramPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->postPlatform))->handle();

    expect($this->postPlatform->fresh()->status)->toBe(PlatformStatus::Published);
});

test('a too-wide image the publisher crops to the post aspect ratio still publishes', function () {
    $this->media->update(['meta' => ['width' => 2000, 'height' => 1000]]);
    $this->postPlatform->update(['meta' => ['aspect_ratio' => '1:1']]);

    $publisher = Mockery::mock(InstagramPublisher::class);
    $publisher->shouldReceive('publish')->once()->andReturn(['id' => 'ig-1', 'url' => 'https://instagram.com/p/ig-1']);
    $this->app->instance(InstagramPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->postPlatform))->handle();

    expect($this->postPlatform->fresh()->status)->toBe(PlatformStatus::Published);
});

test('a too-wide image set to its original aspect ratio fails the recheck', function () {
    $this->media->update(['meta' => ['width' => 2000, 'height' => 1000]]);
    $this->postPlatform->update(['meta' => ['aspect_ratio' => 'original']]);

    $publisher = Mockery::mock(InstagramPublisher::class);
    $publisher->shouldNotReceive('publish');
    $this->app->instance(InstagramPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->postPlatform))->handle();

    expect($this->postPlatform->fresh()->status)->toBe(PlatformStatus::Failed);
});
