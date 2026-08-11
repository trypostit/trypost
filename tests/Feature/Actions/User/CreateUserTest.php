<?php

declare(strict_types=1);

use App\Actions\User\CreateUser;
use App\Jobs\Gtm\SendServerEvent;
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

test('CreateUser dispatches a sign_up GTM event with the auth provider when the backend container is enabled', function () {
    config(['services.gtm.backend.enabled' => true, 'services.gtm.backend.endpoint' => 'https://sgtm.test/collect']);
    Bus::fake([SendServerEvent::class]);

    $user = CreateUser::execute([
        'name' => 'Jane Doe',
        'email' => 'jane.gtm@example.com',
        'password' => 'secret123',
        'google_id' => 'google-123',
    ]);

    Bus::assertDispatched(
        SendServerEvent::class,
        fn (SendServerEvent $job) => $job->event === 'sign_up'
            && $job->distinctId === (string) $user->id
            && $job->properties['auth_provider'] === 'google',
    );
});

test('CreateUser defaults the GTM auth provider to email when no OAuth id is present', function () {
    config(['services.gtm.backend.enabled' => true, 'services.gtm.backend.endpoint' => 'https://sgtm.test/collect']);
    Bus::fake([SendServerEvent::class]);

    CreateUser::execute([
        'name' => 'Jane Doe',
        'email' => 'jane.gtm.email@example.com',
        'password' => 'secret123',
    ]);

    Bus::assertDispatched(
        SendServerEvent::class,
        fn (SendServerEvent $job) => $job->properties['auth_provider'] === 'email',
    );
});

test('CreateUser does not dispatch a GTM event for invite registrations', function () {
    config(['services.gtm.backend.enabled' => true, 'services.gtm.backend.endpoint' => 'https://sgtm.test/collect']);
    Bus::fake([SendServerEvent::class]);

    CreateUser::execute([
        'name' => 'Invited',
        'email' => 'invited.gtm@example.com',
        'password' => 'secret123',
        'is_invite' => true,
    ]);

    Bus::assertNotDispatched(SendServerEvent::class);
});

test('CreateUser does not dispatch a GTM event when the backend container is disabled', function () {
    config(['services.gtm.backend.enabled' => false]);
    Bus::fake([SendServerEvent::class]);

    CreateUser::execute([
        'name' => 'Jane Doe',
        'email' => 'jane.gtm.disabled@example.com',
        'password' => 'secret123',
    ]);

    Bus::assertNotDispatched(SendServerEvent::class);
});
