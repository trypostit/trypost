<?php

declare(strict_types=1);

use App\Actions\Post\CreatePosts;
use App\Enums\Post\Status;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\CreatePostTool;
use App\Mcp\Tools\Post\UpdatePostTool;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Social\Discord\DiscordClient;
use App\Support\PostingSchedule;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;

beforeEach(function () {
    ['user' => $this->user, 'workspace' => $this->workspace, 'token' => $this->token] = parityContext();
    $this->discord = SocialAccount::factory()->discord()->create(['workspace_id' => $this->workspace->id]);
});

/**
 * Creates a Discord draft through one surface with only the channel id.
 *
 * @param  array<string, mixed>  $meta
 */
function discordChannelNameCreate(string $surface, array $meta): Post
{
    $before = Post::query()->pluck('id')->all();
    $destination = ['social_account_id' => test()->discord->id, 'content_type' => ContentType::DiscordMessage->value, 'meta' => $meta];

    match ($surface) {
        'web' => test()->actingAs(test()->user)->post(route('app.posts.store'), ['status' => 'draft', 'content' => 'Release notes', 'destinations' => [$destination]])->assertSessionHasNoErrors(),
        'api' => test()->withHeaders(parityApi(test()->token))->postJson(route('api.posts.store'), ['status' => 'draft', 'content' => 'Release notes', ...$destination])->assertCreated(),
        'mcp' => TryPostServer::actingAs(test()->user)->tool(CreatePostTool::class, ['status' => 'draft', 'content' => 'Release notes', ...$destination])->assertOk(),
    };

    return Post::query()->whereNotIn('id', $before)->sole();
}

/**
 * @param  array<string, mixed>  $meta
 */
function discordChannelNameUpdate(string $surface, Post $post, array $meta): void
{
    $payload = ['status' => Status::Draft->value, 'content' => $post->content, 'content_type' => ContentType::DiscordMessage->value, 'meta' => $meta];

    match ($surface) {
        'web' => test()->actingAs(test()->user)->put(route('app.posts.update', $post), [...$payload, 'media' => []])->assertSessionHasNoErrors()->assertRedirect(),
        'api' => test()->withHeaders(parityApi(test()->token))->putJson(route('api.posts.update', $post), $payload)->assertOk(),
        'mcp' => TryPostServer::actingAs(test()->user)->tool(UpdatePostTool::class, ['post_id' => $post->id, ...$payload])->assertOk(),
    };
}

test('a discord post created or edited with only the channel id stores its name on the web, api and mcp', function (string $surface) {
    $this->mock(DiscordClient::class, function (MockInterface $discord): void {
        $discord->shouldReceive('cachedChannels')->andReturn([
            ['id' => '111', 'name' => 'general'],
            ['id' => '222', 'name' => 'announcements'],
        ]);
        $discord->shouldNotReceive('channels');
    });

    $post = discordChannelNameCreate($surface, ['channel_id' => '111']);
    expect(data_get($post->meta, 'channel_name'))->toBe('general');

    auth()->forgetGuards();
    discordChannelNameUpdate($surface, $post, ['channel_id' => '222']);
    expect(data_get($post->fresh()->meta, 'channel_name'))->toBe('announcements');
})->with(['web', 'api', 'mcp']);

test('a discord channel name sent by the client is never stored on the web, api and mcp', function (string $surface, ?array $cached, ?string $stored) {
    $this->mock(DiscordClient::class)->shouldReceive('cachedChannels')->andReturn($cached);

    $post = discordChannelNameCreate($surface, ['channel_id' => '111', 'channel_name' => 'sent-by-client']);

    expect(data_get($post->meta, 'channel_name'))->toBe($stored);
})->with(['web', 'api', 'mcp'])->with([
    'the cached list names the channel' => [[['id' => '111', 'name' => 'general']], 'general'],
    'no cached list' => [null, null],
]);

test('without the channel in the cached list a discord post stores no channel name, even for the same channel', function (string $surface, ?array $cached) {
    $this->mock(DiscordClient::class, function (MockInterface $discord) use ($cached): void {
        $discord->shouldReceive('cachedChannels')->andReturn($cached);
        $discord->shouldNotReceive('channels');
    });
    $post = Post::factory()->forAccount($this->discord, ContentType::DiscordMessage)->create([
        'user_id' => $this->user->id,
        'content' => 'Release notes',
        'meta' => ['channel_id' => '999', 'channel_name' => 'old-channel'],
    ]);

    discordChannelNameUpdate($surface, $post, ['channel_id' => '999', 'channel_name' => 'old-channel']);

    expect($post->fresh()->meta)->toHaveKey('channel_id', '999')
        ->not->toHaveKey('channel_name');
})->with(['web', 'api', 'mcp'])->with([
    'channel not in the cached list' => [[['id' => '111', 'name' => 'general']]],
    'no cached list' => [null],
]);

test('only discord posts look up a channel name', function () {
    $this->mock(DiscordClient::class)->shouldNotReceive('cachedChannels');
    $linkedin = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn]);

    $this->withHeaders(parityApi($this->token))->postJson(route('api.posts.store'), [
        'status' => 'draft',
        'content' => 'Hello',
        'social_account_id' => $linkedin->id,
        'content_type' => ContentType::LinkedInPost->value,
        'meta' => ['channel_id' => '111'],
    ])->assertCreated();
});

test('duplicating a discord post reads its channel name from the cached list without asking discord', function () {
    $this->mock(DiscordClient::class, function (MockInterface $discord): void {
        $discord->shouldReceive('cachedChannels')->andReturn([['id' => '111', 'name' => 'general']]);
        $discord->shouldNotReceive('channels');
    });
    $post = Post::factory()->forAccount($this->discord, ContentType::DiscordMessage)->create([
        'user_id' => $this->user->id,
        'content' => 'Release notes',
        'meta' => ['channel_id' => '111', 'channel_name' => 'old-name'],
    ]);

    $this->actingAs($this->user)->post(route('app.posts.duplicate', $post))->assertRedirect();

    $copy = Post::query()->where('id', '!=', $post->id)->sole();
    expect($copy->meta)->toMatchArray(['channel_id' => '111', 'channel_name' => 'general']);
});

test('the channel list the web loads is the one a later save reads the name from', function () {
    config(['trypost.platforms.discord.bot_token' => 'BOTTOKEN', 'services.discord.client_id' => '999000111']);
    $guild = (string) $this->discord->platform_user_id;
    $api = config('trypost.platforms.discord.api');
    Http::fake([
        "{$api}/guilds/{$guild}/channels" => Http::response([['id' => '111', 'name' => 'general', 'type' => 0]]),
        "{$api}/guilds/{$guild}/roles" => Http::response([['id' => $guild, 'permissions' => '3072']]),
        "{$api}/guilds/{$guild}/members/*" => Http::response(['roles' => []]),
    ]);

    $this->actingAs($this->user)->getJson(route('app.discord.channels', $this->discord))->assertOk();
    auth()->forgetGuards();
    Http::fake();

    $post = discordChannelNameCreate('api', ['channel_id' => '111']);

    expect(data_get($post->meta, 'channel_name'))->toBe('general');
    Http::assertNothingSent();
});

test('approving, moving to drafts or adding to the queue keeps a discord post channel name while the channel list is cached and drops it without', function (string $action, ?array $cached, ?string $stored) {
    $this->mock(DiscordClient::class, function (MockInterface $discord) use ($cached): void {
        $discord->shouldReceive('cachedChannels')->andReturn($cached);
        $discord->shouldNotReceive('channels');
    });
    $this->discord->update(['posting_schedule' => PostingSchedule::empty()->withTime(1, '09:00')->withTime(3, '09:00')]);
    $requester = workspaceMember($this->workspace, 'approval');

    $post = CreatePosts::execute($this->workspace, $action === 'approve' ? $requester : $this->user, [
        'status' => $action === 'queue_next' ? 'draft' : 'scheduled',
        'scheduled_at' => $action === 'queue_next' ? null : now()->addDays(3)->toIso8601String(),
        'content' => 'Release notes',
        'media' => [],
        'destinations' => [['social_account_id' => $this->discord->id, 'content_type' => ContentType::DiscordMessage->value, 'meta' => ['channel_id' => '111', 'channel_name' => 'general']]],
    ])->sole();
    $post->forceFill(['meta' => ['channel_id' => '111', 'channel_name' => 'general']])->saveQuietly();

    expect($post->status)->toBe($action === 'approve' ? Status::PendingApproval : ($action === 'queue_next' ? Status::Draft : Status::Scheduled));

    $response = $action === 'approve'
        ? $this->actingAs($this->user)->put(route('app.posts.approve', $post))
        : $this->actingAs($this->user)->put(route('app.posts.schedule.update', $post), ['action' => $action]);

    $response->assertSessionHasNoErrors();
    expect(data_get($post->fresh()->meta, 'channel_id'))->toBe('111')
        ->and($post->fresh()->status)->toBe($action === 'draft' ? Status::Draft : Status::Scheduled)
        ->and(data_get($post->fresh()->meta, 'channel_name'))->toBe($stored);
})->with(['approve', 'draft', 'queue_next'])->with([
    'channel list cached' => [[['id' => '111', 'name' => 'general']], 'general'],
    'no cached list' => [null, null],
]);
