<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\Vk\VkAnalytics;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->account = SocialAccount::factory()->vk()->create(['workspace_id' => $this->workspace->id]);
    $this->analytics = new VkAnalytics;
    $this->api = rtrim((string) config('trypost.platforms.vk.api'), '/');
});

test('vk analytics returns the community member count', function () {
    Http::fake([
        "{$this->api}/groups.getById*" => Http::response([
            'response' => ['groups' => [['id' => 123456, 'members_count' => 4321]]],
        ], 200),
    ]);

    $metrics = $this->analytics->getMetrics($this->account);

    expect($metrics)->toHaveCount(1)
        ->and($metrics[0]['value'])->toBe(4321);
});

test('vk analytics returns post views, likes, reposts and comments', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'content' => 'x',
    ]);
    $row = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->account->id,
        'platform' => Platform::Vk,
        'content_type' => ContentType::VkPost,
        'platform_post_id' => '42',
    ]);

    Http::fake([
        "{$this->api}/wall.getById*" => Http::response([
            'response' => ['items' => [[
                'views' => ['count' => 100],
                'likes' => ['count' => 10],
                'reposts' => ['count' => 3],
                'comments' => ['count' => 5],
            ]]],
        ], 200),
    ]);

    $metrics = $this->analytics->fetchPostMetrics($row);

    expect(array_column($metrics, 'value'))->toBe([100, 10, 3, 5]);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/wall.getById')
        && $request['posts'] === '-123456_42');
});

test('vk analytics returns empty metrics on api error', function () {
    Http::fake([
        "{$this->api}/groups.getById*" => Http::response([
            'error' => ['error_code' => 5, 'error_msg' => 'auth failed'],
        ], 200),
    ]);

    expect($this->analytics->getMetrics($this->account))->toBe([]);
});
