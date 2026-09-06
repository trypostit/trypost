<?php

declare(strict_types=1);

use App\Actions\Repurpose\ActivateRepurpose;
use App\Actions\Repurpose\ResumeRepurpose;
use App\Actions\Repurpose\UpdateRepurpose;
use App\Enums\PostPlatform\ContentType;
use App\Enums\Repurpose\PauseReason;
use App\Enums\Repurpose\PublishMode;
use App\Enums\Repurpose\Status;
use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status as AccountStatus;
use App\Models\Repurpose;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Repurpose\RepurposeTransition;
use Illuminate\Validation\ValidationException;

/**
 * File-local on purpose. Pest helpers are global functions that only exist once
 * their defining file has loaded, and this file is run on its own, so it cannot
 * borrow ActionsTest.php's. The names are unique for the same reason.
 *
 * For Action and service tests only: these do not set current_workspace_id,
 * which RepurposePolicy requires, so HTTP tests build their own fixtures.
 *
 * @return array{0: Workspace, 1: User, 2: SocialAccount}
 */
function healthWorkspace(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $account = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::Instagram]);

    return [$workspace, $user, $account];
}

/**
 * @return array<string, mixed>
 */
function healthDestination(Workspace $workspace): array
{
    $account = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::TikTok]);

    return [
        'social_account_id' => $account->id,
        'content_type' => ContentType::TikTokVideo->value,
        'meta' => ['privacy_level' => 'PUBLIC_TO_EVERYONE'],
    ];
}

test('deleting the source account leaves the repurpose and its history intact', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $account = SocialAccount::factory()->for($workspace)->create();

    $repurpose = Repurpose::factory()->for($workspace)->create([
        'source_social_account_id' => $account->id,
    ]);

    $account->delete();

    expect(Repurpose::query()->whereKey($repurpose->id)->exists())->toBeTrue()
        ->and($repurpose->fresh()->source_social_account_id)->toBeNull();
});

test('applyIfPossible returns null instead of throwing when the status moved on', function () {
    $repurpose = Repurpose::factory()->create(['status' => Status::Paused]);

    $result = RepurposeTransition::applyIfPossible(
        $repurpose,
        [Status::Active],
        fn (Repurpose $locked) => $locked->update(['status' => Status::Paused]),
    );

    expect($result)->toBeNull();
});

test('applyIfPossible applies the change and returns the fresh model', function () {
    $repurpose = Repurpose::factory()->create(['status' => Status::Active]);

    $result = RepurposeTransition::applyIfPossible(
        $repurpose,
        [Status::Active],
        fn (Repurpose $locked) => $locked->update(['status' => Status::Paused]),
    );

    expect($result?->status)->toBe(Status::Paused);
});

test('a deactivated destination does not block activation while another still works', function () {
    [$workspace, $user, $source] = healthWorkspace();

    $live = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::Mastodon]);
    $off = SocialAccount::factory()->for($workspace)->create([
        'platform' => Platform::Threads,
        'is_active' => false,
    ]);

    $repurpose = Repurpose::factory()->for($workspace)->create([
        'source_social_account_id' => $source->id,
        'status' => Status::Draft,
        'destinations' => [
            ['social_account_id' => $live->id, 'content_type' => ContentType::MastodonPost->value, 'meta' => []],
            ['social_account_id' => $off->id, 'content_type' => ContentType::ThreadsPost->value, 'meta' => []],
        ],
    ]);

    expect(ActivateRepurpose::execute($repurpose)->status)->toBe(Status::Active);
});

test('activation is refused when no destination is usable', function () {
    [$workspace, $user, $source] = healthWorkspace();

    $off = SocialAccount::factory()->for($workspace)->create([
        'platform' => Platform::Threads,
        'is_active' => false,
    ]);

    $repurpose = Repurpose::factory()->for($workspace)->create([
        'source_social_account_id' => $source->id,
        'status' => Status::Draft,
        'destinations' => [
            ['social_account_id' => $off->id, 'content_type' => ContentType::ThreadsPost->value, 'meta' => []],
        ],
    ]);

    expect(fn () => ActivateRepurpose::execute($repurpose))->toThrow(ValidationException::class);
});

test('activation is refused when the source account is disconnected', function () {
    [$workspace, $user, $source] = healthWorkspace();
    $source->update(['status' => AccountStatus::Disconnected]);

    $repurpose = Repurpose::factory()->for($workspace)->create([
        'source_social_account_id' => $source->id,
        'status' => Status::Draft,
        'destinations' => [healthDestination($workspace)],
    ]);

    expect(fn () => ActivateRepurpose::execute($repurpose))->toThrow(ValidationException::class);
});

test('activation is refused when the source account was removed', function () {
    [$workspace, $user, $source] = healthWorkspace();

    $repurpose = Repurpose::factory()->for($workspace)->create([
        'source_social_account_id' => $source->id,
        'status' => Status::Draft,
        'destinations' => [healthDestination($workspace)],
    ]);

    $source->delete();

    expect(fn () => ActivateRepurpose::execute($repurpose->fresh()))->toThrow(ValidationException::class);
});

test('editing an active repurpose is not blocked by a deactivated destination', function () {
    [$workspace, $user, $source] = healthWorkspace();

    $live = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::Mastodon]);
    $off = SocialAccount::factory()->for($workspace)->create([
        'platform' => Platform::Threads,
        'is_active' => false,
    ]);

    $repurpose = Repurpose::factory()->for($workspace)->create([
        'source_social_account_id' => $source->id,
        'status' => Status::Active,
        'destinations' => [
            ['social_account_id' => $live->id, 'content_type' => ContentType::MastodonPost->value, 'meta' => []],
            ['social_account_id' => $off->id, 'content_type' => ContentType::ThreadsPost->value, 'meta' => []],
        ],
    ]);

    $updated = UpdateRepurpose::execute($repurpose, ['publish_mode' => PublishMode::Draft->value]);

    expect($updated->publish_mode)->toBe(PublishMode::Draft);
});

test('resuming a user pause keeps the watermark', function () {
    [$workspace, $user, $source] = healthWorkspace();
    $watermark = now()->subDays(3);

    $repurpose = Repurpose::factory()->for($workspace)->create([
        'source_social_account_id' => $source->id,
        'status' => Status::Paused,
        'paused_reason' => null,
        'activated_at' => $watermark,
        'destinations' => [healthDestination($workspace)],
    ]);

    expect(ResumeRepurpose::execute($repurpose)->activated_at->timestamp)
        ->toBe($watermark->timestamp);
});

test('resuming a system pause starts from now and clears the reason', function () {
    [$workspace, $user, $source] = healthWorkspace();

    $repurpose = Repurpose::factory()->for($workspace)->create([
        'source_social_account_id' => $source->id,
        'status' => Status::Paused,
        'paused_reason' => PauseReason::SourceUnavailable,
        'activated_at' => now()->subDays(3),
        'destinations' => [healthDestination($workspace)],
    ]);

    $resumed = ResumeRepurpose::execute($repurpose);

    expect($resumed->activated_at->isToday())->toBeTrue()
        ->and($resumed->paused_reason)->toBeNull()
        ->and($resumed->next_poll_at)->toBeNull();
});

test('resuming is refused while the source is still unusable', function () {
    [$workspace, $user, $source] = healthWorkspace();
    $source->update(['is_active' => false]);

    $repurpose = Repurpose::factory()->for($workspace)->create([
        'source_social_account_id' => $source->id,
        'status' => Status::Paused,
        'paused_reason' => PauseReason::SourceUnavailable,
        'destinations' => [healthDestination($workspace)],
    ]);

    expect(fn () => ResumeRepurpose::execute($repurpose))->toThrow(ValidationException::class);
});

test('deleting the source account pauses the repurpose', function () {
    [$workspace, $user, $source] = healthWorkspace();

    $repurpose = Repurpose::factory()->for($workspace)->create([
        'source_social_account_id' => $source->id,
        'status' => Status::Active,
        'destinations' => [healthDestination($workspace)],
    ]);

    $source->delete();

    expect($repurpose->fresh()->status)->toBe(Status::Paused)
        ->and($repurpose->fresh()->paused_reason)->toBe(PauseReason::SourceRemoved);
});

test('a source going token expired pauses the repurpose', function () {
    [$workspace, $user, $source] = healthWorkspace();

    $repurpose = Repurpose::factory()->for($workspace)->create([
        'source_social_account_id' => $source->id,
        'status' => Status::Active,
        'destinations' => [healthDestination($workspace)],
    ]);

    $source->update(['status' => AccountStatus::TokenExpired]);

    expect($repurpose->fresh()->paused_reason)->toBe(PauseReason::SourceUnavailable);
});

test('deactivating the source pauses the repurpose', function () {
    [$workspace, $user, $source] = healthWorkspace();

    $repurpose = Repurpose::factory()->for($workspace)->create([
        'source_social_account_id' => $source->id,
        'status' => Status::Active,
        'destinations' => [healthDestination($workspace)],
    ]);

    $source->update(['is_active' => false]);

    expect($repurpose->fresh()->paused_reason)->toBe(PauseReason::SourceUnavailable);
});

test('a draft repurpose is left alone when its source dies', function () {
    [$workspace, $user, $source] = healthWorkspace();

    $repurpose = Repurpose::factory()->for($workspace)->create([
        'source_social_account_id' => $source->id,
        'status' => Status::Draft,
        'destinations' => [healthDestination($workspace)],
    ]);

    $source->update(['is_active' => false]);

    expect($repurpose->fresh()->status)->toBe(Status::Draft)
        ->and($repurpose->fresh()->paused_reason)->toBeNull();
});

test('a repurpose the user paused does not acquire a system reason', function () {
    [$workspace, $user, $source] = healthWorkspace();

    $repurpose = Repurpose::factory()->for($workspace)->create([
        'source_social_account_id' => $source->id,
        'status' => Status::Paused,
        'paused_reason' => null,
        'destinations' => [healthDestination($workspace)],
    ]);

    $source->update(['is_active' => false]);

    expect($repurpose->fresh()->paused_reason)->toBeNull();
});

test('an unrelated account update does not touch the repurpose', function () {
    [$workspace, $user, $source] = healthWorkspace();

    $repurpose = Repurpose::factory()->for($workspace)->create([
        'source_social_account_id' => $source->id,
        'status' => Status::Active,
        'destinations' => [healthDestination($workspace)],
    ]);

    $source->update(['last_used_at' => now()]);

    expect($repurpose->fresh()->status)->toBe(Status::Active);
});

test('deleting a destination account prunes it from the repurpose', function () {
    [$workspace, $user, $source] = healthWorkspace();

    $keep = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::Mastodon]);
    $drop = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::Threads]);

    $repurpose = Repurpose::factory()->for($workspace)->create([
        'source_social_account_id' => $source->id,
        'status' => Status::Active,
        'destinations' => [
            ['social_account_id' => $keep->id, 'content_type' => ContentType::MastodonPost->value, 'meta' => []],
            ['social_account_id' => $drop->id, 'content_type' => ContentType::ThreadsPost->value, 'meta' => []],
        ],
    ]);

    $drop->delete();

    $destinations = $repurpose->fresh()->destinations;

    expect($destinations)->toHaveCount(1)
        ->and(data_get($destinations, '0.social_account_id'))->toBe($keep->id)
        ->and($repurpose->fresh()->status)->toBe(Status::Active);
});

test('deleting the last destination pauses the repurpose', function () {
    [$workspace, $user, $source] = healthWorkspace();
    $only = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::Threads]);

    $repurpose = Repurpose::factory()->for($workspace)->create([
        'source_social_account_id' => $source->id,
        'status' => Status::Active,
        'destinations' => [
            ['social_account_id' => $only->id, 'content_type' => ContentType::ThreadsPost->value, 'meta' => []],
        ],
    ]);

    $only->delete();

    expect($repurpose->fresh()->destinations)->toBe([])
        ->and($repurpose->fresh()->paused_reason)->toBe(PauseReason::NoDestinations);
});

test('reconnecting a LinkedIn destination as a page realigns its content type', function () {
    [$workspace, $user, $source] = healthWorkspace();

    $linkedin = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::LinkedIn]);

    $repurpose = Repurpose::factory()->for($workspace)->create([
        'source_social_account_id' => $source->id,
        'status' => Status::Active,
        'destinations' => [
            ['social_account_id' => $linkedin->id, 'content_type' => ContentType::LinkedInPost->value, 'meta' => []],
        ],
    ]);

    $linkedin->update(['platform' => Platform::LinkedInPage]);

    expect(data_get($repurpose->fresh()->destinations, '0.content_type'))
        ->toBe(ContentType::LinkedInPagePost->value);
});

test('a deactivated destination is left in place', function () {
    [$workspace, $user, $source] = healthWorkspace();
    $off = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::Threads]);

    $repurpose = Repurpose::factory()->for($workspace)->create([
        'source_social_account_id' => $source->id,
        'status' => Status::Active,
        'destinations' => [
            ['social_account_id' => $off->id, 'content_type' => ContentType::ThreadsPost->value, 'meta' => []],
        ],
    ]);

    $off->update(['is_active' => false]);

    expect($repurpose->fresh()->destinations)->toHaveCount(1)
        ->and($repurpose->fresh()->status)->toBe(Status::Active);
});

test('reconnecting the source resumes the repurpose from now', function () {
    [$workspace, $user, $source] = healthWorkspace();
    $source->update(['status' => AccountStatus::TokenExpired]);

    $repurpose = Repurpose::factory()->for($workspace)->create([
        'source_social_account_id' => $source->id,
        'status' => Status::Paused,
        'paused_reason' => PauseReason::SourceUnavailable,
        'activated_at' => now()->subDays(2),
        'destinations' => [healthDestination($workspace)],
    ]);

    $source->update(['status' => AccountStatus::Connected]);

    $fresh = $repurpose->fresh();

    expect($fresh->status)->toBe(Status::Active)
        ->and($fresh->paused_reason)->toBeNull()
        ->and($fresh->activated_at->isToday())->toBeTrue();
});

test('a user pause is never auto-resumed', function () {
    [$workspace, $user, $source] = healthWorkspace();
    $source->update(['is_active' => false]);

    $repurpose = Repurpose::factory()->for($workspace)->create([
        'source_social_account_id' => $source->id,
        'status' => Status::Paused,
        'paused_reason' => null,
        'destinations' => [healthDestination($workspace)],
    ]);

    $source->update(['is_active' => true]);

    expect($repurpose->fresh()->status)->toBe(Status::Paused);
});

test('a repurpose with no destinations left is not auto-resumed', function () {
    [$workspace, $user, $source] = healthWorkspace();
    $source->update(['status' => AccountStatus::TokenExpired]);

    $repurpose = Repurpose::factory()->for($workspace)->create([
        'source_social_account_id' => $source->id,
        'status' => Status::Paused,
        'paused_reason' => PauseReason::NoDestinations,
        'destinations' => [],
    ]);

    $source->update(['status' => AccountStatus::Connected]);

    expect($repurpose->fresh()->status)->toBe(Status::Paused);
});

test('disconnecting an account says how many automations it paused', function () {
    // Full HTTP setup, not healthWorkspace(): this route authorises
    // manageAccounts on the current workspace, so the workspace needs the
    // user's account_id and the user needs current_workspace_id.
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $source = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::Instagram]);

    Repurpose::factory()->for($workspace)->create([
        'source_social_account_id' => $source->id,
        'status' => Status::Active,
        'destinations' => [healthDestination($workspace)],
    ]);

    $this->actingAs($user)
        ->delete(route('app.accounts.disconnect', $source))
        ->assertSessionHas('flash.banner', trans_choice('accounts.flash.disconnected_paused_repurposes', 1, ['count' => 1]));
});

test('disconnecting an account with no automations keeps the plain message', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $account = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::Instagram]);

    $this->actingAs($user)
        ->delete(route('app.accounts.disconnect', $account))
        ->assertSessionHas('flash.banner', __('accounts.flash.disconnected'));
});

test('switching an account off says how many automations it paused', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $source = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::Instagram]);

    Repurpose::factory()->for($workspace)->create([
        'source_social_account_id' => $source->id,
        'status' => Status::Active,
        'destinations' => [healthDestination($workspace)],
    ]);

    $this->actingAs($user)
        ->put(route('app.accounts.toggle', $source))
        ->assertSessionHas('flash.banner', trans_choice('accounts.flash.deactivated_paused_repurposes', 1, ['count' => 1]));
});

test('switching an account back on says how many automations resumed', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $source = SocialAccount::factory()->for($workspace)->create([
        'platform' => Platform::Instagram,
        'is_active' => false,
    ]);

    Repurpose::factory()->for($workspace)->create([
        'source_social_account_id' => $source->id,
        'status' => Status::Paused,
        'paused_reason' => PauseReason::SourceUnavailable,
        'destinations' => [healthDestination($workspace)],
    ]);

    $this->actingAs($user)
        ->put(route('app.accounts.toggle', $source))
        ->assertSessionHas('flash.banner', trans_choice('accounts.flash.activated_resumed_repurposes', 1, ['count' => 1]));
});
