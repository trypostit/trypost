<?php

declare(strict_types=1);

use App\Actions\SocialAccount\DisconnectSocialAccount;
use App\Enums\SocialAccount\Platform;
use App\Jobs\RevokeGoogleGrant;
use App\Models\SocialAccount;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('removing a youtube or google business channel hands its grant back to google', function (string $state) {
    $account = SocialAccount::factory()->{$state}()->create([
        'refresh_token' => 'refresh-1',
        'meta' => ['google_user_id' => 'google-1'],
    ]);

    DisconnectSocialAccount::execute($account);

    Queue::assertPushed(RevokeGoogleGrant::class, fn (RevokeGoogleGrant $job): bool => $job->platform === $account->platform
        && $job->googleUserId === 'google-1'
        && $job->token === 'refresh-1');
})->with(['youtube', 'googleBusiness']);

test('no revoke is queued for other networks or a channel without its google user', function () {
    DisconnectSocialAccount::execute(SocialAccount::factory()->facebook()->create());
    DisconnectSocialAccount::execute(SocialAccount::factory()->youtube()->create(['meta' => []]));

    Queue::assertNotPushed(RevokeGoogleGrant::class);
});

test('the job revokes the token at google', function () {
    Http::fake([config('trypost.platforms.youtube.oauth_api').'/revoke' => Http::response()]);

    (new RevokeGoogleGrant(Platform::YouTube, 'google-1', 'refresh-1'))->handle();

    Http::assertSent(fn (Request $request): bool => $request->url() === config('trypost.platforms.youtube.oauth_api').'/revoke'
        && $request['token'] === 'refresh-1');
});

test('the job leaves the grant alone while another channel still uses it', function (string $state) {
    SocialAccount::factory()->{$state}()->create(['meta' => ['google_user_id' => 'google-1']]);
    Http::fake();

    (new RevokeGoogleGrant(Platform::YouTube, 'google-1', 'refresh-1'))->handle();

    Http::assertNothingSent();
})->with(['youtube', 'googleBusiness']);

test('a token google already dropped counts as revoked', function () {
    Http::fake([config('trypost.platforms.google_business.oauth_api').'/revoke' => Http::response(['error' => 'invalid_token'], 400)]);

    (new RevokeGoogleGrant(Platform::GoogleBusiness, 'google-1', 'refresh-1'))->handle();

    Http::assertSentCount(1);
});

test('a google outage is retried', function () {
    Http::fake([config('trypost.platforms.youtube.oauth_api').'/revoke' => Http::response(status: 503)]);

    expect(fn () => (new RevokeGoogleGrant(Platform::YouTube, 'google-1', 'refresh-1'))->handle())
        ->toThrow(RequestException::class);
});
