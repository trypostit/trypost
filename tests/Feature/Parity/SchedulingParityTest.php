<?php

declare(strict_types=1);

use App\Actions\Post\BuildPublishPageProps;
use App\Actions\Post\Queue\ListFreeQueueSlots;
use App\Actions\Post\Queue\ReflowChannelQueue;
use App\Actions\Post\UpdatePostRecurrence;
use App\Enums\Post\QueuePosition;
use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Http\Resources\App\ChannelPostingScheduleResource;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\ClearPostRecurrenceTool;
use App\Mcp\Tools\Post\CreatePostsTool;
use App\Mcp\Tools\Post\CreatePostTool;
use App\Mcp\Tools\Post\PublishPostTool;
use App\Mcp\Tools\Post\SetPostRecurrenceTool;
use App\Mcp\Tools\Post\UpdatePostTool;
use App\Mcp\Tools\SocialAccount\CopyPostingScheduleTool;
use App\Mcp\Tools\SocialAccount\GeneratePostingScheduleTool;
use App\Mcp\Tools\SocialAccount\GetPostingScheduleTool;
use App\Mcp\Tools\SocialAccount\ListFreeSlotsTool;
use App\Mcp\Tools\SocialAccount\MovePostToSlotTool;
use App\Mcp\Tools\SocialAccount\ReorderQueueTool;
use App\Mcp\Tools\SocialAccount\UpdatePostingScheduleTool;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Support\PostingSchedule;
use App\Support\RandomMinute;
use App\Support\Requests\Post\PostRequestRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

function schedulingParitySnapshot(Post $post): array
{
    return [
        'status' => $post->status,
        'schedule_mode' => $post->schedule_mode,
        'scheduled' => $post->scheduled_at !== null,
    ];
}

function schedulingParityDraft(SocialAccount $account, string $workspaceId, string $userId): Post
{
    return Post::factory()->forAccount($account, ContentType::LinkedInPost)->draft()->create(['workspace_id' => $workspaceId, 'user_id' => $userId, 'content' => 'Parity draft']);
}

beforeEach(function () {
    ['user' => $this->user, 'workspace' => $this->workspace, 'token' => $this->token] = parityContext();
    $this->account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
        'timezone' => 'UTC',
        'posting_schedule' => collect(range(0, 6))
            ->map(fn (int $day) => ['day' => $day, 'enabled' => true, 'times' => ['09:00', '15:00']])
            ->all(),
    ]);
    $this->destination = ['social_account_id' => $this->account->id, 'content_type' => 'linkedin_post'];
});

test('queue next through the api and the mcp batch tool takes the first two free slots', function () {
    $this->withHeaders(parityApi($this->token))->postJson(route('api.posts.store'), [
        'content' => 'From the API',
        'status' => 'scheduled',
        'queue' => 'next',
        ...$this->destination,
    ])->assertCreated();

    TryPostServer::actingAs($this->user)->tool(CreatePostsTool::class, [
        'content' => 'From MCP',
        'status' => 'scheduled',
        'queue' => 'next',
        'destinations' => [$this->destination],
    ])->assertOk();

    $posts = Post::query()->where('workspace_id', $this->workspace->id)->orderBy('scheduled_at')->get();

    expect($posts)->toHaveCount(2)
        ->and($posts->pluck('schedule_mode')->unique()->all())->toBe([ScheduleMode::Queue])
        ->and($posts->pluck('status')->unique()->all())->toBe([Status::Scheduled])
        ->and($posts[0]->scheduled_at->lt($posts[1]->scheduled_at))->toBeTrue();
});

test('queue top through the api and the mcp batch tool puts the new post first', function () {
    $this->withHeaders(parityApi($this->token))->postJson(route('api.posts.store'), [
        'content' => 'Next from the API',
        'status' => 'scheduled',
        'queue' => 'next',
        ...$this->destination,
    ])->assertCreated();

    $this->withHeaders(parityApi($this->token))->postJson(route('api.posts.store'), [
        'content' => 'Top from the API',
        'status' => 'scheduled',
        'queue' => 'top',
        ...$this->destination,
    ])->assertCreated();

    TryPostServer::actingAs($this->user)->tool(CreatePostsTool::class, [
        'content' => 'Top from MCP',
        'status' => 'scheduled',
        'queue' => 'top',
        'destinations' => [$this->destination],
    ])->assertOk();

    $ordered = Post::query()->where('workspace_id', $this->workspace->id)->orderBy('scheduled_at')->pluck('content')->all();

    expect($ordered[0])->toBe('Top from MCP')
        ->and($ordered)->toHaveCount(3);
});

test('a custom time scheduled through the api and the mcp batch tool is stored the same way', function () {
    $when = Carbon::now()->addDays(3)->startOfHour()->utc();

    $this->withHeaders(parityApi($this->token))->postJson(route('api.posts.store'), [
        'content' => 'Custom time',
        'status' => 'scheduled',
        'scheduled_at' => $when->toIso8601String(),
        ...$this->destination,
    ])->assertCreated();

    TryPostServer::actingAs($this->user)->tool(CreatePostsTool::class, [
        'content' => 'Custom time',
        'status' => 'scheduled',
        'scheduled_at' => $when->toIso8601String(),
        'destinations' => [$this->destination],
    ])->assertOk();

    $posts = Post::query()->where('workspace_id', $this->workspace->id)->get();

    expect($posts)->toHaveCount(2)
        ->and(schedulingParitySnapshot($posts[0]))->toEqual(schedulingParitySnapshot($posts[1]))
        ->and($posts[0]->schedule_mode)->toBe(ScheduleMode::Custom)
        ->and($posts[0]->scheduled_at->equalTo($when))->toBeTrue()
        ->and($posts[1]->scheduled_at->equalTo($when))->toBeTrue();
});

test('the single-post mcp create tool queues next like the api', function () {
    $this->withHeaders(parityApi($this->token))->postJson(route('api.posts.store'), [
        'content' => 'Queued via API',
        'status' => 'scheduled',
        'queue' => 'next',
        ...$this->destination,
    ])->assertCreated();

    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, [
        'content' => 'Queued via MCP',
        'status' => 'scheduled',
        'queue' => 'next',
        ...$this->destination,
    ])->assertOk();

    $viaApi = Post::query()->where('content', 'Queued via API')->sole();
    $viaMcp = Post::query()->where('content', 'Queued via MCP')->sole();

    expect(schedulingParitySnapshot($viaApi))->toEqual(schedulingParitySnapshot($viaMcp))
        ->and($viaMcp->status)->toBe(Status::Scheduled)
        ->and($viaMcp->schedule_mode)->toBe(ScheduleMode::Queue)
        ->and($viaApi->scheduled_at->lt($viaMcp->scheduled_at))->toBeTrue();
});

test('a custom time scheduled through the api and the single-post mcp create tool is stored the same way', function () {
    $when = Carbon::now()->addDays(3)->startOfHour()->utc();

    $this->withHeaders(parityApi($this->token))->postJson(route('api.posts.store'), [
        'content' => 'Custom via API',
        'status' => 'scheduled',
        'scheduled_at' => $when->toIso8601String(),
        ...$this->destination,
    ])->assertCreated();

    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, [
        'content' => 'Custom via MCP',
        'status' => 'scheduled',
        'scheduled_at' => $when->toIso8601String(),
        ...$this->destination,
    ])->assertOk();

    $viaApi = Post::query()->where('content', 'Custom via API')->sole();
    $viaMcp = Post::query()->where('content', 'Custom via MCP')->sole();

    expect(schedulingParitySnapshot($viaApi))->toEqual(schedulingParitySnapshot($viaMcp))
        ->and($viaMcp->status)->toBe(Status::Scheduled)
        ->and($viaMcp->schedule_mode)->toBe(ScheduleMode::Custom)
        ->and($viaMcp->scheduled_at->equalTo($when))->toBeTrue();
});

test('queue with a custom time is refused with the same message by the api and the single-post mcp create tool', function () {
    $payload = [
        'content' => 'Both',
        'status' => 'scheduled',
        'queue' => 'next',
        'scheduled_at' => Carbon::now()->addDays(3)->toIso8601String(),
        ...$this->destination,
    ];

    $this->withHeaders(parityApi($this->token))->postJson(route('api.posts.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['queue' => __('posts.errors.queue_with_scheduled_at')]);

    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, $payload)
        ->assertHasErrors([__('posts.errors.queue_with_scheduled_at')]);

    expect(Post::query()->where('workspace_id', $this->workspace->id)->count())->toBe(0);
});

test('scheduling an existing draft at a custom time through the api and the mcp update tool is stored the same way', function () {
    $viaApi = schedulingParityDraft($this->account, $this->workspace->id, $this->user->id);
    $viaMcp = schedulingParityDraft($this->account, $this->workspace->id, $this->user->id);
    $when = Carbon::now()->addDays(2)->startOfHour()->utc();

    $this->withHeaders(parityApi($this->token))->putJson(route('api.posts.update', $viaApi), [
        'status' => 'scheduled',
        'scheduled_at' => $when->toIso8601String(),
    ])->assertOk();

    TryPostServer::actingAs($this->user)->tool(UpdatePostTool::class, [
        'post_id' => $viaMcp->id,
        'status' => 'scheduled',
        'scheduled_at' => $when->toIso8601String(),
    ])->assertOk();

    expect(schedulingParitySnapshot($viaApi->fresh()))->toEqual(schedulingParitySnapshot($viaMcp->fresh()))
        ->and($viaApi->fresh()->status)->toBe(Status::Scheduled)
        ->and($viaApi->fresh()->schedule_mode)->toBe(ScheduleMode::Custom)
        ->and($viaMcp->fresh()->scheduled_at->equalTo($when))->toBeTrue();
});

test('queueing an existing draft through the api, the mcp update tool and the mcp publish tool all take free slots', function () {
    $viaApi = schedulingParityDraft($this->account, $this->workspace->id, $this->user->id);
    $viaUpdate = schedulingParityDraft($this->account, $this->workspace->id, $this->user->id);
    $viaPublish = schedulingParityDraft($this->account, $this->workspace->id, $this->user->id);

    $this->withHeaders(parityApi($this->token))->putJson(route('api.posts.update', $viaApi), [
        'status' => 'scheduled',
        'queue' => 'next',
    ])->assertOk();

    TryPostServer::actingAs($this->user)->tool(UpdatePostTool::class, [
        'post_id' => $viaUpdate->id,
        'status' => 'scheduled',
        'queue' => 'next',
    ])->assertOk();

    TryPostServer::actingAs($this->user)->tool(PublishPostTool::class, [
        'post_id' => $viaPublish->id,
        'queue' => 'next',
    ])->assertOk();

    $posts = collect([$viaApi, $viaUpdate, $viaPublish])->map->fresh();

    expect($posts->pluck('schedule_mode')->unique()->all())->toBe([ScheduleMode::Queue])
        ->and($posts->pluck('status')->unique()->all())->toBe([Status::Scheduled])
        ->and($posts->pluck('scheduled_at')->map->toIso8601String()->unique()->all())->toHaveCount(3);
});

test('publishing at a custom time through the api and the mcp publish tool is stored the same way', function () {
    $viaApi = schedulingParityDraft($this->account, $this->workspace->id, $this->user->id);
    $viaMcp = schedulingParityDraft($this->account, $this->workspace->id, $this->user->id);
    $when = Carbon::now()->addDays(4)->startOfHour()->utc();

    $this->withHeaders(parityApi($this->token))->putJson(route('api.posts.update', $viaApi), [
        'status' => 'scheduled',
        'scheduled_at' => $when->toIso8601String(),
    ])->assertOk();

    TryPostServer::actingAs($this->user)->tool(PublishPostTool::class, [
        'post_id' => $viaMcp->id,
        'scheduled_at' => $when->toIso8601String(),
    ])->assertOk();

    expect(schedulingParitySnapshot($viaApi->fresh()))->toEqual(schedulingParitySnapshot($viaMcp->fresh()));
});

test('queue_slot stores a queue post in that free slot on the api and the mcp tool', function () {
    $slots = collect($this->withHeaders(parityApi($this->token))->getJson(route('api.channels.queue.slots', $this->account))->assertOk()->json('slots'));

    $this->withHeaders(parityApi($this->token))->postJson(route('api.posts.store'), [
        'content' => 'Slot via API',
        'status' => 'scheduled',
        'queue_slot' => $slots[2],
        ...$this->destination,
    ])->assertCreated();
    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, [
        'content' => 'Slot via MCP',
        'status' => 'scheduled',
        'queue_slot' => $slots[4],
        ...$this->destination,
    ])->assertOk();

    $posts = Post::query()->where('workspace_id', $this->workspace->id)->orderBy('scheduled_at')->get();

    expect($posts->map(fn (Post $post) => $post->scheduled_at->toIso8601ZuluString())->all())->toBe([$slots[2], $slots[4]])
        ->and($posts->pluck('schedule_mode')->unique()->all())->toBe([ScheduleMode::Queue])
        ->and($posts->pluck('status')->unique()->all())->toBe([Status::Scheduled]);
});

test('queue_slot on a slot already taken is refused with the web message on the api and the mcp tool', function () {
    $slot = $this->withHeaders(parityApi($this->token))->getJson(route('api.channels.queue.slots', $this->account))->assertOk()->json('slots.1');
    $payload = ['content' => 'Slot', 'status' => 'scheduled', 'queue_slot' => $slot, ...$this->destination];

    $this->withHeaders(parityApi($this->token))->postJson(route('api.posts.store'), $payload)->assertCreated();
    $this->withHeaders(parityApi($this->token))->postJson(route('api.posts.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['queue_slot' => __('posts.errors.queue_order_stale')]);
    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, $payload)->assertHasErrors([__('posts.errors.queue_order_stale')]);

    expect(Post::query()->where('workspace_id', $this->workspace->id)->count())->toBe(1);
});

test('queue_slot at an instant that is not a free slot is refused', function () {
    $this->withHeaders(parityApi($this->token))->postJson(route('api.posts.store'), [
        'content' => 'Off schedule',
        'status' => 'scheduled',
        'queue_slot' => now()->addDays(3)->startOfDay()->addHours(11)->toIso8601ZuluString(),
        ...$this->destination,
    ])->assertUnprocessable()->assertJsonValidationErrors(['queue_slot']);

    expect(Post::query()->where('workspace_id', $this->workspace->id)->count())->toBe(0);
});

test('queue_slot is refused with queue or without status scheduled', function () {
    $slot = $this->withHeaders(parityApi($this->token))->getJson(route('api.channels.queue.slots', $this->account))->assertOk()->json('slots.0');

    $this->withHeaders(parityApi($this->token))->postJson(route('api.posts.store'), [
        'status' => 'scheduled', 'queue' => 'next', 'queue_slot' => $slot, ...$this->destination,
    ])->assertUnprocessable()->assertJsonValidationErrors(['queue_slot']);
    $this->withHeaders(parityApi($this->token))->postJson(route('api.posts.store'), [
        'status' => 'draft', 'queue_slot' => $slot, ...$this->destination,
    ])->assertUnprocessable()->assertJsonValidationErrors(['queue_slot']);
});

test('a member who needs approval creating at a queue_slot holds the slot as a pending request', function () {
    $member = workspaceMember($this->workspace, 'approval');
    $slot = $this->withHeaders(parityApi($this->token))->getJson(route('api.channels.queue.slots', $this->account))->assertOk()->json('slots.0');
    auth()->forgetGuards();

    TryPostServer::actingAs($member)->tool(CreatePostTool::class, [
        'content' => 'Needs approval',
        'status' => 'scheduled',
        'queue_slot' => $slot,
        ...$this->destination,
    ])->assertOk();

    $post = Post::query()->where('workspace_id', $this->workspace->id)->sole();
    $free = collect(ListFreeQueueSlots::handle($this->account))->map(fn ($at) => $at->toIso8601ZuluString());

    expect($post->status)->toBe(Status::PendingApproval)
        ->and($post->scheduled_at->toIso8601ZuluString())->toBe($slot)
        ->and($free->contains($slot))->toBeFalse();
});

test('publishing a draft through mcp refuses a time past the 2038-01-19 ceiling like the api', function () {
    $draft = schedulingParityDraft($this->account, $this->workspace->id, $this->user->id);

    $this->withHeaders(parityApi($this->token))
        ->putJson(route('api.posts.update', $draft), ['status' => 'scheduled', 'scheduled_at' => '2038-02-01T10:00:00Z'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['scheduled_at']);
    TryPostServer::actingAs($this->user)
        ->tool(PublishPostTool::class, ['post_id' => $draft->id, 'scheduled_at' => '2038-02-01T10:00:00Z'])
        ->assertHasErrors(['The scheduled at field must be a date before 2038-01-19.']);

    $rules = Validator::make(['post_id' => $draft->id, 'scheduled_at' => '2038-02-01T10:00:00Z'], PostRequestRules::publish());

    expect($rules->errors()->first('scheduled_at'))->toBe('The scheduled at field must be a date before 2038-01-19.')
        ->and($draft->fresh()->status)->toBe(Status::Draft);
});

test('the posting schedule reads the same on the web settings page, the api and the mcp tool', function () {
    $this->account->update(['timezone' => 'America/Sao_Paulo', 'posting_goal' => 7]);
    $expected = ChannelPostingScheduleResource::make($this->account->fresh())->resolve();

    $this->actingAs($this->user)->get(route('app.channels.settings', $this->account))
        ->assertInertia(fn ($page) => $page->where('schedule', fn ($schedule) => $schedule->toArray() === [
            'timezone' => 'America/Sao_Paulo',
            'posting_goal' => 7,
            'posting_schedule' => $expected['posting_schedule'],
        ]));
    auth()->forgetGuards();

    $api = $this->withHeaders(parityApi($this->token))->getJson(route('api.channels.posting-schedule.show', $this->account))->assertOk()->json();
    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)->tool(GetPostingScheduleTool::class, ['account_id' => $this->account->id])
        ->assertOk()->assertStructuredContent($api);

    expect($api)->toEqual(['id' => $this->account->id, ...$expected])
        ->and($api['timezone'])->toBe('America/Sao_Paulo')
        ->and($api['posting_goal'])->toBe(7)
        ->and($api['posting_schedule'])->toHaveCount(7);
});

test('a channel without a schedule reads a null schedule', function () {
    $bare = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::X, 'posting_schedule' => null]);

    $this->withHeaders(parityApi($this->token))->getJson(route('api.channels.posting-schedule.show', $bare))
        ->assertOk()->assertJsonPath('posting_schedule', null);
    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->getJson(route('api.channels.queue.slots', $bare))
        ->assertOk()->assertExactJson(['slots' => []]);
});

test('the posting schedule and free slots of a foreign channel are refused on the api and mcp', function () {
    $foreign = SocialAccount::factory()->create(['workspace_id' => Workspace::factory()->create()->id, 'platform' => Platform::LinkedIn]);

    $this->withHeaders(parityApi($this->token))->getJson(route('api.channels.posting-schedule.show', $foreign))->assertNotFound();
    $this->withHeaders(parityApi($this->token))->getJson(route('api.channels.queue.slots', $foreign))->assertNotFound();
    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)->tool(GetPostingScheduleTool::class, ['account_id' => $foreign->id])->assertHasErrors();
    TryPostServer::actingAs($this->user)->tool(ListFreeSlotsTool::class, ['account_id' => $foreign->id])->assertHasErrors();
    TryPostServer::actingAs($this->user)->tool(ListFreeSlotsTool::class, ['account_id' => 'nope'])->assertHasErrors();
});

test('free slots skip a scheduled post and a pending queue request holding a slot, on the api and the mcp tool', function () {
    $slots = collect($this->account->posting_schedule->nextSlots(now()->addMinute(), 'UTC', 4));

    $scheduled = schedulingParityDraft($this->account, $this->workspace->id, $this->user->id);
    $scheduled->update(['status' => Status::Scheduled, 'schedule_mode' => ScheduleMode::Custom, 'scheduled_at' => $slots[0]]);
    $pending = schedulingParityDraft($this->account, $this->workspace->id, $this->user->id);
    $pending->update(['status' => Status::PendingApproval, 'schedule_mode' => ScheduleMode::Queue, 'scheduled_at' => $slots[1]]);

    $api = $this->withHeaders(parityApi($this->token))->getJson(route('api.channels.queue.slots', $this->account))->assertOk()->json('slots');
    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)->tool(ListFreeSlotsTool::class, ['account_id' => $this->account->id])
        ->assertOk()->assertStructuredContent(['slots' => $api]);

    expect($api)->toHaveCount(ListFreeQueueSlots::LIMIT)
        ->and(array_slice($api, 0, 2))->toBe([$slots[2]->toIso8601ZuluString(), $slots[3]->toIso8601ZuluString()]);

    foreach ($api as $instant) {
        expect(ReflowChannelQueue::isFreeSlot($this->account, Carbon::parse($instant)))->toBeTrue();
    }
});

test('free slots stop at the queue horizon and every listed slot is accepted by move to slot on the api and the mcp tool', function () {
    $this->account->update(['posting_schedule' => collect(range(0, 6))
        ->map(fn (int $day) => ['day' => $day, 'enabled' => $day === 2, 'times' => $day === 2 ? ['09:00'] : []])
        ->all()]);

    $api = $this->withHeaders(parityApi($this->token))->getJson(route('api.channels.queue.slots', $this->account))->assertOk()->json('slots');
    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)->tool(ListFreeSlotsTool::class, ['account_id' => $this->account->id])
        ->assertOk()->assertStructuredContent(['slots' => $api]);

    expect($api)->not->toBeEmpty()
        ->and(count($api))->toBeLessThan(ListFreeQueueSlots::LIMIT)
        ->and(Carbon::parse(last($api))->lessThanOrEqualTo(now()->addDays(BuildPublishPageProps::MAX_QUEUE_DAYS)))->toBeTrue();

    $posts = collect(range(0, 1))->map(function (): Post {
        $post = schedulingParityDraft($this->account, $this->workspace->id, $this->user->id);
        $post->update(['status' => Status::Scheduled, 'schedule_mode' => ScheduleMode::Custom, 'scheduled_at' => now()->addDays(3)->setTime(10, 7)]);

        return $post;
    });
    $last = last($api);

    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->putJson(route('api.channels.queue.slot', $this->account), ['post_id' => $posts[0]->id, 'slot_at' => $last])->assertOk();
    auth()->forgetGuards();
    $secondLast = $api[count($api) - 2];
    TryPostServer::actingAs($this->user)->tool(MovePostToSlotTool::class, ['account_id' => $this->account->id, 'post_id' => $posts[1]->id, 'slot_at' => $secondLast])->assertOk();
});

test('free slots follow the channel time zone', function () {
    $this->account->update(['timezone' => 'Asia/Tokyo']);
    $expected = collect($this->account->fresh()->posting_schedule->nextSlots(now()->addMinute(), 'Asia/Tokyo', 3))
        ->map(fn ($slot) => $slot->toIso8601ZuluString())->all();

    $api = $this->withHeaders(parityApi($this->token))->getJson(route('api.channels.queue.slots', $this->account))->assertOk()->json('slots');

    expect(array_slice($api, 0, 3))->toBe($expected);
});

function schedulingParityQueued(SocialAccount $account, string $workspaceId, string $userId, int $count): array
{
    return collect(range(1, $count))->map(function () use ($account, $workspaceId, $userId): Post {
        $post = schedulingParityDraft($account, $workspaceId, $userId);
        $post->update(['status' => Status::Scheduled, 'schedule_mode' => ScheduleMode::Queue, 'scheduled_at' => null]);
        ReflowChannelQueue::handle($account, $post, QueuePosition::Next);

        return $post->fresh();
    })->all();
}

function schedulingParityQueueOrder(SocialAccount $account): array
{
    return Post::query()->queuedOn($account->id, now())->orderBy('scheduled_at')->pluck('id')->all();
}

test('reordering the queue through the web, the api and the mcp tool swaps the posts among their slots', function () {
    [$a, $b, $c] = schedulingParityQueued($this->account, $this->workspace->id, $this->user->id, 3);
    $slots = collect([$a, $b, $c])->map->scheduled_at->map->toIso8601String()->all();

    $this->actingAs($this->user)->putJson(route('app.channels.queue.order', $this->account), ['post_ids' => [$c->id, $b->id, $a->id]])->assertRedirect();
    expect(schedulingParityQueueOrder($this->account))->toBe([$c->id, $b->id, $a->id]);
    auth()->forgetGuards();

    $this->withHeaders(parityApi($this->token))->putJson(route('api.channels.queue.order', $this->account), ['post_ids' => [$a->id, $b->id, $c->id]])->assertNoContent();
    expect(schedulingParityQueueOrder($this->account))->toBe([$a->id, $b->id, $c->id]);

    TryPostServer::actingAs($this->user)->tool(ReorderQueueTool::class, ['account_id' => $this->account->id, 'post_ids' => [$b->id, $a->id]])
        ->assertOk()->assertStructuredContent(['post_ids' => [$b->id, $a->id]]);

    expect(schedulingParityQueueOrder($this->account))->toBe([$b->id, $a->id, $c->id])
        ->and(Post::query()->queuedOn($this->account->id, now())->orderBy('scheduled_at')->get()->map->scheduled_at->map->toIso8601String()->all())->toBe($slots);
});

test('reordering with ids that are not the head of the queue is refused with the web message on every surface', function () {
    [$a, $b, $c] = schedulingParityQueued($this->account, $this->workspace->id, $this->user->id, 3);
    $stale = [$b->id, $c->id];

    $this->actingAs($this->user)->putJson(route('app.channels.queue.order', $this->account), ['post_ids' => $stale])
        ->assertUnprocessable()->assertJsonValidationErrors(['post_ids' => __('posts.errors.queue_order_stale')]);
    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->putJson(route('api.channels.queue.order', $this->account), ['post_ids' => $stale])
        ->assertUnprocessable()->assertJsonValidationErrors(['post_ids' => __('posts.errors.queue_order_stale')]);
    TryPostServer::actingAs($this->user)->tool(ReorderQueueTool::class, ['account_id' => $this->account->id, 'post_ids' => $stale])
        ->assertHasErrors([__('posts.errors.queue_order_stale')]);
    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->putJson(route('api.channels.queue.order', $this->account), ['post_ids' => [$a->id, $a->id]])
        ->assertUnprocessable()->assertJsonValidationErrors(['post_ids.0', 'post_ids.1']);

    expect(schedulingParityQueueOrder($this->account))->toBe([$a->id, $b->id, $c->id]);
});

test('moving a scheduled post onto a free slot through the web, the api and the mcp tool makes it a queue post there', function () {
    $slots = collect($this->account->posting_schedule->nextSlots(now()->addMinute(), 'UTC', 6));
    $posts = collect(range(0, 2))->map(function (int $index): Post {
        $post = schedulingParityDraft($this->account, $this->workspace->id, $this->user->id);
        $post->update(['status' => Status::Scheduled, 'schedule_mode' => ScheduleMode::Custom, 'scheduled_at' => now()->addDays(9 + $index)->setTime(10, 7)]);

        return $post;
    });

    $this->actingAs($this->user)->putJson(route('app.channels.queue.slot', $this->account), ['post_id' => $posts[0]->id, 'slot_at' => $slots[1]->toIso8601ZuluString()])->assertRedirect();
    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->putJson(route('api.channels.queue.slot', $this->account), ['post_id' => $posts[1]->id, 'slot_at' => $slots[2]->toIso8601ZuluString()])
        ->assertOk()->assertJsonPath('id', $posts[1]->id)->assertJsonPath('schedule_mode', 'queue')->assertJsonPath('status', 'scheduled');
    auth()->forgetGuards();
    TryPostServer::actingAs($this->user)->tool(MovePostToSlotTool::class, ['account_id' => $this->account->id, 'post_id' => $posts[2]->id, 'slot_at' => $slots[3]->toIso8601ZuluString()])
        ->assertOk()->assertStructuredContent(fn ($json) => $json->where('id', $posts[2]->id)->where('schedule_mode', 'queue')->where('status', 'scheduled')->etc());

    foreach ([1, 2, 3] as $position => $slot) {
        $fresh = $posts[$position]->fresh();
        expect($fresh->schedule_mode)->toBe(ScheduleMode::Queue)->and($fresh->scheduled_at->equalTo($slots[$slot]))->toBeTrue();
    }
});

test('moving a post onto a taken, pending-held or off-schedule slot, or another workspace post, is refused on every surface', function () {
    $slots = collect($this->account->posting_schedule->nextSlots(now()->addMinute(), 'UTC', 4));
    $held = schedulingParityDraft($this->account, $this->workspace->id, $this->user->id);
    $held->update(['status' => Status::PendingApproval, 'schedule_mode' => ScheduleMode::Queue, 'scheduled_at' => $slots[0]]);
    $mover = schedulingParityDraft($this->account, $this->workspace->id, $this->user->id);
    $mover->update(['status' => Status::Scheduled, 'schedule_mode' => ScheduleMode::Custom, 'scheduled_at' => now()->addDays(9)->setTime(10, 7)]);
    $foreignAccount = SocialAccount::factory()->create(['workspace_id' => Workspace::factory()->create()->id, 'platform' => Platform::LinkedIn]);
    $foreignPost = schedulingParityDraft($foreignAccount, $foreignAccount->workspace_id, $this->user->id);
    $foreignPost->update(['status' => Status::Scheduled, 'schedule_mode' => ScheduleMode::Custom, 'scheduled_at' => now()->addDays(9)]);

    $cases = [
        [$mover->id, $slots[0]->toIso8601ZuluString()],
        [$mover->id, $slots[1]->addMinutes(7)->toIso8601ZuluString()],
        [$foreignPost->id, $slots[1]->toIso8601ZuluString()],
    ];

    foreach ($cases as [$postId, $slotAt]) {
        $this->withHeaders(parityApi($this->token))->putJson(route('api.channels.queue.slot', $this->account), ['post_id' => $postId, 'slot_at' => $slotAt])
            ->assertUnprocessable()->assertJsonValidationErrors(['slot_at' => __('posts.errors.queue_order_stale')]);
        TryPostServer::actingAs($this->user)->tool(MovePostToSlotTool::class, ['account_id' => $this->account->id, 'post_id' => $postId, 'slot_at' => $slotAt])
            ->assertHasErrors([__('posts.errors.queue_order_stale')]);
        auth()->forgetGuards();
    }

    expect($mover->fresh()->schedule_mode)->toBe(ScheduleMode::Custom)
        ->and($foreignPost->fresh()->schedule_mode)->toBe(ScheduleMode::Custom);
});

test('a foreign channel is refused on the queue order and slot routes and tools', function () {
    $foreign = SocialAccount::factory()->create(['workspace_id' => Workspace::factory()->create()->id, 'platform' => Platform::LinkedIn]);
    $id = (string) Str::uuid();

    $this->withHeaders(parityApi($this->token))->putJson(route('api.channels.queue.order', $foreign), ['post_ids' => [$id]])->assertNotFound();
    $this->withHeaders(parityApi($this->token))->putJson(route('api.channels.queue.slot', $foreign), ['post_id' => $id, 'slot_at' => now()->addDay()->toIso8601String()])->assertNotFound();

    TryPostServer::actingAs($this->user)->tool(ReorderQueueTool::class, ['account_id' => $foreign->id, 'post_ids' => [$id]])->assertHasErrors();
    TryPostServer::actingAs($this->user)->tool(MovePostToSlotTool::class, ['account_id' => $foreign->id, 'post_id' => $id, 'slot_at' => now()->addDay()->toIso8601String()])->assertHasErrors();
    TryPostServer::actingAs($this->user)->tool(ReorderQueueTool::class, ['account_id' => $this->account->id, 'post_ids' => ['nope']])->assertHasErrors();
});

test('a busy channel lock answers 409 on the api and an error on the mcp tools, and changes nothing', function () {
    [$a, $b] = schedulingParityQueued($this->account, $this->workspace->id, $this->user->id, 2);
    $mover = schedulingParityDraft($this->account, $this->workspace->id, $this->user->id);
    $mover->update(['status' => Status::Scheduled, 'schedule_mode' => ScheduleMode::Custom, 'scheduled_at' => now()->addDays(9)->setTime(10, 7)]);
    $slot = $this->account->posting_schedule->nextSlots(now()->addMinute(), 'UTC', 3)[2]->toIso8601ZuluString();
    $lock = Cache::lock("queue:{$this->account->id}", 30);
    expect($lock->get())->toBeTrue();
    $this->travelBack();

    try {
        $this->withHeaders(parityApi($this->token))->putJson(route('api.channels.queue.order', $this->account), ['post_ids' => [$b->id, $a->id]])->assertConflict();
        $this->withHeaders(parityApi($this->token))->putJson(route('api.channels.queue.slot', $this->account), ['post_id' => $mover->id, 'slot_at' => $slot])->assertConflict();
        auth()->forgetGuards();
        TryPostServer::actingAs($this->user)->tool(ReorderQueueTool::class, ['account_id' => $this->account->id, 'post_ids' => [$b->id, $a->id]])->assertHasErrors([__('posts.errors.queue_busy')]);
        TryPostServer::actingAs($this->user)->tool(MovePostToSlotTool::class, ['account_id' => $this->account->id, 'post_id' => $mover->id, 'slot_at' => $slot])->assertHasErrors([__('posts.errors.queue_busy')]);
    } finally {
        $lock->release();
    }

    expect(schedulingParityQueueOrder($this->account))->toBe([$a->id, $b->id])
        ->and($mover->fresh()->schedule_mode)->toBe(ScheduleMode::Custom);
});

test('a member who needs approval is refused on both queue routes of the api', function () {
    $member = workspaceMember($this->workspace, 'approval');
    $headers = parityApi(passportToken($member, $this->workspace));
    $id = (string) Str::uuid();

    $this->withHeaders($headers)->putJson(route('api.channels.queue.order', $this->account), ['post_ids' => [$id]])->assertForbidden();
    $this->withHeaders($headers)->putJson(route('api.channels.queue.slot', $this->account), ['post_id' => $id, 'slot_at' => now()->addDay()->toIso8601String()])->assertForbidden();
});

function schedulingParityRecurring(SocialAccount $account, string $workspaceId, string $userId, Status $status = Status::Scheduled): Post
{
    $post = schedulingParityDraft($account, $workspaceId, $userId);
    $post->update(['status' => $status, 'schedule_mode' => ScheduleMode::Custom, 'scheduled_at' => $status === Status::Scheduled ? now()->addDays(5)->setTime(10, 7) : null]);

    return $post->fresh();
}

function schedulingParityRecurrence(Post $post): array
{
    $post = $post->fresh();

    return [
        'interval' => $post->recurrence_interval,
        'frequency' => $post->recurrence_frequency?->value,
        'remaining' => $post->recurrence_remaining,
    ];
}

test('setting a recurrence through the web, the api and the mcp tool stores the same rule', function () {
    $rule = ['interval' => 2, 'frequency' => 'week', 'times' => 4];
    $expected = ['interval' => 2, 'frequency' => 'week', 'remaining' => 4];
    $posts = collect(range(1, 3))->map(fn () => schedulingParityRecurring($this->account, $this->workspace->id, $this->user->id));

    $this->actingAs($this->user)->patchJson(route('app.posts.recurrence.update', $posts[0]), $rule)->assertRedirect();
    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->patchJson(route('api.posts.recurrence.update', $posts[1]), $rule)->assertOk()->assertJsonPath('id', $posts[1]->id)->assertJsonPath('recurrence', $expected);
    TryPostServer::actingAs($this->user)->tool(SetPostRecurrenceTool::class, ['post_id' => $posts[2]->id, ...$rule])
        ->assertOk()->assertStructuredContent(fn ($json) => $json->where('id', $posts[2]->id)->where('recurrence', $expected)->etc());

    foreach ($posts as $post) {
        expect(schedulingParityRecurrence($post))->toBe($expected);
    }
});

test('clearing a recurrence through the web, the api and the mcp tool removes the rule', function () {
    $posts = collect(range(1, 3))->map(function () {
        $post = schedulingParityRecurring($this->account, $this->workspace->id, $this->user->id);
        UpdatePostRecurrence::execute($post, ['interval' => 1, 'frequency' => 'day', 'times' => 3]);

        return $post;
    });

    $this->actingAs($this->user)->deleteJson(route('app.posts.recurrence.destroy', $posts[0]))->assertRedirect();
    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->deleteJson(route('api.posts.recurrence.destroy', $posts[1]))->assertOk()->assertJsonPath('id', $posts[1]->id)->assertJsonPath('recurrence', null);
    TryPostServer::actingAs($this->user)->tool(ClearPostRecurrenceTool::class, ['post_id' => $posts[2]->id])
        ->assertOk()->assertStructuredContent(fn ($json) => $json->where('recurrence', null)->etc());

    foreach ($posts as $post) {
        expect(schedulingParityRecurrence($post))->toBe(['interval' => null, 'frequency' => null, 'remaining' => null]);
    }
});

test('an invalid recurrence is refused with the web messages on every surface', function () {
    $post = schedulingParityRecurring($this->account, $this->workspace->id, $this->user->id);
    $invalid = ['interval' => 0, 'frequency' => 'decade', 'times' => 101];

    $web = $this->actingAs($this->user)->patchJson(route('app.posts.recurrence.update', $post), $invalid)->assertUnprocessable()->json('errors');
    auth()->forgetGuards();
    $api = $this->withHeaders(parityApi($this->token))->patchJson(route('api.posts.recurrence.update', $post), $invalid)->assertUnprocessable()->json('errors');
    $mcp = TryPostServer::actingAs($this->user)->tool(SetPostRecurrenceTool::class, ['post_id' => $post->id, ...$invalid]);

    expect($api)->toBe($web);
    foreach (collect($web)->flatten() as $message) {
        $mcp->assertHasErrors([$message]);
    }

    auth()->forgetGuards();
    $tooFar = ['interval' => 365, 'frequency' => 'year', 'times' => 100];
    $this->withHeaders(parityApi($this->token))->patchJson(route('api.posts.recurrence.update', $post), $tooFar)
        ->assertUnprocessable()->assertJsonValidationErrors(['times' => __('posts.recurrence.errors.too_far')]);
    TryPostServer::actingAs($this->user)->tool(SetPostRecurrenceTool::class, ['post_id' => $post->id, ...$tooFar])->assertHasErrors([__('posts.recurrence.errors.too_far')]);

    expect(schedulingParityRecurrence($post)['interval'])->toBeNull();
});

test('the recurrence ceiling is checked in the channel time zone on every surface', function () {
    $this->user->update(['timezone' => 'UTC']);
    $newYork = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
        'timezone' => 'America/New_York',
    ]);
    $post = schedulingParityRecurring($newYork, $this->workspace->id, $this->user->id);
    $post->update(['scheduled_at' => CarbonImmutable::parse('2037-06-01 23:00', 'UTC')]);
    $nearCeiling = ['interval' => 213, 'frequency' => 'day', 'times' => 1];

    $this->actingAs($this->user)->patchJson(route('app.posts.recurrence.update', $post), $nearCeiling)
        ->assertUnprocessable()->assertJsonValidationErrors(['times' => __('posts.recurrence.errors.too_far')]);
    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->patchJson(route('api.posts.recurrence.update', $post), $nearCeiling)
        ->assertUnprocessable()->assertJsonValidationErrors(['times' => __('posts.recurrence.errors.too_far')]);
    TryPostServer::actingAs($this->user)->tool(SetPostRecurrenceTool::class, ['post_id' => $post->id, ...$nearCeiling])
        ->assertHasErrors([__('posts.recurrence.errors.too_far')]);

    expect(schedulingParityRecurrence($post)['interval'])->toBeNull();
});

test('a post that is not scheduled cannot get a recurrence on any surface', function () {
    $draft = schedulingParityRecurring($this->account, $this->workspace->id, $this->user->id, Status::Draft);
    $rule = ['interval' => 1, 'frequency' => 'day', 'times' => 2];

    $this->actingAs($this->user)->patchJson(route('app.posts.recurrence.update', $draft), $rule)
        ->assertUnprocessable()->assertJsonValidationErrors(['post' => __('posts.recurrence.errors.not_scheduled')]);
    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->patchJson(route('api.posts.recurrence.update', $draft), $rule)
        ->assertUnprocessable()->assertJsonValidationErrors(['post' => __('posts.recurrence.errors.not_scheduled')]);
    TryPostServer::actingAs($this->user)->tool(SetPostRecurrenceTool::class, ['post_id' => $draft->id, ...$rule])
        ->assertHasErrors([__('posts.recurrence.errors.not_scheduled')]);

    expect(schedulingParityRecurrence($draft)['interval'])->toBeNull();
});

test('a post of another workspace cannot get or lose a recurrence on any surface', function () {
    $foreignAccount = SocialAccount::factory()->create(['workspace_id' => Workspace::factory()->create()->id, 'platform' => Platform::LinkedIn]);
    $foreign = schedulingParityRecurring($foreignAccount, $foreignAccount->workspace_id, $this->user->id);
    UpdatePostRecurrence::execute($foreign, ['interval' => 1, 'frequency' => 'day', 'times' => 3]);
    $rule = ['interval' => 2, 'frequency' => 'week', 'times' => 4];

    $this->withHeaders(parityApi($this->token))->patchJson(route('api.posts.recurrence.update', $foreign), $rule)->assertNotFound();
    $this->withHeaders(parityApi($this->token))->deleteJson(route('api.posts.recurrence.destroy', $foreign))->assertNotFound();
    TryPostServer::actingAs($this->user)->tool(SetPostRecurrenceTool::class, ['post_id' => $foreign->id, ...$rule])->assertHasErrors();
    TryPostServer::actingAs($this->user)->tool(ClearPostRecurrenceTool::class, ['post_id' => $foreign->id])->assertHasErrors();

    expect(schedulingParityRecurrence($foreign))->toBe(['interval' => 1, 'frequency' => 'day', 'remaining' => 3]);
});

test('a member who needs approval cannot set a recurrence through the api or the mcp tool', function () {
    $member = workspaceMember($this->workspace, 'approval');
    $post = schedulingParityRecurring($this->account, $this->workspace->id, $member->id);
    $rule = ['interval' => 1, 'frequency' => 'day', 'times' => 2];

    $this->withHeaders(parityApi(passportToken($member, $this->workspace)))->patchJson(route('api.posts.recurrence.update', $post), $rule)->assertForbidden();
    TryPostServer::actingAs($member)->tool(SetPostRecurrenceTool::class, ['post_id' => $post->id, ...$rule])->assertHasErrors([__('This action is unauthorized.')]);

    expect(schedulingParityRecurrence($post)['interval'])->toBeNull();
});

function schedulingParityChannelWithQueue(Workspace $workspace, string $userId, int $count = 3): SocialAccount
{
    $channel = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::LinkedIn,
        'timezone' => 'UTC',
        'posting_schedule' => collect(range(0, 6))
            ->map(fn (int $day) => ['day' => $day, 'enabled' => true, 'times' => ['09:00', '15:00']])
            ->all(),
    ]);
    schedulingParityQueued($channel, $workspace->id, $userId, $count);

    return $channel;
}

function schedulingParityQueueInstants(SocialAccount $channel): array
{
    return Post::query()->queuedOn($channel->id, now())->orderBy('scheduled_at')->get()->map->scheduled_at->map->toIso8601String()->all();
}

function schedulingParityStored(SocialAccount $channel): array
{
    $channel = $channel->fresh();

    return [$channel->timezone, $channel->posting_goal, $channel->posting_schedule?->toArray()];
}

test('a busy channel lock fails a posting schedule change on the api and the mcp tools and stores nothing', function () {
    $source = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
        'posting_schedule' => PostingSchedule::empty()->withTime(1, '08:00'),
    ]);
    $payload = [
        'timezone' => 'America/Sao_Paulo',
        'posting_goal' => 1,
        'posting_schedule' => PostingSchedule::empty()->withTime(2, '11:00')->toArray(),
    ];
    $before = schedulingParityStored($this->account);
    $lock = Cache::lock("queue:{$this->account->id}", 30);
    expect($lock->get())->toBeTrue();
    $this->travelBack();

    try {
        $this->withHeaders(parityApi($this->token))->putJson(route('api.channels.posting-schedule.update', $this->account), $payload)
            ->assertConflict()
            ->assertJson(['message' => __('posts.errors.queue_busy')]);
        auth()->forgetGuards();
        TryPostServer::actingAs($this->user)->tool(UpdatePostingScheduleTool::class, ['account_id' => $this->account->id, ...$payload])
            ->assertHasErrors([__('posts.errors.queue_busy')]);
        TryPostServer::actingAs($this->user)->tool(CopyPostingScheduleTool::class, ['account_id' => $this->account->id, 'from' => $source->id])
            ->assertHasErrors([__('posts.errors.queue_busy')]);
    } finally {
        $lock->release();
    }

    expect(schedulingParityStored($this->account))->toEqual($before);
});

test('updating the posting schedule stores the same schedule and zone on the web, the api and the mcp tool', function () {
    $payload = [
        'timezone' => 'America/Sao_Paulo',
        'posting_goal' => 2,
        'posting_schedule' => PostingSchedule::empty()->withTime(2, '11:00')->withTime(4, '17:30')->toArray(),
    ];
    $web = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn]);
    $api = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn]);
    $mcp = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn]);

    $this->actingAs($this->user)->putJson(route('app.channels.posting-schedule.update', $web), $payload)->assertOk();
    auth()->forgetGuards();
    $response = $this->withHeaders(parityApi($this->token))->putJson(route('api.channels.posting-schedule.update', $api), $payload)->assertOk();
    auth()->forgetGuards();
    TryPostServer::actingAs($this->user)->tool(UpdatePostingScheduleTool::class, ['account_id' => $mcp->id, ...$payload])->assertOk();

    expect(schedulingParityStored($api))->toEqual(schedulingParityStored($web))
        ->and(schedulingParityStored($mcp))->toEqual(schedulingParityStored($web))
        ->and(schedulingParityStored($web)[0])->toBe('America/Sao_Paulo')
        ->and($response->json('timezone'))->toBe('America/Sao_Paulo')
        ->and($response->json('id'))->toBe($api->id);
});

test('an invalid posting schedule is refused with the web messages on every surface', function () {
    $payload = ['timezone' => 'Nope/Zone', 'posting_goal' => 99, 'posting_schedule' => [['day' => 1]]];

    $web = $this->actingAs($this->user)->putJson(route('app.channels.posting-schedule.update', $this->account), $payload)->assertUnprocessable()->json('errors');
    auth()->forgetGuards();
    $api = $this->withHeaders(parityApi($this->token))->putJson(route('api.channels.posting-schedule.update', $this->account), $payload)->assertUnprocessable()->json('errors');
    auth()->forgetGuards();

    expect($api)->toEqual($web);
    $mcp = TryPostServer::actingAs($this->user)->tool(UpdatePostingScheduleTool::class, ['account_id' => $this->account->id, ...$payload]);
    $mcp->assertHasErrors([data_get($web, 'timezone.0'), data_get($web, 'posting_goal.0')]);
});

test('a time zone change through the api and the mcp tool reflows the queue to the same instants as the web', function () {
    $payload = ['timezone' => 'America/Sao_Paulo', 'posting_goal' => null, 'posting_schedule' => $this->account->posting_schedule->toArray()];
    $web = schedulingParityChannelWithQueue($this->workspace, $this->user->id);
    $api = schedulingParityChannelWithQueue($this->workspace, $this->user->id);
    $mcp = schedulingParityChannelWithQueue($this->workspace, $this->user->id);
    $before = schedulingParityQueueInstants($web);

    $this->actingAs($this->user)->putJson(route('app.channels.posting-schedule.update', $web), $payload)->assertOk();
    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->putJson(route('api.channels.posting-schedule.update', $api), $payload)->assertOk();
    auth()->forgetGuards();
    TryPostServer::actingAs($this->user)->tool(UpdatePostingScheduleTool::class, ['account_id' => $mcp->id, ...$payload])->assertOk();

    $expected = schedulingParityQueueInstants($web);

    expect($expected)->not->toBe($before)
        ->and(schedulingParityQueueInstants($api))->toBe($expected)
        ->and(schedulingParityQueueInstants($mcp))->toBe($expected);
});

test('a schedule change through the api and the mcp tool re-places only the posts whose slot vanished, like the web', function () {
    $payload = ['timezone' => 'UTC', 'posting_goal' => null, 'posting_schedule' => collect(range(0, 6))
        ->map(fn (int $day) => ['day' => $day, 'enabled' => true, 'times' => ['09:00', '18:00']])
        ->all()];
    $web = schedulingParityChannelWithQueue($this->workspace, $this->user->id);
    $api = schedulingParityChannelWithQueue($this->workspace, $this->user->id);
    $mcp = schedulingParityChannelWithQueue($this->workspace, $this->user->id);
    $before = schedulingParityQueueInstants($web);

    $this->actingAs($this->user)->putJson(route('app.channels.posting-schedule.update', $web), $payload)->assertOk();
    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->putJson(route('api.channels.posting-schedule.update', $api), $payload)->assertOk();
    auth()->forgetGuards();
    TryPostServer::actingAs($this->user)->tool(UpdatePostingScheduleTool::class, ['account_id' => $mcp->id, ...$payload])->assertOk();

    $expected = schedulingParityQueueInstants($web);
    $kept = collect($before)->filter(fn (string $at) => Carbon::parse($at)->format('H:i') === '09:00');

    expect($expected)->not->toBe($before)
        ->and($kept)->not->toBeEmpty()
        ->and(collect($expected)->intersect($kept)->values()->all())->toBe($kept->values()->all())
        ->and(schedulingParityQueueInstants($api))->toBe($expected)
        ->and(schedulingParityQueueInstants($mcp))->toBe($expected);
});

test('generating a posting schedule stores the same schedule on the web, the api and the mcp tool', function () {
    $this->app->instance(RandomMinute::class, new class extends RandomMinute
    {
        public function __invoke(): int
        {
            return 7;
        }
    });
    $bare = fn () => SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn, 'posting_schedule' => null, 'posting_goal' => null]);
    [$web, $api, $mcp] = [$bare(), $bare(), $bare()];

    $this->actingAs($this->user)->postJson(route('app.channels.posting-schedule.generate', $web), ['mode' => 'goal', 'goal' => 4])->assertOk();
    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->postJson(route('api.channels.posting-schedule.generate', $api), ['mode' => 'goal', 'goal' => 4])->assertOk();
    auth()->forgetGuards();
    TryPostServer::actingAs($this->user)->tool(GeneratePostingScheduleTool::class, ['account_id' => $mcp->id, 'mode' => 'goal', 'goal' => 4])->assertOk();

    expect(schedulingParityStored($api)[2])->toEqual(schedulingParityStored($web)[2])
        ->and(schedulingParityStored($mcp)[2])->toEqual(schedulingParityStored($web)[2])
        ->and(schedulingParityStored($web)[1])->toBe(4)
        ->and(schedulingParityStored($web)[2])->not->toBeNull();
});

test('an invalid generate request is refused with the web messages on every surface and stores nothing', function (array $payload) {
    $before = $this->account->fresh()->posting_schedule;

    $web = $this->actingAs($this->user)->postJson(route('app.channels.posting-schedule.generate', $this->account), $payload)->assertUnprocessable()->json('errors');
    auth()->forgetGuards();
    $api = $this->withHeaders(parityApi($this->token))->postJson(route('api.channels.posting-schedule.generate', $this->account), $payload)->assertUnprocessable()->json('errors');
    auth()->forgetGuards();

    expect($api)->toEqual($web);
    TryPostServer::actingAs($this->user)
        ->tool(GeneratePostingScheduleTool::class, ['account_id' => $this->account->id, ...$payload])
        ->assertHasErrors(collect($web)->flatten()->all());

    expect($this->account->fresh()->posting_schedule)->toEqual($before);
})->with([
    'unknown mode' => [['mode' => 'weekly']],
    'goal above the cap' => [['mode' => 'goal', 'goal' => 999]],
    'goal below one' => [['mode' => 'goal', 'goal' => 0]],
]);

test('copying a posting schedule stores the same schedule on the web, the api and the mcp tool', function () {
    $bare = fn () => SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn, 'posting_schedule' => null]);
    [$web, $api, $mcp] = [$bare(), $bare(), $bare()];

    $this->actingAs($this->user)->postJson(route('app.channels.posting-schedule.copy', $web), ['from' => $this->account->id])->assertOk();
    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->postJson(route('api.channels.posting-schedule.copy', $api), ['from' => $this->account->id])->assertOk();
    auth()->forgetGuards();
    TryPostServer::actingAs($this->user)->tool(CopyPostingScheduleTool::class, ['account_id' => $mcp->id, 'from' => $this->account->id])->assertOk();

    expect(schedulingParityStored($api)[2])->toEqual($this->account->fresh()->posting_schedule->toArray())
        ->and(schedulingParityStored($web)[2])->toEqual(schedulingParityStored($api)[2])
        ->and(schedulingParityStored($mcp)[2])->toEqual(schedulingParityStored($api)[2]);
});

test('copying from a foreign channel or one without a schedule is refused on every surface', function () {
    $foreign = SocialAccount::factory()->create(['workspace_id' => Workspace::factory()->create()->id, 'platform' => Platform::LinkedIn]);
    $empty = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::X, 'posting_schedule' => null]);
    $target = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn, 'posting_schedule' => null]);

    $this->actingAs($this->user)->postJson(route('app.channels.posting-schedule.copy', $target), ['from' => $foreign->id])->assertNotFound();
    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->postJson(route('api.channels.posting-schedule.copy', $target), ['from' => $foreign->id])->assertNotFound();
    $this->withHeaders(parityApi($this->token))->postJson(route('api.channels.posting-schedule.copy', $target), ['from' => $empty->id])->assertUnprocessable()->assertJsonValidationErrors('from');
    $this->withHeaders(parityApi($this->token))->postJson(route('api.channels.posting-schedule.copy', $foreign), ['from' => $this->account->id])->assertNotFound();
    auth()->forgetGuards();
    TryPostServer::actingAs($this->user)->tool(CopyPostingScheduleTool::class, ['account_id' => $target->id, 'from' => $foreign->id])->assertHasErrors();
    TryPostServer::actingAs($this->user)->tool(CopyPostingScheduleTool::class, ['account_id' => $target->id, 'from' => $empty->id])->assertHasErrors();

    expect($target->fresh()->posting_schedule)->toBeNull();
});

test('a non-admin member is refused on the posting schedule routes and tools', function () {
    $member = workspaceMember($this->workspace, 'approval');
    $headers = parityApi(passportToken($member, $this->workspace));
    $before = schedulingParityStored($this->account);
    $payload = ['timezone' => 'America/Sao_Paulo', 'posting_goal' => 2, 'posting_schedule' => null];

    $this->withHeaders($headers)->putJson(route('api.channels.posting-schedule.update', $this->account), $payload)->assertForbidden();
    $this->withHeaders($headers)->postJson(route('api.channels.posting-schedule.generate', $this->account), ['mode' => 'goal'])->assertForbidden();
    $this->withHeaders($headers)->postJson(route('api.channels.posting-schedule.copy', $this->account), ['from' => $this->account->id])->assertForbidden();
    TryPostServer::actingAs($member)->tool(UpdatePostingScheduleTool::class, ['account_id' => $this->account->id, ...$payload])->assertHasErrors([__('This action is unauthorized.')]);
    TryPostServer::actingAs($member)->tool(GeneratePostingScheduleTool::class, ['account_id' => $this->account->id, 'mode' => 'goal'])->assertHasErrors([__('This action is unauthorized.')]);
    TryPostServer::actingAs($member)->tool(CopyPostingScheduleTool::class, ['account_id' => $this->account->id, 'from' => $this->account->id])->assertHasErrors([__('This action is unauthorized.')]);

    expect(schedulingParityStored($this->account))->toEqual($before);
});

test('the posting schedule routes and tools of a foreign channel are refused', function () {
    $foreign = SocialAccount::factory()->create(['workspace_id' => Workspace::factory()->create()->id, 'platform' => Platform::LinkedIn]);
    $payload = ['timezone' => 'UTC', 'posting_goal' => null, 'posting_schedule' => null];

    $this->withHeaders(parityApi($this->token))->putJson(route('api.channels.posting-schedule.update', $foreign), $payload)->assertNotFound();
    $this->withHeaders(parityApi($this->token))->postJson(route('api.channels.posting-schedule.generate', $foreign), ['mode' => 'goal'])->assertNotFound();
    TryPostServer::actingAs($this->user)->tool(UpdatePostingScheduleTool::class, ['account_id' => $foreign->id, ...$payload])->assertHasErrors();
    TryPostServer::actingAs($this->user)->tool(GeneratePostingScheduleTool::class, ['account_id' => $foreign->id, 'mode' => 'goal'])->assertHasErrors();
});
