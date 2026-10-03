<?php

declare(strict_types=1);

use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush(); // channel list is cached per guild — isolate between tests
    config([
        'trypost.platforms.discord.bot_token' => 'BOTTOKEN',
        'services.discord.client_id' => '999000111', // bot user id, used by the channel permission check
    ]);

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->user->account_id, 'user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->user->refresh();

    $this->account = SocialAccount::factory()->discord()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => '111222333',
    ]);
});

test('lists only postable channels (text + announcement) the bot can send in', function () {
    Http::fake([
        config('trypost.platforms.discord.api').'/guilds/111222333/channels' => Http::response([
            ['id' => '1', 'name' => 'general', 'type' => 0],
            ['id' => '2', 'name' => 'voice', 'type' => 2],    // voice — excluded
            ['id' => '3', 'name' => 'news', 'type' => 5],
            ['id' => '4', 'name' => 'category', 'type' => 4], // category — excluded
            ['id' => '5', 'name' => 'forum', 'type' => 15],   // forum — excluded (only accepts threads)
        ], 200),
        // @everyone grants VIEW_CHANNEL + SEND_MESSAGES (1024 + 2048) everywhere.
        config('trypost.platforms.discord.api').'/guilds/111222333/roles' => Http::response([
            ['id' => '111222333', 'name' => '@everyone', 'permissions' => '3072'],
        ], 200),
        config('trypost.platforms.discord.api').'/guilds/111222333/members/999000111' => Http::response(['roles' => []], 200),
    ]);

    $response = $this->actingAs($this->user)
        ->getJson(route('app.discord.channels', $this->account->id));

    $response->assertOk();
    expect(collect($response->json('channels'))->pluck('name')->all())->toBe(['general', 'news']);
});

test('excludes channels where the bot lacks send permission', function () {
    Http::fake([
        config('trypost.platforms.discord.api').'/guilds/111222333/channels' => Http::response([
            ['id' => '1', 'name' => 'open', 'type' => 0],
            ['id' => '2', 'name' => 'locked', 'type' => 0, 'permission_overwrites' => [
                // @everyone overwrite denies SEND_MESSAGES (2048) on this channel.
                ['id' => '111222333', 'type' => 0, 'allow' => '0', 'deny' => '2048'],
            ]],
        ], 200),
        config('trypost.platforms.discord.api').'/guilds/111222333/roles' => Http::response([
            ['id' => '111222333', 'name' => '@everyone', 'permissions' => '3072'],
        ], 200),
        config('trypost.platforms.discord.api').'/guilds/111222333/members/999000111' => Http::response(['roles' => []], 200),
    ]);

    $response = $this->actingAs($this->user)
        ->getJson(route('app.discord.channels', $this->account->id));

    $response->assertOk();
    expect(collect($response->json('channels'))->pluck('name')->all())->toBe(['open']);
});

test('does not hide channels when the bot role lookup is unavailable', function () {
    // A failed members lookup must not blank the picker — fall back to type filtering.
    Http::fake([
        config('trypost.platforms.discord.api').'/guilds/111222333/channels' => Http::response([
            ['id' => '1', 'name' => 'general', 'type' => 0],
        ], 200),
        config('trypost.platforms.discord.api').'/guilds/111222333/roles' => Http::response([
            ['id' => '111222333', 'name' => '@everyone', 'permissions' => '3072'],
        ], 200),
        config('trypost.platforms.discord.api').'/guilds/111222333/members/999000111' => Http::response(['message' => 'Missing Access', 'code' => 50001], 403),
    ]);

    $response = $this->actingAs($this->user)
        ->getJson(route('app.discord.channels', $this->account->id));

    $response->assertOk();
    expect(collect($response->json('channels'))->pluck('name')->all())->toBe(['general']);
});

test('returns mention targets: specials, roles and members', function () {
    Http::fake([
        config('trypost.platforms.discord.api').'/guilds/111222333/roles' => Http::response([
            ['id' => '10', 'name' => 'moderators'],
        ], 200),
        config('trypost.platforms.discord.api').'/guilds/111222333/members/search*' => Http::response([
            ['user' => ['id' => '20', 'username' => 'mod_jane', 'global_name' => 'Jane']],
        ], 200),
    ]);

    $response = $this->actingAs($this->user)
        ->getJson(route('app.discord.mentions', $this->account->id).'?q=mod');

    $response->assertOk();
    $types = collect($response->json('mentions'))->pluck('type')->all();
    expect($types)->toContain('role')->toContain('user');
});

test('degrades to specials only when the roles/members lookup fails', function () {
    // A failed Discord call returns an error OBJECT, which must not be iterated
    // into bogus mention rows.
    Http::fake([
        config('trypost.platforms.discord.api').'/guilds/111222333/roles' => Http::response(['message' => 'Missing Access', 'code' => 50001], 403),
        config('trypost.platforms.discord.api').'/guilds/111222333/members/search*' => Http::response(['message' => 'Missing Access', 'code' => 50001], 403),
    ]);

    $response = $this->actingAs($this->user)
        ->getJson(route('app.discord.mentions', $this->account->id).'?q=mod');

    $response->assertOk();
    $types = collect($response->json('mentions'))->pluck('type');
    expect($types)->not->toContain('role')->not->toContain('user');
});

test('forbids looking up a discord account in another workspace', function () {
    $other = SocialAccount::factory()->discord()->create([
        'workspace_id' => Workspace::factory()->create()->id,
    ]);

    $this->actingAs($this->user)
        ->getJson(route('app.discord.channels', $other->id))
        ->assertForbidden();
});
