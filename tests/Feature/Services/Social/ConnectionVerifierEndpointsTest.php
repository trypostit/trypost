<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Services\Social\ConnectionVerifier;
use App\Services\Social\Telegram\TelegramApi;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
| The daily check calls one documented endpoint per network. These tests pin
| that endpoint exactly: any other request is refused (`preventStrayRequests`),
| the way the network would answer a wrong path with a 404, which the verifier
| reads as "unavailable" and never fails on. A loose `host/*` fake let
| LinkedIn's `/rest/userinfo` (a path that does not exist) pass for months.
| Every path here answered 200 against a real account on 2026-10-14.
*/

beforeEach(function () {
    config([
        'trypost.platforms.telegram.bot_token' => 'TELEGRAM-TOKEN',
        'trypost.platforms.discord.bot_token' => 'DISCORD-TOKEN',
    ]);
    Http::preventStrayRequests();
});

/**
 * @return array{0: SocialAccount, 1: string}
 */
function verifierEndpointCase(string $case): array
{
    $future = ['token_expires_at' => now()->addDays(30)];

    return match ($case) {
        'linkedin' => [SocialAccount::factory()->linkedin()->create($future), config('trypost.platforms.linkedin.api').'/v2/userinfo'],
        'linkedin page' => [SocialAccount::factory()->linkedinPage()->create($future), config('trypost.platforms.linkedin-page.api').'/rest/organizationAcls'],
        'x' => [SocialAccount::factory()->x()->create($future), config('trypost.platforms.x.api').'/users/me'],
        'tiktok' => [SocialAccount::factory()->tiktok()->create($future), config('trypost.platforms.tiktok.api').'/user/info/'],
        'youtube' => [SocialAccount::factory()->youtube()->create($future), config('trypost.platforms.youtube.data_api').'/channels'],
        'facebook' => [SocialAccount::factory()->facebook()->create($future), config('trypost.platforms.facebook.graph_api').'/me'],
        'instagram' => [SocialAccount::factory()->instagram()->create($future), Platform::Instagram->instagramGraphBaseUrl().'/me'],
        'instagram via facebook' => [SocialAccount::factory()->create(['platform' => Platform::InstagramFacebook, ...$future]), Platform::InstagramFacebook->instagramGraphBaseUrl().'/me'],
        'threads' => [SocialAccount::factory()->threads()->create($future), config('trypost.platforms.threads.graph_api').'/me'],
        'pinterest' => [SocialAccount::factory()->pinterest()->create($future), config('trypost.platforms.pinterest.api').'/user_account'],
        'bluesky' => [SocialAccount::factory()->bluesky()->create($future), 'https://bsky.social/xrpc/app.bsky.actor.getProfile'],
        'mastodon' => [SocialAccount::factory()->mastodon()->create($future), 'https://mastodon.social/api/v1/accounts/verify_credentials'],
        'telegram' => [SocialAccount::factory()->telegram()->create($future), TelegramApi::endpoint('getChat')],
        'discord' => [$discord = SocialAccount::factory()->discord()->create($future), config('trypost.platforms.discord.api')."/guilds/{$discord->platform_user_id}"],
        'google business' => [SocialAccount::factory()->googleBusiness()->create($future), config('trypost.platforms.google_business.business_information_api').'/locations/987654321'],
    };
}

test('the daily check calls the documented endpoint of each network and nothing else', function (string $case, array $body) {
    [$account, $endpoint] = verifierEndpointCase($case);
    Http::fake([
        $endpoint => Http::response($body),
        "{$endpoint}?*" => Http::response($body),
    ]);

    expect((new ConnectionVerifier)->verify($account))->toBeTrue();

    $expected = parse_url($endpoint);
    Http::assertSent(fn (Request $request): bool => parse_url($request->url(), PHP_URL_HOST) === $expected['host']
        && parse_url($request->url(), PHP_URL_PATH) === $expected['path']);
})->with([
    'linkedin' => ['linkedin', ['sub' => 'member-1']],
    'linkedin page' => ['linkedin page', ['elements' => [['role' => 'ADMINISTRATOR', 'state' => 'APPROVED']]]],
    'x' => ['x', ['data' => ['id' => '1', 'username' => 'someone']]],
    'tiktok' => ['tiktok', ['data' => ['user' => ['open_id' => 'open-1']], 'error' => ['code' => 'ok']]],
    'youtube' => ['youtube', ['items' => [['id' => 'channel-1']]]],
    'facebook' => ['facebook', ['id' => 'page-1', 'name' => 'Page']],
    'instagram' => ['instagram', ['id' => 'app-scoped-1', 'username' => 'someone', 'user_id' => '17841400000000001']],
    'instagram via facebook' => ['instagram via facebook', ['id' => 'page-1', 'name' => 'Page']],
    'threads' => ['threads', ['id' => 'threads-1']],
    'pinterest' => ['pinterest', ['username' => 'someone']],
    'bluesky' => ['bluesky', ['did' => 'did:plc:someone', 'handle' => 'someone.bsky.social']],
    'mastodon' => ['mastodon', ['id' => '1', 'username' => 'someone']],
    'telegram' => ['telegram', ['ok' => true, 'result' => ['id' => -1001234567890]]],
    'discord' => ['discord', ['id' => 'guild-1', 'name' => 'Guild']],
    'google business' => ['google business', ['name' => 'locations/987654321']],
]);
