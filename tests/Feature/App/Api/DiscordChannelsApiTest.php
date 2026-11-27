<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    config([
        'trypost.platforms.discord.bot_token' => 'BOTTOKEN',
        'services.discord.client_id' => '999000111',
    ]);

    $result = createApiTestToken();
    $this->user = $result['user'];
    $this->workspace = $result['workspace'];
    $this->plainToken = $result['plain_token'];
});

it('lists discord channels for a connected account', function () {
    $account = SocialAccount::factory()->discord()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => '111222333',
    ]);

    Http::fake([
        config('trypost.platforms.discord.api').'/guilds/111222333/channels' => Http::response([
            ['id' => '1', 'name' => 'general', 'type' => 0],
            ['id' => '2', 'name' => 'voice', 'type' => 2],
            ['id' => '3', 'name' => 'news', 'type' => 5],
        ], 200),
        config('trypost.platforms.discord.api').'/guilds/111222333/roles' => Http::response([
            ['id' => '111222333', 'name' => '@everyone', 'permissions' => '3072'],
        ], 200),
        config('trypost.platforms.discord.api').'/guilds/111222333/members/999000111' => Http::response(['roles' => []], 200),
    ]);

    $response = $this->getJson(route('api.social-accounts.channels', $account), [
        'Authorization' => "Bearer {$this->plainToken}",
    ]);

    $response->assertOk();
    $response->assertExactJson([
        'channels' => [
            ['id' => '1', 'name' => 'general'],
            ['id' => '3', 'name' => 'news'],
        ],
    ]);
});

it('returns bad gateway when discord channel lookup fails', function () {
    $account = SocialAccount::factory()->discord()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => '111222333',
    ]);

    Http::fake([
        config('trypost.platforms.discord.api').'/guilds/111222333/channels' => Http::response('upstream down', 500),
    ]);

    $this->getJson(route('api.social-accounts.channels', $account), [
        'Authorization' => "Bearer {$this->plainToken}",
    ])
        ->assertStatus(502)
        ->assertJsonPath('message', 'Discord channel lookup failed (500).');
});

it('rejects channels listing for non-discord accounts', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
    ]);

    $this->getJson(route('api.social-accounts.channels', $account), [
        'Authorization' => "Bearer {$this->plainToken}",
    ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Channels are only available for Discord accounts.');
});

it('cannot list channels for an account from another workspace', function () {
    $otherWorkspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->discord()->create([
        'workspace_id' => $otherWorkspace->id,
    ]);

    $this->getJson(route('api.social-accounts.channels', $account), [
        'Authorization' => "Bearer {$this->plainToken}",
    ])->assertNotFound();
});
