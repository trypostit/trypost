<?php

declare(strict_types=1);

use App\Events\OnboardingStatusUpdated;
use App\Models\AccessToken;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

test('event broadcasts on the workspace channel', function () {
    $workspaceId = fake()->uuid();
    $event = new OnboardingStatusUpdated($workspaceId);
    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(1)
        ->and($channels[0])->toBeInstanceOf(PrivateChannel::class)
        ->and($channels[0]->name)->toBe("private-workspace.{$workspaceId}");
});

test('event broadcasts with the workspace id', function () {
    $workspaceId = fake()->uuid();
    $event = new OnboardingStatusUpdated($workspaceId);

    expect($event->broadcastWith())->toBe(['workspace_id' => $workspaceId]);
});

test('event broadcasts as a stable name', function () {
    $event = new OnboardingStatusUpdated(fake()->uuid());

    expect($event->broadcastAs())->toBe('onboarding.status.updated');
});

test('event waits for the database transaction to commit', function () {
    expect(new OnboardingStatusUpdated(fake()->uuid()))
        ->toBeInstanceOf(ShouldDispatchAfterCommit::class);
});

test('dispatchForWorkspace skips blank workspace ids', function () {
    Event::fake([OnboardingStatusUpdated::class]);

    OnboardingStatusUpdated::dispatchForWorkspace(null);
    OnboardingStatusUpdated::dispatchForWorkspace('');

    Event::assertNotDispatched(OnboardingStatusUpdated::class);
});

test('broadcastForAccount notifies every workspace even after completion', function () {
    Event::fake([OnboardingStatusUpdated::class]);

    $user = User::factory()->create();
    $workspaceA = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspaceB = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $user->account->forceFill(['onboarding_completed_at' => now()])->save();

    OnboardingStatusUpdated::broadcastForAccount($user->account->fresh());

    Event::assertDispatchedTimes(OnboardingStatusUpdated::class, 2);
    Event::assertDispatched(
        OnboardingStatusUpdated::class,
        fn (OnboardingStatusUpdated $event): bool => $event->workspaceId === $workspaceA->id,
    );
    Event::assertDispatched(
        OnboardingStatusUpdated::class,
        fn (OnboardingStatusUpdated $event): bool => $event->workspaceId === $workspaceB->id,
    );
});

test('dispatchForWorkspace fans out Echo to sibling workspaces while onboarding is open', function () {
    Event::fake([OnboardingStatusUpdated::class]);
    config(['trypost.self_hosted' => false]);

    $user = User::factory()->create();
    $workspaceA = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspaceB = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $user->update(['current_workspace_id' => $workspaceA->id]);
    subscribeAccount($user->account);

    OnboardingStatusUpdated::dispatchForWorkspace($workspaceA->id, $user->fresh());

    Event::assertDispatched(
        OnboardingStatusUpdated::class,
        fn (OnboardingStatusUpdated $event): bool => $event->workspaceId === $workspaceA->id,
    );
    Event::assertDispatched(
        OnboardingStatusUpdated::class,
        fn (OnboardingStatusUpdated $event): bool => $event->workspaceId === $workspaceB->id,
    );
});

test('dispatchForWorkspace does not stamp when the surrounding transaction rolls back', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');
    config(['trypost.self_hosted' => false]);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    AccessToken::withoutEvents(fn () => mcpAccessToken($user, mcpOauthClient()));
    SocialAccount::withoutEvents(fn () => SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
    ]));
    Post::withoutEvents(fn () => Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]));

    try {
        DB::transaction(function () use ($user, $workspace): void {
            OnboardingStatusUpdated::dispatchForWorkspace($workspace->id, $user->fresh());

            throw new RuntimeException('force rollback');
        });
    } catch (RuntimeException) {
        // Expected — sync/analytics must stay inside afterCommit.
    }

    expect($user->account->fresh()->onboarding_completed_at)->toBeNull();
});

test('dispatchForWorkspace stamps completion when actor current workspace differs', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');
    config(['trypost.self_hosted' => false]);

    $user = User::factory()->create();
    $readyWorkspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $otherWorkspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);

    // Persisted current workspace is elsewhere (API/MCP in-memory mutation case).
    $user->update(['current_workspace_id' => $otherWorkspace->id]);
    subscribeAccount($user->account);

    AccessToken::withoutEvents(fn () => mcpAccessToken($user, mcpOauthClient()));
    SocialAccount::withoutEvents(fn () => SocialAccount::factory()->create([
        'workspace_id' => $readyWorkspace->id,
    ]));
    Post::withoutEvents(fn () => Post::factory()->create([
        'workspace_id' => $readyWorkspace->id,
        'user_id' => $user->id,
    ]));

    // Align the request user to the ready workspace in memory only.
    $actor = $user->fresh();
    $actor->setRelation('currentWorkspace', $readyWorkspace);
    $actor->current_workspace_id = $readyWorkspace->id;

    OnboardingStatusUpdated::dispatchForWorkspace($readyWorkspace->id, $actor);

    expect($user->account->fresh()->onboarding_completed_at?->equalTo(now()))->toBeTrue();
});

test('dispatchForWorkspace stamps when nobody is currently on the workspace', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');
    config(['trypost.self_hosted' => false]);

    $owner = User::factory()->create(['current_workspace_id' => null]);
    $readyWorkspace = Workspace::factory()->create([
        'account_id' => $owner->account_id,
        'user_id' => $owner->id,
    ]);
    subscribeAccount($owner->account);

    $owner->account->forceFill(['onboarding_skipped_steps' => ['mcp']])->save();
    SocialAccount::withoutEvents(fn () => SocialAccount::factory()->create([
        'workspace_id' => $readyWorkspace->id,
    ]));
    Post::withoutEvents(fn () => Post::factory()->create([
        'workspace_id' => $readyWorkspace->id,
        'user_id' => $owner->id,
    ]));

    $actor = $owner->fresh();
    $actor->setRelation('currentWorkspace', $readyWorkspace);
    $actor->current_workspace_id = $readyWorkspace->id;

    OnboardingStatusUpdated::dispatchForWorkspace($readyWorkspace->id, $actor);

    expect($owner->account->fresh()->onboarding_completed_at?->equalTo(now()))->toBeTrue();
});

test('dispatchForWorkspace stamps completion via the account owner when the actor is null', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');
    config(['trypost.self_hosted' => false]);

    $owner = User::factory()->create(['current_workspace_id' => null]);
    $readyWorkspace = Workspace::factory()->create([
        'account_id' => $owner->account_id,
        'user_id' => $owner->id,
    ]);
    subscribeAccount($owner->account);

    $owner->account->forceFill(['onboarding_skipped_steps' => ['mcp']])->save();
    SocialAccount::withoutEvents(fn () => SocialAccount::factory()->create([
        'workspace_id' => $readyWorkspace->id,
    ]));
    Post::withoutEvents(fn () => Post::factory()->create([
        'workspace_id' => $readyWorkspace->id,
        'user_id' => $owner->id,
    ]));

    // Webhook-style dispatch (e.g. Telegram connect): no authenticated actor.
    OnboardingStatusUpdated::dispatchForWorkspace($readyWorkspace->id);

    expect($owner->account->fresh()->onboarding_completed_at?->equalTo(now()))->toBeTrue();
});

test('completion broadcasts exactly once per workspace', function () {
    Event::fake([OnboardingStatusUpdated::class]);
    config(['trypost.self_hosted' => false]);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    AccessToken::withoutEvents(fn () => mcpAccessToken($user, mcpOauthClient()));
    SocialAccount::withoutEvents(fn () => SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
    ]));
    Post::withoutEvents(fn () => Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]));

    OnboardingStatusUpdated::dispatchForWorkspace($workspace->id, $user->fresh());

    Event::assertDispatchedTimes(OnboardingStatusUpdated::class, 1);
});
