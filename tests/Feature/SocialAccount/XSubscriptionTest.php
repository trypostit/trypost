<?php

declare(strict_types=1);

use App\Actions\SocialAccount\SyncXSubscription;
use App\Models\SocialAccount;
use App\Services\Social\ConnectionVerifier;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('verifying an x account stores its subscription tier', function () {
    $account = SocialAccount::factory()->x()->create(['meta' => ['keep' => 'me'], 'token_expires_at' => now()->addHour()]);
    Http::fake([
        config('trypost.platforms.x.api').'/users/me*' => Http::response(['data' => [
            'id' => '1',
            'subscription_type' => 'Premium',
            'verified_type' => 'blue',
        ]]),
    ]);

    app(ConnectionVerifier::class)->verify($account);

    expect($account->fresh()->meta)->toEqual(['keep' => 'me', 'x_subscription_type' => 'Premium', 'x_verified_type' => 'blue']);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/users/me')
        && $request['user.fields'] === 'subscription_type,verified_type');
});

test('the content limit follows the x tier', function (array $meta, int $limit) {
    $account = SocialAccount::factory()->x()->create(['meta' => $meta]);

    expect($account->maxContentLength())->toBe($limit)
        ->and($account->contentOverflow(str_repeat('a', 300)))->toBe(max(0, 300 - $limit));
})->with([
    'no tier stored' => [[], 280],
    'free' => [['x_subscription_type' => 'None', 'x_verified_type' => 'none'], 280],
    'basic' => [['x_subscription_type' => 'Basic'], 25000],
    'premium' => [['x_subscription_type' => 'Premium'], 25000],
    'premium plus' => [['x_subscription_type' => 'PremiumPlus'], 25000],
    'verified organization' => [['x_subscription_type' => 'None', 'x_verified_type' => 'business'], 25000],
]);

test('other networks keep their platform limit', function () {
    $account = SocialAccount::factory()->mastodon()->create(['meta' => ['x_subscription_type' => 'Premium']]);

    expect($account->maxContentLength())->toBe(500);
});

test('connecting an x account fetches the tier once', function () {
    $account = SocialAccount::factory()->x()->create(['meta' => [], 'token_expires_at' => now()->addHour()]);
    Http::fake([
        config('trypost.platforms.x.api').'/users/me*' => Http::response(['data' => ['id' => '1', 'subscription_type' => 'Basic']]),
    ]);

    rescue(fn () => app(ConnectionVerifier::class)->verifyAccessToken($account), report: false);

    expect($account->fresh()->hasXLongPosts())->toBeTrue();
    Http::assertSentCount(1);
});

test('a response without the tier fields keeps the stored tier', function () {
    $account = SocialAccount::factory()->x()->create([
        'meta' => ['x_subscription_type' => 'Premium', 'x_verified_type' => 'blue'],
    ]);

    SyncXSubscription::fromUser($account, ['id' => '1', 'username' => 'someone']);

    expect($account->fresh()->meta)
        ->toMatchArray(['x_subscription_type' => 'Premium', 'x_verified_type' => 'blue'])
        ->and($account->fresh()->maxContentLength())->toBe(25000);
});
