<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status as AccountStatus;
use App\Enums\UserWorkspace\Role;
use App\Exceptions\TokenExpiredException;
use App\Models\Account;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\YouTubeAnalytics;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create([]);
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
    $this->user->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);

    $this->youtubeAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::YouTube,
        'platform_user_id' => 'UC_test_channel_123',
        'username' => 'testchannel',
        'display_name' => 'Test Channel',
        'access_token' => 'ya29.test_access_token',
        'refresh_token' => 'refresh_token_123',
        'token_expires_at' => now()->addHours(2),
        'status' => AccountStatus::Connected,
        'is_active' => true,
        'meta' => [
            'channel_id' => 'UC_test_channel_123',
            'google_user_id' => 'google_user_123',
        ],
    ]);

    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

test('youtube analytics returns metrics from api', function () {
    Http::fake([
        'https://youtubeanalytics.googleapis.com/v2/reports*' => Http::response([
            'columnHeaders' => [
                ['name' => 'views'],
                ['name' => 'estimatedMinutesWatched'],
                ['name' => 'averageViewDuration'],
                ['name' => 'averageViewPercentage'],
                ['name' => 'subscribersGained'],
                ['name' => 'subscribersLost'],
                ['name' => 'likes'],
            ],
            'rows' => [
                [1500, 3200, 128, 45.5, 50, 5, 200],
            ],
        ], 200),
    ]);

    $analytics = app(YouTubeAnalytics::class);
    $metrics = $analytics->getMetrics($this->youtubeAccount);

    expect($metrics)->toBeArray()
        ->and($metrics)->toHaveCount(7)
        ->and($metrics[0])->toMatchArray(['label' => 'Views', 'value' => 1500])
        ->and($metrics[1])->toMatchArray(['label' => 'Minutes Watched', 'value' => 3200])
        ->and($metrics[2])->toMatchArray(['label' => 'Avg. View Duration (s)', 'value' => 128])
        ->and($metrics[3])->toMatchArray(['label' => 'Avg. View Percentage', 'value' => 45.5])
        ->and($metrics[4])->toMatchArray(['label' => 'Subscribers Gained', 'value' => 50])
        ->and($metrics[5])->toMatchArray(['label' => 'Subscribers Lost', 'value' => 5])
        ->and($metrics[6])->toMatchArray(['label' => 'Likes', 'value' => 200]);
});

test('youtube analytics returns empty array on api failure', function () {
    Http::fake([
        'https://youtubeanalytics.googleapis.com/v2/reports*' => Http::response([], 403),
    ]);

    $analytics = app(YouTubeAnalytics::class);
    $metrics = $analytics->getMetrics($this->youtubeAccount);

    expect($metrics)->toBeArray()->toBeEmpty();
});

test('youtube analytics returns empty array when no rows', function () {
    Http::fake([
        'https://youtubeanalytics.googleapis.com/v2/reports*' => Http::response([
            'columnHeaders' => [
                ['name' => 'views'],
            ],
            'rows' => [],
        ], 200),
    ]);

    $analytics = app(YouTubeAnalytics::class);
    $metrics = $analytics->getMetrics($this->youtubeAccount);

    expect($metrics)->toBeArray()->toBeEmpty();
});

test('youtube analytics caches results', function () {
    Http::fake([
        'https://youtubeanalytics.googleapis.com/v2/reports*' => Http::response([
            'columnHeaders' => [
                ['name' => 'views'],
            ],
            'rows' => [
                [500],
            ],
        ], 200),
    ]);

    $analytics = app(YouTubeAnalytics::class);
    $analytics->getMetrics($this->youtubeAccount);
    $analytics->getMetrics($this->youtubeAccount);

    Http::assertSentCount(1);
});

test('youtube analytics supports date range', function () {
    Http::fake([
        'https://youtubeanalytics.googleapis.com/v2/reports*' => Http::response([
            'columnHeaders' => [
                ['name' => 'views'],
            ],
            'rows' => [
                [1000],
            ],
        ], 200),
    ]);

    $analytics = app(YouTubeAnalytics::class);
    $metrics = $analytics->getMetrics(
        $this->youtubeAccount,
        now()->subDays(30),
        now(),
    );

    expect($metrics)->toBeArray()->toHaveCount(1);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'startDate=')
        && str_contains($request->url(), 'endDate=')
    );
});

test('youtube analytics refreshes expired token', function () {
    $this->youtubeAccount->update(['token_expires_at' => now()->subMinutes(5)]);

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'new_access_token',
            'expires_in' => 3600,
        ], 200),
        'https://youtubeanalytics.googleapis.com/v2/reports*' => Http::response([
            'columnHeaders' => [
                ['name' => 'views'],
            ],
            'rows' => [
                [100],
            ],
        ], 200),
    ]);

    $analytics = app(YouTubeAnalytics::class);
    $metrics = $analytics->getMetrics($this->youtubeAccount);

    expect($metrics)->toBeArray()->toHaveCount(1);

    $this->youtubeAccount->refresh();
    expect($this->youtubeAccount->access_token)->toBe('new_access_token');
});

test('youtube analytics throws exception when no refresh token', function () {
    $this->youtubeAccount->update([
        'token_expires_at' => now()->subMinutes(5),
        'refresh_token' => null,
    ]);

    $analytics = app(YouTubeAnalytics::class);
    $analytics->getMetrics($this->youtubeAccount);
})->throws(TokenExpiredException::class);

test('youtube analytics throws exception on token refresh failure', function () {
    $this->youtubeAccount->update(['token_expires_at' => now()->subMinutes(5)]);

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    $analytics = app(YouTubeAnalytics::class);
    $analytics->getMetrics($this->youtubeAccount);
})->throws(TokenExpiredException::class);

test('youtube is in supported analytics platforms', function () {
    config(['trypost.self_hosted' => true]);

    $response = $this->actingAs($this->user)
        ->get(route('app.analytics'));

    $response->assertOk();

    $accounts = $response->original->getData()['page']['props']['accounts'];
    $youtubeAccount = collect($accounts)->firstWhere('platform', Platform::YouTube->value);

    expect($youtubeAccount)->not->toBeNull()
        ->and($youtubeAccount['id'])->toBe($this->youtubeAccount->id)
        ->and($youtubeAccount)->toHaveKeys(['display_label']);
});

test('youtube analytics show endpoint returns metrics', function () {
    config(['trypost.self_hosted' => true]);

    Http::fake([
        'https://youtubeanalytics.googleapis.com/v2/reports*' => Http::response([
            'columnHeaders' => [
                ['name' => 'views'],
                ['name' => 'likes'],
            ],
            'rows' => [
                [500, 30],
            ],
        ], 200),
    ]);

    $response = $this->actingAs($this->user)
        ->getJson(route('app.analytics.show', $this->youtubeAccount));

    $response->assertOk()
        ->assertJsonStructure(['metrics'])
        ->assertJsonCount(2, 'metrics');
});

test('youtube analytics show endpoint rejects other workspace accounts', function () {
    config(['trypost.self_hosted' => true]);

    $otherUser = User::factory()->create([]);
    $otherWorkspace = Workspace::factory()->create(['user_id' => $otherUser->id]);
    $otherWorkspace->members()->attach($otherUser->id, ['role' => Role::Member->value]);
    $otherUser->update(['current_workspace_id' => $otherWorkspace->id]);

    $response = $this->actingAs($otherUser)
        ->getJson(route('app.analytics.show', $this->youtubeAccount));

    $response->assertForbidden();
});
