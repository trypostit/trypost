<?php

declare(strict_types=1);

use App\Enums\Media\Source;
use App\Enums\Post\CreatedVia;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Enums\Webhook\EventType as WebhookEvent;
use App\Jobs\DispatchWebhook;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Webhook;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use App\Services\WebhookService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->service = app(WebhookService::class);
});

test('ping sends a signed POST request to the endpoint', function () {
    Http::fake([
        'https://example.com/hook' => Http::response([], 200),
    ]);

    $secret = 'whsec_test_secret';

    $this->service->ping('https://example.com/hook', $secret);

    Http::assertSent(function ($request) use ($secret) {
        $body = $request->data();
        $raw = $request->body();

        return $request->url() === 'https://example.com/hook'
            && $request->method() === 'POST'
            && $body['type'] === 'webhook.test'
            && str_contains($raw, '"data":{}')
            && isset($body['id'], $body['created_at'])
            && $request->hasHeader('X-Webhook-Signature')
            && $request->header('X-Webhook-Signature')[0] === hash_hmac('sha256', $raw, $secret);
    });
});

test('ping throws when endpoint is unreachable', function () {
    Http::fake([
        'https://example.com/unreachable' => Http::throw(fn () => throw new ConnectionException('Connection refused')),
    ]);

    $this->service->ping('https://example.com/unreachable', 'whsec_test_secret');
})->throws(RuntimeException::class, 'The endpoint is not reachable.');

test('ping throws when endpoint returns non-200', function () {
    Http::fake([
        'https://example.com/hook' => Http::response([], 500),
    ]);

    $this->service->ping('https://example.com/hook', 'whsec_test_secret');
})->throws(RuntimeException::class, 'The endpoint returned HTTP 500.');

test('ping rejects private network endpoints', function () {
    $this->service->ping('http://127.0.0.1/hook', 'whsec_test_secret');
})->throws(RuntimeException::class, 'This endpoint is not allowed.');

test('assertEndpointAllowed rejects private network endpoints', function () {
    $this->service->assertEndpointAllowed('http://127.0.0.1/hook');
})->throws(RuntimeException::class, 'This endpoint is not allowed.');

test('dispatch dispatches DispatchWebhook for matching webhooks', function () {
    Queue::fake();

    Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
        'events' => [WebhookEvent::PostPublished->value, WebhookEvent::PostFailed->value],
    ]);

    $this->service->dispatch($this->workspace, WebhookEvent::PostPublished, ['foo' => 'bar']);

    Queue::assertPushed(DispatchWebhook::class);
});

test('dispatch does not dispatch for webhooks without matching events', function () {
    Queue::fake();

    Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
        'events' => [WebhookEvent::PostFailed->value],
    ]);

    $this->service->dispatch($this->workspace, WebhookEvent::PostPublished, ['foo' => 'bar']);

    Queue::assertNotPushed(DispatchWebhook::class);
});

test('dispatch does not dispatch for disabled webhooks', function () {
    Queue::fake();

    Webhook::factory()->disabled()->create([
        'workspace_id' => $this->workspace->id,
        'events' => [WebhookEvent::PostPublished->value],
    ]);

    $this->service->dispatch($this->workspace, WebhookEvent::PostPublished, ['foo' => 'bar']);

    Queue::assertNotPushed(DispatchWebhook::class);
});

test('dispatch does not dispatch for paused webhooks', function () {
    Queue::fake();

    Webhook::factory()->paused()->create([
        'workspace_id' => $this->workspace->id,
        'events' => [WebhookEvent::PostCreated->value, WebhookEvent::PostPublished->value],
    ]);

    $this->service->dispatch($this->workspace, WebhookEvent::PostCreated, ['foo' => 'bar']);

    Queue::assertNotPushed(DispatchWebhook::class);
});

test('dispatch does not match wildcard events', function () {
    Queue::fake();

    Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
        'events' => ['*'],
    ]);

    $this->service->dispatch($this->workspace, WebhookEvent::PostPublished, ['foo' => 'bar']);

    Queue::assertNotPushed(DispatchWebhook::class);
});

test('postPayload includes the post lifecycle fields', function () {
    $post = Post::factory()->published()->createQuietly([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'content' => 'Hello world',
        'created_via' => CreatedVia::Web,
    ]);

    $payload = $this->service->postPayload($post);

    expect($payload)->toEqual([
        'id' => $post->id,
        'workspace_id' => $post->workspace_id,
        'user_id' => $this->user->id,
        'status' => 'published',
        'created_via' => CreatedVia::Web->value,
        'content' => 'Hello world',
        'scheduled_at' => null,
        'published_at' => $post->published_at?->toIso8601String(),
        'created_at' => $post->created_at?->toIso8601String(),
        'updated_at' => $post->updated_at?->toIso8601String(),
        'author' => [
            'id' => $this->user->id,
            'name' => $this->user->name,
        ],
        'workspace' => [
            'id' => $this->workspace->id,
            'name' => $this->workspace->name,
        ],
        'labels' => [],
        'media' => [],
        'platforms' => [],
    ]);
});

test('postPayload matches the published webhook example', function () {
    $post = Post::factory()->published()->createQuietly([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'content' => '<p>Launch day. TryPost is live.</p>',
        'created_via' => CreatedVia::Web,
        'media' => [
            [
                'id' => 'm_01',
                'path' => 'medias/9f2c-hero.jpg',
                'url' => 'https://cdn.example.com/medias/9f2c-hero.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'hero.jpg',
                'source' => Source::Unsplash->value,
                'source_meta' => ['photographer' => 'Ada'],
                'meta' => [
                    'width' => 1080,
                    'height' => 1350,
                    'alt_text' => 'Product screenshot on a laptop',
                ],
            ],
            [
                'id' => 'm_02',
                'path' => 'medias/9f2c-clip.mp4',
                'url' => 'https://cdn.example.com/medias/9f2c-clip.mp4',
                'mime_type' => 'video/mp4',
                'original_filename' => 'clip.mp4',
                'source' => Source::Ai->value,
                'source_meta' => null,
                'meta' => [
                    'width' => 1080,
                    'height' => 1920,
                    'duration' => 18.4,
                ],
            ],
        ],
    ]);

    $label = WorkspaceLabel::factory()->recycle($this->workspace)->create([
        'name' => 'Launch',
        'color' => '#7C3AED',
    ]);
    $post->labels()->attach($label);

    $instagram = SocialAccount::factory()->instagram()->recycle($this->workspace)->create([
        'display_name' => 'TryPost',
        'username' => 'trypost',
        'avatar_url' => 'avatars/ig.jpg',
        'access_token' => 'secret-access-token',
        'refresh_token' => 'secret-refresh-token',
    ]);
    $linkedin = SocialAccount::factory()->linkedin()->recycle($this->workspace)->create([
        'display_name' => 'Paulo Castellano',
        'username' => 'paulocastellano',
        'avatar_url' => 'avatars/li.jpg',
    ]);
    $x = SocialAccount::factory()->x()->recycle($this->workspace)->create([
        'display_name' => 'TryPost',
        'username' => 'trypost',
        'avatar_url' => 'avatars/x.jpg',
    ]);

    $instagramPlatform = PostPlatform::factory()->published()->recycle($post, $instagram)->create([
        'platform' => Platform::Instagram,
        'content_type' => ContentType::InstagramFeed,
        'meta' => ['aspect_ratio' => '4:5'],
    ]);
    $linkedinPlatform = PostPlatform::factory()->published()->recycle($post, $linkedin)->create([
        'platform' => Platform::LinkedIn,
        'content_type' => ContentType::LinkedInPost,
        'meta' => ['document_title' => 'TryPost launch deck'],
    ]);
    $xPlatform = PostPlatform::factory()->published()->recycle($post, $x)->create([
        'platform' => Platform::X,
        'content_type' => ContentType::XPost,
        'meta' => [],
    ]);

    $payload = $this->service->postPayload($post->fresh());
    $platforms = collect($payload['platforms'])->keyBy('id');

    expect($payload)
        ->toHaveKeys([
            'id',
            'workspace_id',
            'user_id',
            'status',
            'created_via',
            'content',
            'scheduled_at',
            'published_at',
            'created_at',
            'updated_at',
            'author',
            'workspace',
            'labels',
            'media',
            'platforms',
        ])
        ->and($payload['id'])->toBe($post->id)
        ->and($payload['workspace_id'])->toBe($this->workspace->id)
        ->and($payload['user_id'])->toBe($this->user->id)
        ->and($payload['status'])->toBe('published')
        ->and($payload['created_via'])->toBe(CreatedVia::Web->value)
        ->and($payload['content'])->toBe('<p>Launch day. TryPost is live.</p>')
        ->and($payload['author'])->toEqual([
            'id' => $this->user->id,
            'name' => $this->user->name,
        ])
        ->and($payload['author'])->not->toHaveKey('email')
        ->and($payload['workspace'])->toEqual([
            'id' => $this->workspace->id,
            'name' => $this->workspace->name,
        ])
        ->and($payload['labels'])->toEqual([
            [
                'id' => $label->id,
                'name' => 'Launch',
                'color' => '#7C3AED',
            ],
        ])
        ->and($payload['media'])->toEqual([
            [
                'id' => 'm_01',
                'path' => 'medias/9f2c-hero.jpg',
                'url' => 'https://cdn.example.com/medias/9f2c-hero.jpg',
                'type' => 'image',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'hero.jpg',
                'source' => 'unsplash',
                'source_meta' => ['photographer' => 'Ada'],
                'meta' => [
                    'width' => 1080,
                    'height' => 1350,
                    'alt_text' => 'Product screenshot on a laptop',
                ],
            ],
            [
                'id' => 'm_02',
                'path' => 'medias/9f2c-clip.mp4',
                'url' => 'https://cdn.example.com/medias/9f2c-clip.mp4',
                'type' => 'video',
                'mime_type' => 'video/mp4',
                'original_filename' => 'clip.mp4',
                'source' => 'ai',
                'source_meta' => null,
                'meta' => [
                    'width' => 1080,
                    'height' => 1920,
                    'duration' => 18.4,
                ],
            ],
        ])
        ->and($payload['platforms'])->toHaveCount(3)
        ->and($platforms[$instagramPlatform->id])->toEqual([
            'id' => $instagramPlatform->id,
            'social_account_id' => $instagram->id,
            'platform' => Platform::Instagram->value,
            'content_type' => ContentType::InstagramFeed->value,
            'enabled' => true,
            'status' => 'published',
            'platform_post_id' => $instagramPlatform->platform_post_id,
            'platform_url' => $instagramPlatform->platform_url,
            'published_at' => $instagramPlatform->published_at?->toIso8601String(),
            'error_message' => null,
            'error_context' => null,
            'display_name' => 'TryPost',
            'display_username' => 'trypost',
            'display_avatar' => Storage::url('avatars/ig.jpg'),
            'meta' => ['aspect_ratio' => '4:5'],
            'social_account' => [
                'id' => $instagram->id,
                'platform' => Platform::Instagram->value,
                'display_name' => 'TryPost',
                'username' => 'trypost',
                'is_active' => true,
                'status' => 'connected',
            ],
        ])
        ->and($platforms[$linkedinPlatform->id])->toEqual([
            'id' => $linkedinPlatform->id,
            'social_account_id' => $linkedin->id,
            'platform' => Platform::LinkedIn->value,
            'content_type' => ContentType::LinkedInPost->value,
            'enabled' => true,
            'status' => 'published',
            'platform_post_id' => $linkedinPlatform->platform_post_id,
            'platform_url' => $linkedinPlatform->platform_url,
            'published_at' => $linkedinPlatform->published_at?->toIso8601String(),
            'error_message' => null,
            'error_context' => null,
            'display_name' => 'Paulo Castellano',
            'display_username' => 'paulocastellano',
            'display_avatar' => Storage::url('avatars/li.jpg'),
            'meta' => ['document_title' => 'TryPost launch deck'],
            'social_account' => [
                'id' => $linkedin->id,
                'platform' => Platform::LinkedIn->value,
                'display_name' => 'Paulo Castellano',
                'username' => 'paulocastellano',
                'is_active' => true,
                'status' => 'connected',
            ],
        ])
        ->and($platforms[$xPlatform->id])->toEqual([
            'id' => $xPlatform->id,
            'social_account_id' => $x->id,
            'platform' => Platform::X->value,
            'content_type' => ContentType::XPost->value,
            'enabled' => true,
            'status' => 'published',
            'platform_post_id' => $xPlatform->platform_post_id,
            'platform_url' => $xPlatform->platform_url,
            'published_at' => $xPlatform->published_at?->toIso8601String(),
            'error_message' => null,
            'error_context' => null,
            'display_name' => 'TryPost',
            'display_username' => 'trypost',
            'display_avatar' => Storage::url('avatars/x.jpg'),
            'meta' => [],
            'social_account' => [
                'id' => $x->id,
                'platform' => Platform::X->value,
                'display_name' => 'TryPost',
                'username' => 'trypost',
                'is_active' => true,
                'status' => 'connected',
            ],
        ])
        ->and(json_encode($payload))->not->toContain('secret-access-token')
        ->and(json_encode($payload))->not->toContain('secret-refresh-token')
        ->and(json_encode($payload))->not->toContain('access_token')
        ->and(json_encode($payload))->not->toContain('refresh_token');
});

test('postPayload includes failed platform errors', function () {
    $post = Post::factory()->published()->createQuietly([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $account = SocialAccount::factory()->tiktok()->recycle($this->workspace)->create();
    $platform = PostPlatform::factory()->failed()->recycle($post, $account)->create([
        'platform' => Platform::TikTok,
        'content_type' => ContentType::TikTokVideo,
        'meta' => ['privacy_level' => 'PUBLIC_TO_EVERYONE'],
        'error_context' => ['retry_count' => 2],
    ]);

    $payload = $this->service->postPayload($post->fresh());

    expect(data_get($payload, 'platforms.0.id'))->toBe($platform->id)
        ->and(data_get($payload, 'platforms.0.status'))->toBe('failed')
        ->and(data_get($payload, 'platforms.0.error_message'))->toBe('Failed to publish')
        ->and(data_get($payload, 'platforms.0.error_context'))->toEqual(['retry_count' => 2])
        ->and(data_get($payload, 'platforms.0.meta.privacy_level'))->toBe('PUBLIC_TO_EVERYONE');
});

test('postPayload accepts integer media ids from generated attachments', function () {
    $post = Post::factory()->published()->createQuietly([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'media' => [
            [
                'id' => 42,
                'path' => 'medias/generated.png',
                'url' => 'https://cdn.example.com/medias/generated.png',
                'mime_type' => 'image/png',
            ],
        ],
    ]);

    $payload = $this->service->postPayload($post);

    expect(data_get($payload, 'media.0.id'))->toBe('42')
        ->and(data_get($payload, 'media.0.type'))->toBe('image');
});

test('postPayload author is null when the post has no user', function () {
    $post = Post::factory()->published()->createQuietly([
        'workspace_id' => $this->workspace->id,
        'user_id' => null,
        'content' => 'Orphan post',
    ]);

    $payload = $this->service->postPayload($post);

    expect($payload['user_id'])->toBeNull()
        ->and($payload['author'])->toBeNull();
});

test('postPayload keeps display fields when the social account is gone', function () {
    $post = Post::factory()->published()->createQuietly([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $platform = PostPlatform::factory()->published()->recycle($post)->create([
        'social_account_id' => null,
        'platform' => Platform::X,
        'content_type' => ContentType::XPost,
        'platform_name' => 'TryPost',
        'platform_username' => 'trypost',
        'platform_avatar' => null,
    ]);

    $payload = $this->service->postPayload($post->fresh());

    expect(data_get($payload, 'platforms.0.id'))->toBe($platform->id)
        ->and(data_get($payload, 'platforms.0.social_account_id'))->toBeNull()
        ->and(data_get($payload, 'platforms.0.social_account'))->toBeNull()
        ->and(data_get($payload, 'platforms.0.display_name'))->toBe('TryPost')
        ->and(data_get($payload, 'platforms.0.display_username'))->toBe('trypost');
});
