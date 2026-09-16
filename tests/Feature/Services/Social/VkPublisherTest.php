<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\VkPublishException;
use App\Exceptions\TokenExpiredException;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\VkPublisher;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);

    $this->socialAccount = SocialAccount::factory()->vk()->create([
        'workspace_id' => $this->workspace->id,
        'username' => 'testcommunity',
    ]);

    $this->post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'content' => 'Hello from VK!',
    ]);

    $this->postPlatform = PostPlatform::factory()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->socialAccount->id,
        'platform' => Platform::Vk,
        'content_type' => ContentType::VkPost,
    ]);

    $this->publisher = new VkPublisher;
    $this->api = rtrim((string) config('trypost.platforms.vk.api'), '/');
});

test('vk publisher can publish text-only post to a community', function () {
    Http::fake([
        "{$this->api}/wall.post*" => Http::response([
            'response' => ['post_id' => 42],
        ], 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('42')
        ->and($result['url'])->toBe('https://vk.com/wall-123456_42');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/wall.post')
            && $request['owner_id'] == -123456
            && $request['from_group'] == 1
            && $request['message'] === 'Hello from VK!'
            && $request['v'] === config('trypost.platforms.vk.api_version');
    });
});

test('vk publisher posts to a profile wall without from_group', function () {
    $this->socialAccount->update([
        'platform_user_id' => '111',
        'meta' => ['owner_id' => 111, 'is_group' => false, 'vk_user_id' => 111],
    ]);
    $this->postPlatform->refresh();

    Http::fake([
        "{$this->api}/wall.post*" => Http::response([
            'response' => ['post_id' => 7],
        ], 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['url'])->toBe('https://vk.com/wall111_7');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/wall.post')
            && $request['owner_id'] == 111
            && ! isset($request['from_group']);
    });
});

test('vk publisher throws token expired exception on dead token', function () {
    Http::fake([
        "{$this->api}/wall.post*" => Http::response([
            'error' => [
                'error_code' => 5,
                'error_msg' => 'User authorization failed: invalid access_token.',
            ],
        ], 200),
    ]);

    $this->publisher->publish($this->postPlatform);
})->throws(TokenExpiredException::class);

test('vk publisher throws publish exception on api error', function () {
    Http::fake([
        "{$this->api}/wall.post*" => Http::response([
            'error' => [
                'error_code' => 214,
                'error_msg' => 'Access to adding post denied.',
            ],
        ], 200),
    ]);

    try {
        $this->publisher->publish($this->postPlatform);
        $this->fail('Expected VkPublishException');
    } catch (VkPublishException $e) {
        expect($e->platformErrorCode)->toBe('214')
            ->and($e->platform())->toBe('vk');
    }
});

test('vk publisher rejects content over the platform limit', function () {
    $this->post->update(['content' => str_repeat('a', Platform::Vk->maxContentLength() + 1)]);
    $this->postPlatform->refresh();

    Http::fake();

    $this->publisher->publish($this->postPlatform);
})->throws(Exception::class, 'Content exceeds VK limit');
