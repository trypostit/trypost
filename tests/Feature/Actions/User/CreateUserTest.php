<?php

declare(strict_types=1);

use App\Actions\User\CreateUser;
use App\Enums\PostHog\UserEvent;
use App\Jobs\PostHog\SendEvent;
use App\Jobs\PostHog\SyncUser;
use App\Models\Account;
use App\Models\Workspace;
use Illuminate\Support\Facades\Bus;

test('CreateUser creates the owner a default workspace and sets it as current', function () {
    $user = CreateUser::execute([
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'secret123',
    ]);

    expect($user->name)->toBe('Jane Doe');
    expect($user->email)->toBe('jane@example.com');
    expect($user->account_id)->not->toBeNull();
    expect(Account::find($user->account_id))->not->toBeNull();

    $workspace = $user->workspaces()->first();
    expect($user->workspaces()->count())->toBe(1);
    expect($workspace->name)->toBe("Jane Doe's Workspace");
    expect($workspace->account_id)->toBe($user->account_id);
    expect($user->fresh()->current_workspace_id)->toBe($workspace->id);
});

test('CreateUser sets account owner_id to the new user', function () {
    $user = CreateUser::execute([
        'name' => 'Jane Doe',
        'email' => 'jane2@example.com',
        'password' => 'secret123',
    ]);

    expect($user->account->owner_id)->toBe($user->id);
});

test('CreateUser invite-style still creates user without workspace (workspace assignment happens via invite acceptance)', function () {
    $user = CreateUser::execute([
        'name' => 'Invited',
        'email' => 'invited@example.com',
        'password' => 'secret123',
        'is_invite' => true,
    ]);

    expect($user->email_verified_at)->not->toBeNull();
    expect(Workspace::count())->toBe(0);
});

test('CreateUser dispatches SyncUser with the new user id when PostHog is enabled', function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test_key']);
    Bus::fake([SyncUser::class]);

    $user = CreateUser::execute([
        'name' => 'Jane Doe',
        'email' => 'jane.posthog@example.com',
        'password' => 'secret123',
    ]);

    Bus::assertDispatched(
        SyncUser::class,
        fn ($job) => $job->userId === (string) $user->id,
    );
});

test('CreateUser does not dispatch SyncUser when PostHog is disabled', function () {
    config(['services.posthog.enabled' => false]);
    Bus::fake([SyncUser::class]);

    CreateUser::execute([
        'name' => 'Jane Doe',
        'email' => 'jane.posthog.disabled@example.com',
        'password' => 'secret123',
    ]);

    Bus::assertNotDispatched(SyncUser::class);
});

test('CreateUser does not dispatch SyncUser when PostHog is disabled in production', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['services.posthog.enabled' => false, 'services.posthog.api_key' => null]);
    Bus::fake([SyncUser::class]);

    CreateUser::execute([
        'name' => 'Jane Doe',
        'email' => 'jane.posthog.disabled.production@example.com',
        'password' => 'secret123',
    ]);

    Bus::assertNotDispatched(SyncUser::class);
});

test('CreateUser dispatches SyncUser in the local environment even when PostHog is disabled', function () {
    app()->detectEnvironment(fn () => 'local');
    config(['services.posthog.enabled' => false, 'services.posthog.api_key' => null]);
    Bus::fake([SyncUser::class]);

    $user = CreateUser::execute([
        'name' => 'Jane Doe',
        'email' => 'jane.posthog.local@example.com',
        'password' => 'secret123',
    ]);

    Bus::assertDispatched(SyncUser::class, fn (SyncUser $job) => $job->userId === (string) $user->id);
});

test('CreateUser captures user.signed_up with the auth provider when PostHog is enabled', function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test_key']);
    Bus::fake([SendEvent::class]);

    $user = CreateUser::execute([
        'name' => 'Jane Doe',
        'email' => 'jane.signup@example.com',
        'password' => 'secret123',
        'google_id' => 'google-123',
    ]);

    Bus::assertDispatched(SendEvent::class, fn (SendEvent $event): bool => $event->method === 'capture'
        && data_get($event->payload, 'distinctId') === (string) $user->id
        && data_get($event->payload, 'event') === UserEvent::SignedUp->value
        && data_get($event->payload, 'properties.auth_provider') === 'google');
});

test('CreateUser defaults the auth provider to email when no OAuth id is present', function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test_key']);
    Bus::fake([SendEvent::class]);

    CreateUser::execute([
        'name' => 'Jane Doe',
        'email' => 'jane.signup.email@example.com',
        'password' => 'secret123',
    ]);

    Bus::assertDispatched(
        SendEvent::class,
        fn (SendEvent $event): bool => data_get($event->payload, 'properties.auth_provider') === 'email',
    );
});

test('CreateUser does not capture user.signed_up for invite registrations', function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test_key']);
    Bus::fake([SendEvent::class]);

    CreateUser::execute([
        'name' => 'Invited',
        'email' => 'invited.signup@example.com',
        'password' => 'secret123',
        'is_invite' => true,
    ]);

    // SyncUser still fires (it dispatches a SendEvent of its own, method
    // 'identify', for every registration) — only the 'user.signed_up'
    // capture must be skipped for invites.
    Bus::assertNotDispatched(
        SendEvent::class,
        fn (SendEvent $event): bool => $event->method === 'capture'
            && data_get($event->payload, 'event') === UserEvent::SignedUp->value,
    );
});

test('CreateUser does not capture user.signed_up when PostHog is disabled', function () {
    config(['services.posthog.enabled' => false]);
    Bus::fake([SendEvent::class]);

    CreateUser::execute([
        'name' => 'Jane Doe',
        'email' => 'jane.signup.disabled@example.com',
        'password' => 'secret123',
    ]);

    Bus::assertNotDispatched(SendEvent::class);
});

test('CreateUser does not capture user.signed_up when PostHog is disabled in production', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['services.posthog.enabled' => false, 'services.posthog.api_key' => null]);
    Bus::fake([SendEvent::class, SyncUser::class]);

    CreateUser::execute([
        'name' => 'Jane Doe',
        'email' => 'jane.signup.disabled.production@example.com',
        'password' => 'secret123',
    ]);

    Bus::assertNotDispatched(SendEvent::class);
    Bus::assertNotDispatched(SyncUser::class);
});
