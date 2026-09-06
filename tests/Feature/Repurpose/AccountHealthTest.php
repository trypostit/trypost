<?php

declare(strict_types=1);

use App\Actions\Repurpose\ActivateRepurpose;
use App\Actions\Repurpose\UpdateRepurpose;
use App\Enums\PostPlatform\ContentType;
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
