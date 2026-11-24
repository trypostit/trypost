<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Exceptions\PlatformUnavailableException;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\SocialAccount\CreatePinterestBoardTool;
use App\Mcp\Tools\SocialAccount\GetInstagramAudioTool;
use App\Mcp\Tools\SocialAccount\ListDiscordChannelsTool;
use App\Mcp\Tools\SocialAccount\ListPinterestBoardsTool;
use App\Mcp\Tools\SocialAccount\ListSocialAccountsTool;
use App\Mcp\Tools\SocialAccount\SearchInstagramAudioTool;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Social\Discord\DiscordClient;
use App\Support\Social\InstagramAudio;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    ['user' => $this->user, 'workspace' => $this->workspace, 'token' => $this->token] = parityContext();
});

test('listing channels returns the same accounts on the api and mcp, including timezone and posting_goal', function () {
    $first = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn, 'timezone' => 'America/Sao_Paulo']);
    $second = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::X]);
    SocialAccount::factory()->create(['workspace_id' => Workspace::factory()->create()->id, 'platform' => Platform::Bluesky]);

    auth()->forgetGuards();

    $apiResponse = $this->withHeaders(parityApi($this->token))->getJson(route('api.social-accounts.index'))->assertOk();
    $api = $apiResponse->json('data');
    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)->tool(ListSocialAccountsTool::class)->assertOk()->assertStructuredContent(parityMcpPage('social_accounts', $apiResponse));

    expect(collect($api)->pluck('id')->sort()->values()->all())->toBe(collect([$first->id, $second->id])->sort()->values()->all())
        ->and(array_keys($api[0]))->toEqual(['id', 'platform', 'display_name', 'username', 'status', 'has_posting_schedule', 'timezone', 'posting_goal', 'max_content_length', 'long_posts', 'verified_badge', 'missing_permissions'])
        ->and(collect($api)->firstWhere('id', $first->id)['timezone'])->toBe('America/Sao_Paulo');
});

test('the api and mcp list what a channel cannot do until it is reconnected', function () {
    $account = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $this->workspace->id,
        'scopes' => ['instagram_business_basic', 'instagram_business_content_publish'],
    ]);

    auth()->forgetGuards();
    $apiResponse = $this->withHeaders(parityApi($this->token))->getJson(route('api.social-accounts.index'))->assertOk();
    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)->tool(ListSocialAccountsTool::class)->assertOk()->assertStructuredContent(parityMcpPage('social_accounts', $apiResponse));
    expect(collect($apiResponse->json('data'))->firstWhere('id', $account->id)['missing_permissions'])->toBe(['thread_replies', 'post_insights']);
});

test('the api exposes exactly the channel list, boards, instagram audio, discord channels, insights, posting schedule and queue routes', function () {
    $apiChannelRoutes = collect(Route::getRoutes()->getRoutesByName())
        ->keys()
        ->filter(fn (string $name): bool => str_starts_with($name, 'api.social-accounts') || str_starts_with($name, 'api.channels'))
        ->values()
        ->all();

    expect($apiChannelRoutes)->toEqual(['api.social-accounts.index', 'api.social-accounts.boards', 'api.social-accounts.boards.store', 'api.social-accounts.tiktok-creator-info', 'api.social-accounts.instagram-audio', 'api.social-accounts.instagram-audio.show', 'api.social-accounts.channels', 'api.channels.insights.show', 'api.channels.insights.publications', 'api.channels.posting-schedule.show', 'api.channels.posting-schedule.update', 'api.channels.posting-schedule.generate', 'api.channels.posting-schedule.copy', 'api.channels.queue.slots', 'api.channels.queue.order', 'api.channels.queue.slot'])
        ->and(Route::has('app.channels.disconnect'))->toBeTrue()
        ->and(Route::has('app.channels.posting-schedule.update'))->toBeTrue()
        ->and(Route::has('app.channels.reorder'))->toBeTrue();
});

test('pinterest boards refuse a non pinterest account differently on the web, api and mcp', function () {
    $linkedIn = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn]);

    $this->actingAs($this->user)->getJson(route('app.pinterest.boards.index', $linkedIn))->assertNotFound();
    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->getJson(route('api.social-accounts.boards', $linkedIn))->assertUnprocessable();
    TryPostServer::actingAs($this->user)->tool(ListPinterestBoardsTool::class, ['account_id' => $linkedIn->id])->assertHasErrors();
});

test('pinterest boards of another workspace are refused on the api and mcp', function () {
    $foreign = SocialAccount::factory()->pinterest()->create(['workspace_id' => Workspace::factory()->create()->id]);

    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->getJson(route('api.social-accounts.boards', $foreign))->assertNotFound();
    TryPostServer::actingAs($this->user)->tool(ListPinterestBoardsTool::class, ['account_id' => $foreign->id])->assertHasErrors();
});

test('discord channels degrade to an empty list on the web but fail on the api and mcp when discord is unavailable', function () {
    $discord = SocialAccount::factory()->discord()->create(['workspace_id' => $this->workspace->id]);

    $this->mock(DiscordClient::class)
        ->shouldReceive('channels')
        ->andThrow(new PlatformUnavailableException('Discord channel lookup failed (500).', 500));

    $this->actingAs($this->user)->getJson(route('app.discord.channels', $discord))->assertOk()->assertExactJson(['channels' => []]);
    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->getJson(route('api.social-accounts.channels', $discord))->assertStatus(Response::HTTP_BAD_GATEWAY);
    TryPostServer::actingAs($this->user)->tool(ListDiscordChannelsTool::class, ['account_id' => $discord->id])->assertHasErrors();
});

test('discord channels return the same list on the web, api and mcp', function () {
    $discord = SocialAccount::factory()->discord()->create(['workspace_id' => $this->workspace->id]);
    $channels = [['id' => '1', 'name' => 'general']];

    $this->mock(DiscordClient::class)->shouldReceive('channels')->andReturn($channels);

    $this->actingAs($this->user)->getJson(route('app.discord.channels', $discord))->assertOk()->assertExactJson(['channels' => $channels]);
    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->getJson(route('api.social-accounts.channels', $discord))->assertOk()->assertExactJson(['channels' => $channels]);
    TryPostServer::actingAs($this->user)->tool(ListDiscordChannelsTool::class, ['account_id' => $discord->id])->assertOk()->assertStructuredContent(['channels' => $channels]);
});

test('discord mentions are a web-only route with no api counterpart', function () {
    expect(Route::has('app.discord.mentions'))->toBeTrue()
        ->and(Route::has('api.social-accounts.mentions'))->toBeFalse();
});

test('creating a pinterest board works the same on the web, api and mcp and refreshes the cached boards', function () {
    $api = config('trypost.platforms.pinterest.api');
    Http::fake([
        "{$api}/boards" => Http::response(['id' => '549755885175', 'name' => 'Summer recipes', 'media' => ['image_cover_url' => null]], Response::HTTP_CREATED),
    ]);
    $pinterest = SocialAccount::factory()->pinterest()->create(['workspace_id' => $this->workspace->id, 'access_token' => 'pinterest-token', 'token_expires_at' => now()->addDays(20)]);
    $board = ['id' => '549755885175', 'name' => 'Summer recipes', 'cover_url' => null];
    $cacheKey = "pinterest:boards:{$pinterest->id}";

    Cache::put($cacheKey, ['boards' => [], 'truncated' => false]);
    $this->actingAs($this->user)->postJson(route('app.pinterest.boards.store', $pinterest), ['name' => '  Summer recipes  '])->assertCreated()->assertExactJson($board);
    expect(Cache::has($cacheKey))->toBeFalse();
    auth()->forgetGuards();

    Cache::put($cacheKey, ['boards' => [], 'truncated' => false]);
    $this->withHeaders(parityApi($this->token))->postJson(route('api.social-accounts.boards.store', $pinterest), ['name' => '  Summer recipes  '])->assertCreated()->assertExactJson($board);
    expect(Cache::has($cacheKey))->toBeFalse();

    Cache::put($cacheKey, ['boards' => [], 'truncated' => false]);
    TryPostServer::actingAs($this->user)->tool(CreatePinterestBoardTool::class, ['account_id' => $pinterest->id, 'name' => '  Summer recipes  '])->assertOk()->assertStructuredContent($board);
    expect(Cache::has($cacheKey))->toBeFalse();

    $boardRequests = collect(Http::recorded())->filter(fn (array $pair): bool => $pair[0]->url() === "{$api}/boards");

    expect($boardRequests)->toHaveCount(3)
        ->and($boardRequests->every(fn (array $pair): bool => $pair[0]->data() === ['name' => 'Summer recipes'] && $pair[0]->hasHeader('Authorization', 'Bearer pinterest-token')))->toBeTrue();
});

test('pinterest board creation failures get the web message on the api and mcp', function (int $status, string $key) {
    Http::fake([
        config('trypost.platforms.pinterest.api').'/boards' => Http::response(['code' => 8, 'message' => 'Failed'], $status),
    ]);
    $pinterest = SocialAccount::factory()->pinterest()->create(['workspace_id' => $this->workspace->id, 'token_expires_at' => now()->addDays(20)]);

    $this->actingAs($this->user)->postJson(route('app.pinterest.boards.store', $pinterest), ['name' => 'Ideas'])->assertUnprocessable()->assertJsonValidationErrors(['name' => __($key)]);
    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->postJson(route('api.social-accounts.boards.store', $pinterest), ['name' => 'Ideas'])->assertUnprocessable()->assertJsonValidationErrors(['name' => __($key)]);
    TryPostServer::actingAs($this->user)->tool(CreatePinterestBoardTool::class, ['account_id' => $pinterest->id, 'name' => 'Ideas'])->assertHasErrors([__($key)]);
})->with([
    'dead token' => [Response::HTTP_UNAUTHORIZED, 'posts.form.pinterest.boards_reconnect'],
    'refused' => [Response::HTTP_FORBIDDEN, 'posts.form.pinterest.boards_reconnect'],
    'rate limited' => [Response::HTTP_TOO_MANY_REQUESTS, 'posts.form.pinterest.boards_rate_limited'],
    'server error' => [Response::HTTP_INTERNAL_SERVER_ERROR, 'posts.form.pinterest.boards_failed'],
]);

test('pinterest board creation refuses the same names and accounts on the web, api and mcp without calling pinterest', function () {
    Http::fake();
    $pinterest = SocialAccount::factory()->pinterest()->create(['workspace_id' => $this->workspace->id]);
    $linkedIn = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn]);
    $foreign = SocialAccount::factory()->pinterest()->create(['workspace_id' => Workspace::factory()->create()->id]);

    foreach (['   ', str_repeat('a', 51)] as $name) {
        $web = $this->actingAs($this->user)->postJson(route('app.pinterest.boards.store', $pinterest), ['name' => $name])->assertUnprocessable()->json('errors.name.0');
        auth()->forgetGuards();
        $this->withHeaders(parityApi($this->token))->postJson(route('api.social-accounts.boards.store', $pinterest), ['name' => $name])->assertUnprocessable()->assertJsonValidationErrors(['name' => $web]);
        TryPostServer::actingAs($this->user)->tool(CreatePinterestBoardTool::class, ['account_id' => $pinterest->id, 'name' => $name])->assertHasErrors([$web]);
    }

    $this->actingAs($this->user)->postJson(route('app.pinterest.boards.store', $linkedIn), ['name' => 'Ideas'])->assertNotFound();
    $this->actingAs($this->user)->postJson(route('app.pinterest.boards.store', $foreign), ['name' => 'Ideas'])->assertNotFound();
    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->postJson(route('api.social-accounts.boards.store', $linkedIn), ['name' => 'Ideas'])->assertNotFound();
    $this->withHeaders(parityApi($this->token))->postJson(route('api.social-accounts.boards.store', $foreign), ['name' => 'Ideas'])->assertNotFound();
    TryPostServer::actingAs($this->user)->tool(CreatePinterestBoardTool::class, ['account_id' => $linkedIn->id, 'name' => 'Ideas'])->assertHasErrors(['This tool only works with Pinterest social accounts.']);
    TryPostServer::actingAs($this->user)->tool(CreatePinterestBoardTool::class, ['account_id' => $foreign->id, 'name' => 'Ideas'])->assertHasErrors(['Social account not found.']);

    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/boards'));
});

test('instagram audio search and details return the same tracks on the web, api and mcp', function () {
    $graph = config('trypost.platforms.instagram-facebook.graph_api');
    Http::fake([
        "{$graph}/ig_audio?audio_type=music*" => Http::response(['audio' => [['audio_id' => '111', 'title' => 'Grudge', 'display_artist' => 'Tash', 'audio_type' => 'music', 'duration_in_ms' => 15000]]]),
        "{$graph}/ig_audio?audio_type=original_sound*" => Http::response(['audio' => []]),
        "{$graph}/111*" => Http::response(['audio_id' => '111', 'title' => 'Grudge', 'display_artist' => 'Tash', 'audio_type' => 'music', 'duration_in_ms' => 15000]),
    ]);
    $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::InstagramFacebook]);
    $track = ['id' => '111', 'type' => 'music', 'title' => 'Grudge', 'artist' => 'Tash', 'cover_url' => null, 'duration_ms' => 15000, 'preview_url' => null];

    $this->actingAs($this->user)->getJson(route('app.instagram.audio.index', [$instagram, 'q' => 'grudge']))->assertOk()->assertExactJson([$track]);
    $this->actingAs($this->user)->getJson(route('app.instagram.audio.show', [$instagram, '111']))->assertOk()->assertExactJson($track);
    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->getJson(route('api.social-accounts.instagram-audio', [$instagram, 'q' => 'grudge']))->assertOk()->assertExactJson([$track]);
    $this->withHeaders(parityApi($this->token))->getJson(route('api.social-accounts.instagram-audio.show', [$instagram, '111']))->assertOk()->assertExactJson($track);
    TryPostServer::actingAs($this->user)->tool(SearchInstagramAudioTool::class, ['account_id' => $instagram->id, 'q' => 'grudge'])->assertOk()->assertStructuredContent(['audio' => [$track]]);
    TryPostServer::actingAs($this->user)->tool(GetInstagramAudioTool::class, ['account_id' => $instagram->id, 'audio_id' => '111'])->assertOk()->assertStructuredContent($track);
});

test('instagram audio asks an account connected without facebook to link it on the web, api and mcp', function () {
    $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    $message = __('posts.form.instagram.music.requires_facebook');
    Http::fake();

    $this->actingAs($this->user)->getJson(route('app.instagram.audio.index', $instagram))->assertUnprocessable()->assertJsonValidationErrors(['q' => $message]);
    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->getJson(route('api.social-accounts.instagram-audio', $instagram))->assertUnprocessable()->assertJsonPath('message', $message);
    TryPostServer::actingAs($this->user)->tool(SearchInstagramAudioTool::class, ['account_id' => $instagram->id])->assertHasErrors([$message]);
    TryPostServer::actingAs($this->user)->tool(GetInstagramAudioTool::class, ['account_id' => $instagram->id, 'audio_id' => '111'])->assertHasErrors([$message]);

    Http::assertNothingSent();
});

test('instagram audio says the lookup failed when instagram does not answer on the web, api and mcp', function (string $failure) {
    $graph = config('trypost.platforms.instagram-facebook.graph_api');
    Http::fake(["{$graph}/*" => fn () => $failure === 'server error' ? Http::response(['error' => ['message' => 'Boom']], Response::HTTP_INTERNAL_SERVER_ERROR) : Http::failedConnection()]);
    $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::InstagramFacebook]);
    $message = __('posts.form.instagram.music.search_failed');

    $this->actingAs($this->user)->getJson(route('app.instagram.audio.index', [$instagram, 'q' => 'grudge']))->assertUnprocessable()->assertJsonValidationErrors(['q' => $message]);
    $this->actingAs($this->user)->getJson(route('app.instagram.audio.show', [$instagram, '111']))->assertUnprocessable()->assertJsonValidationErrors(['q' => $message]);
    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->getJson(route('api.social-accounts.instagram-audio', [$instagram, 'q' => 'grudge']))->assertStatus(Response::HTTP_BAD_GATEWAY)->assertJsonPath('message', $message);
    $this->withHeaders(parityApi($this->token))->getJson(route('api.social-accounts.instagram-audio.show', [$instagram, '111']))->assertStatus(Response::HTTP_BAD_GATEWAY)->assertJsonPath('message', $message);
    TryPostServer::actingAs($this->user)->tool(SearchInstagramAudioTool::class, ['account_id' => $instagram->id, 'q' => 'grudge'])->assertHasErrors([$message]);
    TryPostServer::actingAs($this->user)->tool(GetInstagramAudioTool::class, ['account_id' => $instagram->id, 'audio_id' => '111'])->assertHasErrors([$message]);
})->with(['server error', 'failed connection']);

test('instagram audio says the lookup failed for a removed track on the web, api and mcp', function () {
    $graph = config('trypost.platforms.instagram-facebook.graph_api');
    Http::fake(["{$graph}/111*" => Http::response(['error' => ['message' => 'Unsupported get request.']], Response::HTTP_NOT_FOUND)]);
    $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::InstagramFacebook]);
    $message = __('posts.form.instagram.music.search_failed');

    $this->actingAs($this->user)->getJson(route('app.instagram.audio.show', [$instagram, '111']))->assertUnprocessable()->assertJsonValidationErrors(['q' => $message]);
    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->getJson(route('api.social-accounts.instagram-audio.show', [$instagram, '111']))->assertStatus(Response::HTTP_BAD_GATEWAY)->assertJsonPath('message', $message);
    TryPostServer::actingAs($this->user)->tool(GetInstagramAudioTool::class, ['account_id' => $instagram->id, 'audio_id' => '111'])->assertHasErrors([$message]);
});

test('instagram audio refuses an account of another network on the web, api and mcp', function () {
    $linkedIn = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn]);
    $apiMessage = 'Instagram audio is only available for Instagram accounts.';
    $mcpMessage = 'This tool only works with Instagram social accounts.';
    Http::fake();

    $this->actingAs($this->user)->getJson(route('app.instagram.audio.index', [$linkedIn, 'q' => 'grudge']))->assertNotFound();
    $this->actingAs($this->user)->getJson(route('app.instagram.audio.show', [$linkedIn, '111']))->assertNotFound();
    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->getJson(route('api.social-accounts.instagram-audio', [$linkedIn, 'q' => 'grudge']))->assertUnprocessable()->assertJsonPath('message', $apiMessage);
    $this->withHeaders(parityApi($this->token))->getJson(route('api.social-accounts.instagram-audio.show', [$linkedIn, '111']))->assertUnprocessable()->assertJsonPath('message', $apiMessage);
    TryPostServer::actingAs($this->user)->tool(SearchInstagramAudioTool::class, ['account_id' => $linkedIn->id, 'q' => 'grudge'])->assertHasErrors([$mcpMessage]);
    TryPostServer::actingAs($this->user)->tool(GetInstagramAudioTool::class, ['account_id' => $linkedIn->id, 'audio_id' => '111'])->assertHasErrors([$mcpMessage]);

    Http::assertNothingSent();
});

test('instagram audio lookups of another workspace are refused on the api and mcp', function () {
    $foreign = SocialAccount::factory()->instagram()->create(['workspace_id' => Workspace::factory()->create()->id, 'platform' => Platform::InstagramFacebook]);
    Http::fake();

    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->getJson(route('api.social-accounts.instagram-audio', $foreign))->assertNotFound();
    TryPostServer::actingAs($this->user)->tool(SearchInstagramAudioTool::class, ['account_id' => $foreign->id])->assertHasErrors();

    Http::assertNothingSent();
});

test('instagram audio refuses lookups past the workspace allowance on the api and mcp', function () {
    $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::InstagramFacebook]);
    $message = __('posts.form.instagram.music.too_many_searches');
    Http::fake();

    foreach (range(1, InstagramAudio::LOOKUPS_PER_MINUTE) as $lookup) {
        RateLimiter::hit("instagram-audio:{$this->workspace->id}", 60);
    }

    $this->withHeaders(parityApi($this->token))->getJson(route('api.social-accounts.instagram-audio', [$instagram, 'q' => 'grudge']))
        ->assertTooManyRequests()
        ->assertHeader('Retry-After');
    $this->withHeaders(parityApi($this->token))->getJson(route('api.social-accounts.instagram-audio.show', [$instagram, '111']))
        ->assertTooManyRequests();
    TryPostServer::actingAs($this->user)->tool(SearchInstagramAudioTool::class, ['account_id' => $instagram->id, 'q' => 'grudge'])->assertHasErrors([$message]);
    TryPostServer::actingAs($this->user)->tool(GetInstagramAudioTool::class, ['account_id' => $instagram->id, 'audio_id' => '111'])->assertHasErrors([$message]);

    Http::assertNothingSent();
});

test('instagram audio refuses an id that is not a number and a search that is too long on the web, api and mcp', function () {
    $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::InstagramFacebook]);
    Http::fake();

    $this->actingAs($this->user)->getJson(route('app.instagram.audio.show', [$instagram, 'not-a-number']))->assertNotFound();
    $this->actingAs($this->user)->getJson(route('app.instagram.audio.index', [$instagram, 'q' => str_repeat('a', 101)]))->assertUnprocessable()->assertJsonValidationErrors('q');
    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->getJson(route('api.social-accounts.instagram-audio.show', [$instagram, 'not-a-number']))->assertNotFound();
    $this->withHeaders(parityApi($this->token))->getJson(route('api.social-accounts.instagram-audio', [$instagram, 'q' => str_repeat('a', 101)]))->assertUnprocessable()->assertJsonValidationErrors('q');
    TryPostServer::actingAs($this->user)->tool(GetInstagramAudioTool::class, ['account_id' => $instagram->id, 'audio_id' => 'not-a-number'])->assertHasErrors([__('validation.regex', ['attribute' => 'audio id'])]);
    TryPostServer::actingAs($this->user)->tool(SearchInstagramAudioTool::class, ['account_id' => $instagram->id, 'q' => str_repeat('a', 101)])->assertHasErrors([__('validation.max.string', ['attribute' => 'q', 'max' => 100])]);

    Http::assertNothingSent();
});
