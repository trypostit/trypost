<?php

declare(strict_types=1);

use App\Actions\SocialAccount\ApplyChannelDefaults;
use App\Enums\Post\ScheduleMode;
use App\Enums\SocialAccount\Platform;
use App\Events\TelegramChannelConnected;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostingSchedule;
use Illuminate\Support\Facades\Event;

test('the posting schedule casts to and from JSON', function () {
    $account = SocialAccount::factory()->linkedin()->create([
        'posting_schedule' => PostingSchedule::empty()->withTime(1, '09:30'),
        'posting_goal' => 5,
        'timezone' => 'Europe/Warsaw',
    ]);

    $fresh = $account->fresh();

    expect($fresh->posting_schedule)->toBeInstanceOf(PostingSchedule::class)
        ->and($fresh->posting_schedule->toArray())->toEqual(PostingSchedule::empty()->withTime(1, '09:30')->toArray())
        ->and($fresh->posting_goal)->toBe(5)
        ->and($fresh->timezone)->toBe('Europe/Warsaw');
});

test('a channel without a schedule reads null', function () {
    expect(SocialAccount::factory()->linkedin()->create()->fresh()->posting_schedule)->toBeNull();
});

function channelDefaultsWorkspace(string $ownerTimezone = 'Europe/Warsaw'): array
{
    $owner = User::factory()->create(['timezone' => $ownerTimezone]);
    $workspace = Workspace::factory()->create(['account_id' => $owner->account_id, 'user_id' => $owner->id]);
    $workspace->members()->attach($owner->id, membershipPivot('admin'));
    $owner->update(['current_workspace_id' => $workspace->id]);

    return [$owner->fresh(), $workspace];
}

function connectLinkedIn(Workspace $workspace, string $id = 'li-1', ?SocialAccount $reconnect = null): SocialAccount
{
    return SocialAccount::connectIdentity($workspace, Platform::LinkedIn, $id, [
        'username' => 'ana', 'display_name' => 'Ana', 'access_token' => 't', 'status' => 'connected',
    ], $reconnect);
}

test('a new channel takes the connecting user time zone, goal 3 and a generated schedule', function () {
    [$owner, $workspace] = channelDefaultsWorkspace();
    $connector = User::factory()->create(['timezone' => 'America/Sao_Paulo']);
    $this->actingAs($connector);

    $account = connectLinkedIn($workspace)->fresh();

    expect($account->timezone)->toBe('America/Sao_Paulo')
        ->and($account->posting_goal)->toBe(3)
        ->and($account->posting_schedule->slotCount())->toBe(3);
});

test('without an authenticated user the workspace owner time zone is used', function () {
    [, $workspace] = channelDefaultsWorkspace('Asia/Tokyo');

    expect(connectLinkedIn($workspace)->fresh()->timezone)->toBe('Asia/Tokyo');
});

test('reconnecting keeps a customized schedule, goal and time zone', function () {
    [$owner, $workspace] = channelDefaultsWorkspace();
    $this->actingAs($owner);
    $account = connectLinkedIn($workspace);
    $custom = PostingSchedule::empty()->withTime(4, '07:05');
    $account->update(['timezone' => 'Pacific/Auckland', 'posting_goal' => 9, 'posting_schedule' => $custom]);

    connectLinkedIn($workspace, 'li-1', $account);
    connectLinkedIn($workspace, 'li-1');

    $fresh = $account->fresh();
    expect($fresh->timezone)->toBe('Pacific/Auckland')
        ->and($fresh->posting_goal)->toBe(9)
        ->and($fresh->posting_schedule->toArray())->toEqual($custom->toArray());
});

test('the telegram connected broadcast carries the account id and created flag', function () {
    $event = new TelegramChannelConnected('ws-1', 'nonce-1', 'acc-1', true);

    expect($event->broadcastWith())->toBe(['nonce' => 'nonce-1', 'account_id' => 'acc-1', 'created' => true]);
});

test('existing channels without a schedule get the new channel defaults from their workspace owner, quietly', function () {
    [$owner, $workspace] = channelDefaultsWorkspace('America/Sao_Paulo');
    $bare = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $custom = PostingSchedule::empty()->withTime(2, '10:15');
    $configured = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id, 'timezone' => 'Asia/Tokyo', 'posting_goal' => 7, 'posting_schedule' => $custom]);
    [, $ownerless] = channelDefaultsWorkspace('UTC');
    $utc = SocialAccount::factory()->linkedin()->create(['workspace_id' => $ownerless->id]);
    $scheduledAt = now()->addDays(2)->setTime(13, 37)->startOfSecond();
    $post = Post::factory()->scheduled()->create(['workspace_id' => $workspace->id, 'scheduled_at' => $scheduledAt, 'schedule_mode' => ScheduleMode::Custom]);
    PostPlatform::factory()->create(['post_id' => $post->id, 'social_account_id' => $bare->id]);
    Event::fake();

    expect(ApplyChannelDefaults::pending())->toBe(2)
        ->and(ApplyChannelDefaults::backfill())->toBe(2)
        ->and(ApplyChannelDefaults::backfill())->toBe(0);

    $bare = $bare->fresh();

    expect($bare->timezone)->toBe('America/Sao_Paulo')
        ->and($bare->posting_goal)->toBe(3)
        ->and($bare->posting_schedule->slotCount())->toBe(3)
        ->and($utc->fresh()->timezone)->toBe('UTC')
        ->and($utc->fresh()->posting_goal)->toBe(3)
        ->and($configured->fresh()->timezone)->toBe('Asia/Tokyo')
        ->and($configured->fresh()->posting_goal)->toBe(7)
        ->and($configured->fresh()->posting_schedule->toArray())->toEqual($custom->toArray())
        ->and($post->fresh()->scheduled_at->equalTo($scheduledAt))->toBeTrue();
    expect(collect(Event::dispatchedEvents())->keys()->reject(fn (string $event): bool => str_starts_with($event, 'eloquent.retrieved') || str_starts_with($event, 'eloquent.booting') || str_starts_with($event, 'eloquent.booted'))->values()->all())->toBe([]);
});
