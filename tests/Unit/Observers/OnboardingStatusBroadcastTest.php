<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Status;
use App\Enums\UserWorkspace\Role;
use App\Events\OnboardingStatusUpdated;
use App\Models\AccessToken;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Observers\AccessTokenObserver;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake([OnboardingStatusUpdated::class]);
});

test('creating a social account broadcasts onboarding status for its workspace', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);

    SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
    ]);

    Event::assertDispatched(
        OnboardingStatusUpdated::class,
        fn (OnboardingStatusUpdated $event): bool => $event->workspaceId === $workspace->id,
    );
});

test('deleting a social account broadcasts onboarding status for its workspace', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);

    $socialAccount = SocialAccount::withoutEvents(fn () => SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
    ]));

    Event::fake([OnboardingStatusUpdated::class]);

    $socialAccount->delete();

    Event::assertDispatched(
        OnboardingStatusUpdated::class,
        fn (OnboardingStatusUpdated $event): bool => $event->workspaceId === $workspace->id,
    );
});

test('disconnecting the last connected social account broadcasts onboarding status', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $socialAccount = SocialAccount::withoutEvents(fn () => SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => Status::Connected,
    ]));

    Event::fake([OnboardingStatusUpdated::class]);

    $socialAccount->update(['status' => Status::Disconnected]);

    Event::assertDispatched(
        OnboardingStatusUpdated::class,
        fn (OnboardingStatusUpdated $event): bool => $event->workspaceId === $workspace->id,
    );
});

test('reconnecting the first usable social account broadcasts onboarding status', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $socialAccount = SocialAccount::withoutEvents(fn () => SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => Status::Disconnected,
    ]));

    Event::fake([OnboardingStatusUpdated::class]);

    $socialAccount->update(['status' => Status::Connected]);

    Event::assertDispatched(
        OnboardingStatusUpdated::class,
        fn (OnboardingStatusUpdated $event): bool => $event->workspaceId === $workspace->id,
    );
});

test('changing one of multiple connected social accounts does not broadcast onboarding status', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $socialAccount = SocialAccount::withoutEvents(fn () => SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => Status::Connected,
    ]));
    SocialAccount::withoutEvents(fn () => SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => Status::Connected,
    ]));

    Event::fake([OnboardingStatusUpdated::class]);

    $socialAccount->update(['status' => Status::TokenExpired]);

    Event::assertNotDispatched(OnboardingStatusUpdated::class);
});

test('creating a post broadcasts onboarding status for its workspace', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);

    Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    Event::assertDispatched(
        OnboardingStatusUpdated::class,
        fn (OnboardingStatusUpdated $event): bool => $event->workspaceId === $workspace->id,
    );
});

test('deleting a post broadcasts onboarding status for its workspace', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);

    $post = Post::withoutEvents(fn () => Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]));

    Event::fake([OnboardingStatusUpdated::class]);

    $post->delete();

    Event::assertDispatched(
        OnboardingStatusUpdated::class,
        fn (OnboardingStatusUpdated $event): bool => $event->workspaceId === $workspace->id,
    );
});

test('creating an oauth mcp token broadcasts onboarding status for every account workspace', function () {
    $user = User::factory()->create(['current_workspace_id' => null]);
    $workspaceA = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspaceB = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $user->update(['current_workspace_id' => $workspaceA->id]);

    mcpAccessToken($user, mcpOauthClient());

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

test('creating a personal access token does not broadcast onboarding status', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $user->createToken('API Key');

    Event::assertNotDispatched(OnboardingStatusUpdated::class);
});

test('unscoped and viewer oauth tokens do not broadcast onboarding status', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $owner->account_id,
        'user_id' => $owner->id,
    ]);
    $owner->update(['current_workspace_id' => $workspace->id]);
    $viewer = User::factory()->create(['account_id' => $owner->account_id]);
    $workspace->members()->attach($viewer->id, ['role' => Role::Viewer->value]);
    $viewer->update(['current_workspace_id' => $workspace->id]);

    mcpAccessToken($owner, mcpOauthClient('Unscoped Agent'), scopes: []);
    mcpAccessToken($viewer, mcpOauthClient('Viewer Agent'));

    Event::assertNotDispatched(OnboardingStatusUpdated::class);
});

test('creating an oauth mcp token does not broadcast when onboarding is over', function (string $column) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $user->update(['current_workspace_id' => $workspace->id]);
    $user->account->forceFill([$column => now()])->save();

    mcpAccessToken($user, mcpOauthClient());

    Event::assertNotDispatched(OnboardingStatusUpdated::class);
})->with(['onboarding_completed_at', 'onboarding_dismissed_at']);

test('revoking an oauth mcp token broadcasts onboarding status', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $token = AccessToken::withoutEvents(fn () => mcpAccessToken($user, mcpOauthClient()));

    Event::fake([OnboardingStatusUpdated::class]);

    $token->update(['revoked' => true]);

    Event::assertDispatched(
        OnboardingStatusUpdated::class,
        fn (OnboardingStatusUpdated $event): bool => $event->workspaceId === $workspace->id,
    );
});

test('oauth tokens bound to a workspace still broadcast onboarding status', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $token = AccessToken::withoutEvents(fn () => mcpAccessToken($user, mcpOauthClient()));
    $token->forceFill(['workspace_id' => $workspace->id])->saveQuietly();

    Event::fake([OnboardingStatusUpdated::class]);

    (new AccessTokenObserver)->created($token->fresh());

    Event::assertDispatched(
        OnboardingStatusUpdated::class,
        fn (OnboardingStatusUpdated $event): bool => $event->workspaceId === $workspace->id,
    );
});

test('tokens without an oauth client do not broadcast', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $token = new AccessToken([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'revoked' => false,
    ]);

    (new AccessTokenObserver)->created($token);

    Event::assertNotDispatched(OnboardingStatusUpdated::class);
});

test('additional posts after the first do not broadcast onboarding status', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);

    Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    Event::fake([OnboardingStatusUpdated::class]);

    Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    Event::assertNotDispatched(OnboardingStatusUpdated::class);
});

test('does not broadcast when onboarding is already completed', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $user->account->forceFill(['onboarding_completed_at' => now()])->save();

    Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    Event::assertNotDispatched(OnboardingStatusUpdated::class);
});

test('does not broadcast when onboarding is dismissed', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $user->account->forceFill(['onboarding_dismissed_at' => now()])->save();

    SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
    ]);

    Event::assertNotDispatched(OnboardingStatusUpdated::class);
});

test('creating another post on the same account does not rebroadcast the step', function () {
    $user = User::factory()->create();
    $workspaceA = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspaceB = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);

    Post::withoutEvents(fn () => Post::factory()->create([
        'workspace_id' => $workspaceA->id,
        'user_id' => $user->id,
    ]));

    Event::fake([OnboardingStatusUpdated::class]);

    Post::factory()->create([
        'workspace_id' => $workspaceB->id,
        'user_id' => $user->id,
    ]);

    Event::assertNotDispatched(OnboardingStatusUpdated::class);
});

test('deleting a post does not broadcast while the account still has posts', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);

    $posts = Post::withoutEvents(fn () => Post::factory()->count(2)->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]));

    Event::fake([OnboardingStatusUpdated::class]);

    $posts->first()->delete();

    Event::assertNotDispatched(OnboardingStatusUpdated::class);
});

test('deleting a post does not broadcast when onboarding is completed', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $user->account->forceFill(['onboarding_completed_at' => now()])->save();

    $post = Post::withoutEvents(fn () => Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]));

    Event::fake([OnboardingStatusUpdated::class]);

    $post->delete();

    Event::assertNotDispatched(OnboardingStatusUpdated::class);
});

test('connecting a second social account on the account does not rebroadcast the step', function () {
    $user = User::factory()->create();
    $workspaceA = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspaceB = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);

    SocialAccount::withoutEvents(fn () => SocialAccount::factory()->create([
        'workspace_id' => $workspaceA->id,
    ]));

    Event::fake([OnboardingStatusUpdated::class]);

    SocialAccount::factory()->create([
        'workspace_id' => $workspaceB->id,
    ]);

    Event::assertNotDispatched(OnboardingStatusUpdated::class);
});

test('deleting a social account does not broadcast while the account still has connections', function () {
    $user = User::factory()->create();
    $workspaceA = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspaceB = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);

    $socialAccounts = SocialAccount::withoutEvents(fn () => collect([
        SocialAccount::factory()->create(['workspace_id' => $workspaceA->id]),
        SocialAccount::factory()->create(['workspace_id' => $workspaceB->id]),
    ]));

    Event::fake([OnboardingStatusUpdated::class]);

    $socialAccounts->first()->delete();

    Event::assertNotDispatched(OnboardingStatusUpdated::class);
});
