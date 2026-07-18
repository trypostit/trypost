<?php

declare(strict_types=1);

use App\Enums\Notification\Type;
use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Events\NotificationCreated;
use App\Jobs\SendNotification;
use App\Mail\AccountDisconnected;
use App\Models\Notification;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->owner->id]);
});

// ---- needsProactiveTokenRefresh ----

test('needsProactiveTokenRefresh: rotating platforms refresh only when expired, extension platforms while still valid', function () {
    // Rotating (X): a still-valid token that is merely expiring soon is NOT refreshed.
    $xValid = SocialAccount::factory()->x()->create([
        'workspace_id' => $this->workspace->id,
        'token_expires_at' => now()->addMinutes(10),
    ]);
    expect($xValid->needsProactiveTokenRefresh())->toBeFalse();

    // Rotating (X): once actually expired, it is refreshed.
    $xExpired = SocialAccount::factory()->x()->create([
        'workspace_id' => $this->workspace->id,
        'token_expires_at' => now()->subMinute(),
    ]);
    expect($xExpired->needsProactiveTokenRefresh())->toBeTrue();

    // Extension-model (Instagram): a still-valid token that is expiring soon MUST
    // be refreshed while valid — its token can't be extended once expired.
    $igValid = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'token_expires_at' => now()->addMinutes(10),
    ]);
    expect($igValid->needsProactiveTokenRefresh())->toBeTrue();
});

// ---- markAsTokenExpired ----

test('markAsTokenExpired updates status and dispatches notification when transitioning from connected', function () {
    Queue::fake();

    $account = SocialAccount::factory()->x()->create([
        'workspace_id' => $this->workspace->id,
        'status' => Status::Connected,
        'username' => 'testuser',
    ]);

    $account->markAsTokenExpired('refresh_token rejected');

    expect($account->fresh()->status)->toBe(Status::TokenExpired);
    expect($account->fresh()->error_message)->toBe('refresh_token rejected');

    Queue::assertPushed(SendNotification::class, function ($job) {
        return $job->user->id === $this->owner->id
            && $job->type === Type::AccountDisconnected
            && str_contains($job->title, 'needs to be reconnected');
    });
});

test('markAsTokenExpired does not dispatch notification when already token expired', function () {
    Queue::fake();

    $account = SocialAccount::factory()->x()->create([
        'workspace_id' => $this->workspace->id,
        'status' => Status::TokenExpired,
        'disconnected_at' => now()->subDay(),
    ]);

    $account->markAsTokenExpired('another failure');

    Queue::assertNotPushed(SendNotification::class);
});

test('markAsTokenExpired does not dispatch notification when account is disconnected', function () {
    Queue::fake();

    $account = SocialAccount::factory()->x()->create([
        'workspace_id' => $this->workspace->id,
        'status' => Status::Disconnected,
        'disconnected_at' => now()->subDay(),
    ]);

    $account->markAsTokenExpired('refresh_token rejected after disconnect');

    Queue::assertNotPushed(SendNotification::class);
});

test('markAsTokenExpired respects notify=false flag', function () {
    Queue::fake();

    $account = SocialAccount::factory()->x()->create([
        'workspace_id' => $this->workspace->id,
        'status' => Status::Connected,
        'username' => 'testuser',
    ]);

    $account->markAsTokenExpired('refresh_token rejected', notify: false);

    expect($account->fresh()->status)->toBe(Status::TokenExpired);
    Queue::assertNotPushed(SendNotification::class);
});

test('markAsTokenExpired preserves existing disconnected_at value', function () {
    Queue::fake();

    $earlier = now()->subDays(3);
    $account = SocialAccount::factory()->x()->create([
        'workspace_id' => $this->workspace->id,
        'status' => Status::Connected,
        'disconnected_at' => $earlier,
    ]);

    $account->markAsTokenExpired('refresh_token rejected');

    expect($account->fresh()->disconnected_at->toIso8601String())
        ->toBe($earlier->toIso8601String());
});

test('markAsTokenExpired creates notification row with i18n placeholders substituted', function () {
    Event::fake([NotificationCreated::class]);
    Mail::fake();

    $account = SocialAccount::factory()->x()->create([
        'workspace_id' => $this->workspace->id,
        'status' => Status::Connected,
        'username' => 'testuser',
    ]);

    $account->markAsTokenExpired('refresh_token rejected');

    $notification = Notification::where('user_id', $this->owner->id)->first();

    expect($notification)->not->toBeNull();
    expect($notification->title)->toBe('X account needs to be reconnected');
    expect($notification->body)->toBe('@testuser session expired — please reconnect to keep posting');
    expect($notification->type)->toBe(Type::AccountDisconnected);
    expect($notification->data)->toBe(['social_account_id' => $account->id]);

    Event::assertDispatched(NotificationCreated::class);
    Mail::assertQueued(AccountDisconnected::class);
});

// ---- markAsDisconnected ----

test('markAsDisconnected updates status and dispatches notification when transitioning from connected', function () {
    Queue::fake();

    $account = SocialAccount::factory()->x()->create([
        'workspace_id' => $this->workspace->id,
        'status' => Status::Connected,
        'username' => 'testuser',
    ]);

    $account->markAsDisconnected('manual disconnect');

    expect($account->fresh()->status)->toBe(Status::Disconnected);
    expect($account->fresh()->error_message)->toBe('manual disconnect');
    expect($account->fresh()->disconnected_at)->not->toBeNull();

    Queue::assertPushed(SendNotification::class, function ($job) {
        return $job->user->id === $this->owner->id
            && $job->type === Type::AccountDisconnected;
    });
});

test('markAsDisconnected does not dispatch notification when already disconnected', function () {
    Queue::fake();

    $account = SocialAccount::factory()->x()->create([
        'workspace_id' => $this->workspace->id,
        'status' => Status::Disconnected,
        'disconnected_at' => now()->subDay(),
    ]);

    $account->markAsDisconnected('another disconnect');

    Queue::assertNotPushed(SendNotification::class);
});

test('markAsDisconnected creates notification row with i18n placeholders substituted', function () {
    Event::fake([NotificationCreated::class]);
    Mail::fake();

    $account = SocialAccount::factory()->x()->create([
        'workspace_id' => $this->workspace->id,
        'status' => Status::Connected,
        'username' => 'testuser',
    ]);

    $account->markAsDisconnected('manual disconnect');

    $notification = Notification::where('user_id', $this->owner->id)->first();

    expect($notification)->not->toBeNull();
    expect($notification->title)->toBe('X account disconnected');
    expect($notification->body)->toBe('@testuser needs to be reconnected');
    expect($notification->type)->toBe(Type::AccountDisconnected);
    expect($notification->data)->toBe(['social_account_id' => $account->id]);

    Event::assertDispatched(NotificationCreated::class);
    Mail::assertQueued(AccountDisconnected::class);
});
