<?php

declare(strict_types=1);

use App\Enums\TikTok\PrivacyLevel;
use App\Exceptions\PlatformUnavailableException;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\TikTokCreatorInfo;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->account = SocialAccount::factory()->tiktok()->create([
        'workspace_id' => $this->workspace->id,
        'token_expires_at' => now()->addDays(1),
    ]);

    $this->service = new TikTokCreatorInfo;

    $this->api = config('trypost.platforms.tiktok.api');
});

test('it returns full creator payload from api response', function () {
    Http::fake([
        $this->api.'/post/publish/creator_info/query/' => Http::response([
            'data' => [
                'creator_nickname' => 'Paulo',
                'creator_username' => 'paulocastellano',
                'creator_avatar_url' => 'https://cdn.tiktok.com/avatar.jpg',
                'privacy_level_options' => [
                    PrivacyLevel::PublicToEveryone->value,
                    PrivacyLevel::MutualFollowFriends->value,
                    PrivacyLevel::SelfOnly->value,
                ],
                'comment_disabled' => false,
                'duet_disabled' => true,
                'stitch_disabled' => true,
                'max_video_post_duration_sec' => 600,
            ],
        ], 200),
    ]);

    $info = $this->service->fetch($this->account);

    expect($info['creator_nickname'])->toBe('Paulo')
        ->and($info['creator_username'])->toBe('paulocastellano')
        ->and($info['creator_avatar_url'])->toBe('https://cdn.tiktok.com/avatar.jpg')
        ->and($info['privacy_level_options'])->toBe([
            PrivacyLevel::PublicToEveryone->value,
            PrivacyLevel::MutualFollowFriends->value,
            PrivacyLevel::SelfOnly->value,
        ])
        ->and($info['comment_disabled'])->toBeFalse()
        ->and($info['duet_disabled'])->toBeTrue()
        ->and($info['stitch_disabled'])->toBeTrue()
        ->and($info['max_video_post_duration_sec'])->toBe(600);
});

test('it sends an empty json object as the request body', function () {
    Http::fake([
        $this->api.'/post/publish/creator_info/query/' => Http::response(['data' => []], 200),
    ]);

    $this->service->fetch($this->account);

    Http::assertSent(function ($request) {
        return $request->body() === '{}';
    });
});

test('it returns an empty payload when the api fails', function () {
    Http::fake([
        $this->api.'/post/publish/creator_info/query/' => Http::response(['error' => 'unauthorized'], 401),
    ]);

    $info = $this->service->fetch($this->account);

    expect($info['creator_nickname'])->toBeNull()
        ->and($info['privacy_level_options'])->toBe([])
        ->and($info['comment_disabled'])->toBeFalse()
        ->and($info['duet_disabled'])->toBeFalse()
        ->and($info['stitch_disabled'])->toBeFalse()
        ->and($info['max_video_post_duration_sec'])->toBeNull();
});

test('it refreshes the token before calling when expired', function () {
    $this->account->update(['token_expires_at' => now()->subMinute()]);

    Http::fake([
        $this->api.'/oauth/token/' => Http::response([
            'access_token' => 'new-token',
            'refresh_token' => 'new-refresh',
            'expires_in' => 3600,
        ], 200),
        $this->api.'/post/publish/creator_info/query/' => Http::response([
            'data' => [
                'privacy_level_options' => [PrivacyLevel::PublicToEveryone->value],
            ],
        ], 200),
    ]);

    $this->service->fetch($this->account);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/oauth/token/'));
    expect($this->account->fresh()->access_token)->toBe('new-token');
});

test('it drops unknown privacy options returned by creator info', function () {
    Http::fake([
        $this->api.'/post/publish/creator_info/query/' => Http::response([
            'data' => [
                'privacy_level_options' => [
                    PrivacyLevel::PublicToEveryone->value,
                    'EVERYONE',
                    PrivacyLevel::SelfOnly->value,
                ],
            ],
        ], 200),
    ]);

    $info = $this->service->fetch($this->account);

    expect($info['privacy_level_options'])->toBe([
        PrivacyLevel::PublicToEveryone->value,
        PrivacyLevel::SelfOnly->value,
    ]);
});

test('a refusal tiktok reports with http 200 is read from error.code', function (string $code) {
    Http::fake([
        $this->api.'/post/publish/creator_info/query/' => Http::response([
            'data' => [],
            'error' => ['code' => $code, 'message' => 'Limit', 'log_id' => 'log123'],
        ], 200),
    ]);

    expect(fn () => $this->service->fetchOrFail($this->account))
        ->toThrow(fn (PlatformUnavailableException $exception) => expect($exception->httpStatus)->toBe(Response::HTTP_TOO_MANY_REQUESTS));
})->with(['spam_risk_too_many_posts', 'reached_active_user_cap']);

test('a creator banned from posting with http 200 cannot post', function () {
    Http::fake([
        $this->api.'/post/publish/creator_info/query/' => Http::response([
            'data' => [],
            'error' => ['code' => 'spam_risk_user_banned_from_posting', 'message' => 'Banned', 'log_id' => 'log123'],
        ], 200),
    ]);

    expect($this->service->fetchOrFail($this->account)['privacy_level_options'])->toBe([]);
});

test('an ok error code with data is a successful answer', function () {
    Http::fake([
        $this->api.'/post/publish/creator_info/query/' => Http::response([
            'data' => ['privacy_level_options' => [PrivacyLevel::SelfOnly->value]],
            'error' => ['code' => 'ok', 'message' => '', 'log_id' => 'log123'],
        ], 200),
    ]);

    expect($this->service->fetchOrFail($this->account)['privacy_level_options'])->toBe([PrivacyLevel::SelfOnly->value]);
});
