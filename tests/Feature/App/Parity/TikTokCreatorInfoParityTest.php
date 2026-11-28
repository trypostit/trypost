<?php

declare(strict_types=1);

use App\Enums\TikTok\PrivacyLevel;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\SocialAccount\GetTikTokCreatorInfoTool;
use App\Models\SocialAccount;
use App\Support\PostPlatformMetaRules;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    ['user' => $this->user, 'workspace' => $this->workspace, 'token' => $this->token] = parityContext();
    $this->tiktok = SocialAccount::factory()->tiktok()->create([
        'workspace_id' => $this->workspace->id,
        'token_expires_at' => now()->addDays(1),
    ]);
    $this->creatorInfoUrl = config('trypost.platforms.tiktok.api').'/post/publish/creator_info/query/';
});

function tiktokCreatorInfoCalls(string $url): int
{
    return Http::recorded(fn (Request $request): bool => $request->url() === $url)->count();
}

test('the api, mcp and the web composer read the same creator info for a tiktok account', function () {
    Http::fake([
        $this->creatorInfoUrl => Http::response(['data' => [
            'creator_nickname' => 'Paulo',
            'creator_username' => 'paulo',
            'creator_avatar_url' => 'https://cdn.tiktok.com/avatar.jpg',
            'privacy_level_options' => [PrivacyLevel::PublicToEveryone->value, PrivacyLevel::SelfOnly->value],
            'comment_disabled' => false,
            'duet_disabled' => true,
            'stitch_disabled' => true,
            'max_video_post_duration_sec' => 600,
        ]]),
    ]);

    $web = $this->actingAs($this->user)->getJson(route('app.posts.composer.account', $this->tiktok))->assertOk();
    auth()->forgetGuards();
    $api = $this->withHeaders(parityApi($this->token))->getJson(route('api.social-accounts.tiktok-creator-info', $this->tiktok))
        ->assertOk()
        ->assertExactJson([
            'can_post' => true,
            'privacy_level_options' => [PrivacyLevel::PublicToEveryone->value, PrivacyLevel::SelfOnly->value],
            'comment_disabled' => false,
            'duet_disabled' => true,
            'stitch_disabled' => true,
            'max_video_post_duration_sec' => 600,
            'creator_nickname' => 'Paulo',
            'creator_username' => 'paulo',
            'creator_avatar_url' => 'https://cdn.tiktok.com/avatar.jpg',
        ]);
    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)->tool(GetTikTokCreatorInfoTool::class, ['account_id' => $this->tiktok->id])
        ->assertOk()
        ->assertStructuredContent($api->json());

    expect(collect($api->json())->except('can_post')->all())->toEqual($web->json('tiktokCreatorInfo'))
        ->and(tiktokCreatorInfoCalls($this->creatorInfoUrl))->toBe(1);
});

test('a creator tiktok bans from posting answers can_post false on the api and mcp', function () {
    Http::fake([
        $this->creatorInfoUrl => Http::response(['error' => ['code' => 'spam_risk_user_banned_from_posting', 'message' => 'Banned']], 403),
    ]);

    $this->withHeaders(parityApi($this->token))->getJson(route('api.social-accounts.tiktok-creator-info', $this->tiktok))
        ->assertOk()
        ->assertJsonPath('can_post', false)
        ->assertJsonPath('privacy_level_options', []);
    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)->tool(GetTikTokCreatorInfoTool::class, ['account_id' => $this->tiktok->id])
        ->assertOk()
        ->assertSee('"can_post":false');
});

test('a tiktok posting limit answers 429 on the api and a limit error on mcp, never can_post false, and is not cached', function (string $code, int $status) {
    Http::fake([
        $this->creatorInfoUrl => Http::response(['error' => ['code' => $code, 'message' => 'Limit']], $status),
    ]);
    $message = __('posts.form.tiktok.creator_info_limit_reached');

    $this->withHeaders(parityApi($this->token))->getJson(route('api.social-accounts.tiktok-creator-info', $this->tiktok))
        ->assertStatus(Response::HTTP_TOO_MANY_REQUESTS)
        ->assertHeaderMissing('Retry-After')
        ->assertExactJson(['message' => $message]);
    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)->tool(GetTikTokCreatorInfoTool::class, ['account_id' => $this->tiktok->id])
        ->assertHasErrors(['TikTok posting limit reached; scheduled posts will retry automatically'])
        ->assertDontSee('can_post');

    expect(tiktokCreatorInfoCalls($this->creatorInfoUrl))->toBe(2);
})->with([
    'daily post cap' => ['spam_risk_too_many_posts', Response::HTTP_FORBIDDEN],
    'active user quota' => ['reached_active_user_cap', Response::HTTP_FORBIDDEN],
    'rate limit' => ['rate_limit_exceeded', Response::HTTP_TOO_MANY_REQUESTS],
]);

test('a tiktok limit with a reset time sends Retry-After on the api', function () {
    Http::fake([
        $this->creatorInfoUrl => Http::response(['error' => ['code' => 'rate_limit_exceeded']], Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => '120']),
    ]);

    $this->withHeaders(parityApi($this->token))->getJson(route('api.social-accounts.tiktok-creator-info', $this->tiktok))
        ->assertStatus(Response::HTTP_TOO_MANY_REQUESTS)
        ->assertHeader('Retry-After', '120');
});

test('a tiktok that fails answers 502 on the api and an error on mcp, never can_post false, and is not cached', function () {
    Http::fake([
        $this->creatorInfoUrl => Http::response(['error' => ['code' => 'internal_error']], 500),
    ]);
    $message = __('posts.form.tiktok.creator_info_unavailable');

    $this->withHeaders(parityApi($this->token))->getJson(route('api.social-accounts.tiktok-creator-info', $this->tiktok))
        ->assertStatus(Response::HTTP_BAD_GATEWAY)
        ->assertExactJson(['message' => $message]);
    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)->tool(GetTikTokCreatorInfoTool::class, ['account_id' => $this->tiktok->id])
        ->assertHasErrors([$message])
        ->assertDontSee('can_post');

    expect(tiktokCreatorInfoCalls($this->creatorInfoUrl))->toBe(2);
});

test('a tiktok that times out answers 503 on the api and an error on mcp', function () {
    Http::fake([
        $this->creatorInfoUrl => fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
    ]);
    $message = __('posts.form.tiktok.creator_info_unavailable');

    $this->withHeaders(parityApi($this->token))->getJson(route('api.social-accounts.tiktok-creator-info', $this->tiktok))
        ->assertStatus(Response::HTTP_SERVICE_UNAVAILABLE)
        ->assertExactJson(['message' => $message]);
    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)->tool(GetTikTokCreatorInfoTool::class, ['account_id' => $this->tiktok->id])
        ->assertHasErrors([$message]);
});

test('the web composer still degrades to an empty creator info when tiktok fails', function () {
    Http::fake([
        $this->creatorInfoUrl => Http::response(['error' => ['code' => 'internal_error']], 500),
    ]);

    $this->actingAs($this->user)->getJson(route('app.posts.composer.account', $this->tiktok))
        ->assertOk()
        ->assertJsonPath('tiktokCreatorInfo.privacy_level_options', []);
});

test('creator info is refused for an account that is not tiktok', function () {
    Http::fake();
    $linkedin = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id]);

    $this->withHeaders(parityApi($this->token))->getJson(route('api.social-accounts.tiktok-creator-info', $linkedin))->assertUnprocessable();
    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)->tool(GetTikTokCreatorInfoTool::class, ['account_id' => $linkedin->id])
        ->assertHasErrors(['This tool only works with TikTok social accounts.']);

    expect(tiktokCreatorInfoCalls($this->creatorInfoUrl))->toBe(0);
});

test('a tiktok account of another workspace is not found on the api and mcp', function () {
    Http::fake();
    $foreign = SocialAccount::factory()->tiktok()->create();

    $this->withHeaders(parityApi($this->token))->getJson(route('api.social-accounts.tiktok-creator-info', $foreign))->assertNotFound();
    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)->tool(GetTikTokCreatorInfoTool::class, ['account_id' => $foreign->id])
        ->assertHasErrors(['Social account not found.']);
    TryPostServer::actingAs($this->user)->tool(GetTikTokCreatorInfoTool::class, ['account_id' => 'not-a-uuid'])
        ->assertHasErrors()
        ->assertDontSee('SQLSTATE');

    expect(tiktokCreatorInfoCalls($this->creatorInfoUrl))->toBe(0);
});

test('the tiktok meta documentation sends agents to the creator info tool before choosing a privacy level', function () {
    expect(PostPlatformMetaRules::documentation())->toContain('get-tiktok-creator-info-tool')
        ->and((new GetTikTokCreatorInfoTool)->name())->toBe('get-tiktok-creator-info-tool');
});
