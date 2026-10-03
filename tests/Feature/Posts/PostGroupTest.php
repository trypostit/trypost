<?php

declare(strict_types=1);

use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\CreatePostsTool;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Queue::fake();
    Storage::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00', 'UTC'));

    $this->user = User::factory()->create(['timezone' => 'UTC']);
    $this->workspace = Workspace::factory()->create(['account_id' => $this->user->account_id, 'user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $this->channels = SocialAccount::factory()->count(3)->sequence(
        ['position' => 2],
        ['position' => 0],
        ['position' => 1],
    )->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
    ]);
});

function groupedPost(SocialAccount $channel, User $user, ?string $groupId, array $attributes = []): Post
{
    $post = Post::factory()->create(array_merge([
        'workspace_id' => $channel->workspace_id,
        'user_id' => $user->id,
        'post_group_id' => $groupId,
        'content' => 'Shared caption',
        'status' => PostStatus::Scheduled,
        'schedule_mode' => ScheduleMode::Custom,
        'scheduled_at' => now()->addDay(),
    ], $attributes));

    PostPlatform::factory()->create(['post_id' => $post->id, 'social_account_id' => $channel->id]);

    return $post;
}

function groupDestinations(iterable $channels): array
{
    return collect($channels)->map(fn (SocialAccount $channel): array => [
        'social_account_id' => $channel->id,
        'content_type' => ContentType::LinkedInPost->value,
        'meta' => [],
    ])->values()->all();
}

test('the composer gives every post of one creation the same group', function () {
    $this->actingAs($this->user)->post(route('app.posts.store'), [
        'status' => 'draft',
        'content' => 'Shared caption',
        'media' => [],
        'destinations' => groupDestinations($this->channels),
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->user)->post(route('app.posts.store'), [
        'status' => 'draft',
        'content' => 'Single caption',
        'media' => [],
        'destinations' => groupDestinations([$this->channels[0]]),
    ])->assertSessionHasNoErrors();

    $shared = Post::where('content', 'Shared caption')->pluck('post_group_id');
    $single = Post::where('content', 'Single caption')->sole();

    expect($shared)->toHaveCount(3)
        ->and($shared->unique())->toHaveCount(1)
        ->and($shared->first())->not->toBeNull()
        ->and($single->post_group_id)->not->toBeNull()
        ->and($single->post_group_id)->not->toBe($shared->first());
});

test('the API batch gives its posts one group', function () {
    $token = createApiTestToken(['workspace' => $this->workspace])['plain_token'];

    $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson(route('api.posts.batch.store'), [
            'status' => 'draft',
            'content' => 'Base',
            'destinations' => groupDestinations($this->channels->take(2)),
        ])
        ->assertCreated()
        ->assertJsonMissingPath('posts.0.group_id');

    $groups = Post::where('workspace_id', $this->workspace->id)->pluck('post_group_id');

    expect($groups)->toHaveCount(2)
        ->and($groups->unique())->toHaveCount(1)
        ->and($groups->first())->not->toBeNull();
});

test('the MCP batch tool gives its posts one group', function () {
    TryPostServer::actingAs($this->user)->tool(CreatePostsTool::class, [
        'status' => 'draft',
        'content' => 'Base',
        'destinations' => groupDestinations($this->channels->take(2)),
    ])->assertOk();

    $groups = Post::where('workspace_id', $this->workspace->id)->pluck('post_group_id');

    expect($groups)->toHaveCount(2)
        ->and($groups->unique())->toHaveCount(1)
        ->and($groups->first())->not->toBeNull();
});

test('a duplicate starts its own group and siblings stay independent', function () {
    $groupId = '0190a3b2-0000-7000-8000-00000000000a';
    $original = groupedPost($this->channels[0], $this->user, $groupId);
    $sibling = groupedPost($this->channels[1], $this->user, $groupId);

    $this->actingAs($this->user)
        ->post(route('app.posts.duplicate', $original))
        ->assertRedirect();

    $copy = Post::whereNotIn('id', [$original->id, $sibling->id])->sole();

    expect($copy->post_group_id)->not->toBeNull()
        ->and($copy->post_group_id)->not->toBe($groupId);

    $this->actingAs($this->user)
        ->delete(route('app.posts.destroy', $original))
        ->assertRedirect();

    expect($sibling->fresh())->not->toBeNull()
        ->and($sibling->fresh()->status)->toBe(PostStatus::Scheduled);
});

test('the group endpoint lists the posts created together, ordered by time then channel', function () {
    $groupId = '0190a3b2-0000-7000-8000-00000000000b';
    $later = groupedPost($this->channels[1], $this->user, $groupId, ['scheduled_at' => now()->addDays(3)]);
    $firstChannelLast = groupedPost($this->channels[0], $this->user, $groupId);
    $firstChannelFirst = groupedPost($this->channels[1], $this->user, $groupId);
    $draft = groupedPost($this->channels[2], $this->user, $groupId, ['status' => PostStatus::Draft, 'scheduled_at' => null, 'schedule_mode' => null]);
    groupedPost($this->channels[0], $this->user, '0190a3b2-0000-7000-8000-00000000000c');
    $foreignChannel = SocialAccount::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);
    groupedPost($foreignChannel, User::factory()->create(), $groupId);

    $response = $this->actingAs($this->user)
        ->getJson(route('app.posts.group.show', $firstChannelLast))
        ->assertOk()
        ->assertJsonCount(4)
        ->assertJsonPath('0.post_platforms.0.social_account.id', $this->channels[1]->id)
        ->assertJsonPath('0.can_delete', true)
        ->assertJsonPath('3.status', PostStatus::Draft->value);

    expect(collect($response->json())->pluck('id')->all())
        ->toBe([$firstChannelFirst->id, $firstChannelLast->id, $later->id, $draft->id]);
});

test('an ungrouped post is its own group', function () {
    $post = groupedPost($this->channels[0], $this->user, null);
    groupedPost($this->channels[1], $this->user, null);

    $this->actingAs($this->user)
        ->getJson(route('app.posts.group.show', $post))
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $post->id);
});

test('the group endpoint is tenancy scoped', function () {
    $foreignChannel = SocialAccount::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);
    $foreign = groupedPost($foreignChannel, User::factory()->create(), '0190a3b2-0000-7000-8000-00000000000d');

    $this->actingAs($this->user)
        ->getJson(route('app.posts.group.show', $foreign))
        ->assertNotFound();
});

test('the group endpoint carries the latest metrics of its sent posts', function () {
    $groupId = '0190a3b2-0000-7000-8000-000000000010';
    $channel = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    $sent = Post::factory()->published()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'post_group_id' => $groupId,
    ]);
    $target = PostPlatform::factory()->published()->create([
        'post_id' => $sent->id,
        'social_account_id' => $channel->id,
        'platform' => Platform::Instagram,
        'content_type' => ContentType::InstagramFeed,
    ]);
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $this->workspace->id,
        'social_account_id' => $channel->id,
        'social_account_key' => $channel->id,
        'post_platform_id' => $target->id,
        'platform' => Platform::Instagram,
        'network' => Platform::Instagram->network(),
        'remote_id' => $target->platform_post_id,
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $publication->id,
        'metrics' => ['reactions' => ['value' => 12, 'unit' => 'count', 'availability' => 'available']],
    ]);
    $scheduled = groupedPost($this->channels[0], $this->user, $groupId);

    $response = $this->actingAs($this->user)
        ->getJson(route('app.posts.group.show', $scheduled))
        ->assertOk()
        ->assertJsonCount(2);

    $posts = collect($response->json())->keyBy('id');

    expect(data_get($posts, "{$sent->id}.metrics.{$target->id}.available"))->toBeTrue()
        ->and(data_get($posts, "{$sent->id}.metrics.{$target->id}.metrics.reactions.value"))->toBe(12)
        ->and(data_get($posts, "{$scheduled->id}.metrics"))->toBeNull();
});

test('the group endpoint runs a fixed number of queries', function () {
    $groupId = '0190a3b2-0000-7000-8000-00000000000e';
    $post = groupedPost($this->channels[0], $this->user, $groupId);
    groupedPost($this->channels[1], $this->user, $groupId);

    $count = function () use ($post): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->user)->getJson(route('app.posts.group.show', $post))->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $count();
    $few = $count();
    groupedPost($this->channels[2], $this->user, $groupId);
    groupedPost($this->channels[0], $this->user, $groupId);

    expect($count())->toBe($few);
});

test('the group endpoint runs a fixed number of queries with sent siblings', function () {
    $groupId = '0190a3b2-0000-7000-8000-00000000000f';
    $channel = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    $sentSibling = function () use ($channel, $groupId): void {
        $sent = Post::factory()->published()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->user->id,
            'post_group_id' => $groupId,
        ]);
        $target = PostPlatform::factory()->published()->create([
            'post_id' => $sent->id,
            'social_account_id' => $channel->id,
            'platform' => Platform::Instagram,
            'content_type' => ContentType::InstagramFeed,
        ]);
        $publication = AnalyticsPublication::factory()->create([
            'workspace_id' => $this->workspace->id,
            'social_account_id' => $channel->id,
            'social_account_key' => $channel->id,
            'post_platform_id' => $target->id,
            'platform' => Platform::Instagram,
            'network' => Platform::Instagram->network(),
            'remote_id' => $target->platform_post_id,
        ]);
        AnalyticsPublicationDailySnapshot::factory()->create(['publication_id' => $publication->id]);
    };
    $post = groupedPost($this->channels[0], $this->user, $groupId);
    $sentSibling();

    $count = function () use ($post): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->user)->getJson(route('app.posts.group.show', $post))->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $count();
    $few = $count();
    $sentSibling();
    $sentSibling();

    expect($count())->toBe($few);
});

test('publish page cards report the size of their group', function () {
    $groupId = '0190a3b2-0000-7000-8000-00000000000f';
    $post = groupedPost($this->channels[0], $this->user, $groupId);
    groupedPost($this->channels[1], $this->user, $groupId);
    $alone = groupedPost($this->channels[2], $this->user, null);

    $this->actingAs($this->user)
        ->get(route('app.posts.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('posts.data', fn ($posts) => collect($posts)->firstWhere('id', $post->id)['group_posts_count'] === 2
                && collect($posts)->firstWhere('id', $post->id)['post_group_id'] === $groupId
                && collect($posts)->firstWhere('id', $alone->id)['group_posts_count'] === 0));
});
