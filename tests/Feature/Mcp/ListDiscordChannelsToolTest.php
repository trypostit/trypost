<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\SocialAccount\ListDiscordChannelsTool;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    Cache::flush();
    config([
        'trypost.platforms.discord.bot_token' => 'BOTTOKEN',
        'services.discord.client_id' => '999000111',
    ]);

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

test('lists discord channels as id and name', function () {
    $account = SocialAccount::factory()->discord()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => '111222333',
    ]);

    Http::fake([
        config('trypost.platforms.discord.api').'/guilds/111222333/channels' => Http::response([
            ['id' => '1', 'name' => 'general', 'type' => 0],
            ['id' => '2', 'name' => 'voice', 'type' => 2],
        ], 200),
        config('trypost.platforms.discord.api').'/guilds/111222333/roles' => Http::response([
            ['id' => '111222333', 'name' => '@everyone', 'permissions' => '3072'],
        ], 200),
        config('trypost.platforms.discord.api').'/guilds/111222333/members/999000111' => Http::response(['roles' => []], 200),
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(ListDiscordChannelsTool::class, ['account_id' => $account->id]);

    $response->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->has('channels', 1)
                ->where('channels.0.id', '1')
                ->where('channels.0.name', 'general');
        });
});

test('returns an actionable error when discord is unavailable', function () {
    $account = SocialAccount::factory()->discord()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => '111222333',
    ]);

    Http::fake([
        config('trypost.platforms.discord.api').'/guilds/111222333/channels' => Http::response('upstream down', 500),
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(ListDiscordChannelsTool::class, ['account_id' => $account->id]);

    $response->assertHasErrors(['Discord channel lookup failed (500).']);
});

test('rejects non-discord accounts', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(ListDiscordChannelsTool::class, ['account_id' => $account->id]);

    $response->assertHasErrors(['This tool only works with Discord social accounts.']);
});

test('cannot list channels for another workspace account', function () {
    $otherWorkspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->discord()->create([
        'workspace_id' => $otherWorkspace->id,
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(ListDiscordChannelsTool::class, ['account_id' => $account->id]);

    $response->assertHasErrors(['Social account not found.']);
});
