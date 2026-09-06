<?php

declare(strict_types=1);

use App\Actions\Repurpose\ActivateRepurpose;
use App\Actions\Repurpose\CreateRepurpose;
use App\Actions\Repurpose\DeleteRepurpose;
use App\Actions\Repurpose\DisableRepurpose;
use App\Actions\Repurpose\PauseRepurpose;
use App\Actions\Repurpose\ResumeRepurpose;
use App\Actions\Repurpose\UpdateRepurpose;
use App\Enums\PostPlatform\ContentType;
use App\Enums\Repurpose\PublishMode;
use App\Enums\Repurpose\SourceFormat;
use App\Enums\Repurpose\Status;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\Repurpose;
use App\Models\RepurposeItem;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Validation\ValidationException;

function repurposeWorkspace(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $account = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::Instagram]);

    return [$workspace, $user, $account];
}

function tiktokDestination(Workspace $workspace): array
{
    $account = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::TikTok]);

    return [
        'social_account_id' => $account->id,
        'content_type' => ContentType::TikTokVideo->value,
        'meta' => ['privacy_level' => 'PUBLIC_TO_EVERYONE'],
    ];
}

test('a repurpose is created as a draft with its source account', function () {
    [$workspace, $user, $account] = repurposeWorkspace();

    $repurpose = CreateRepurpose::execute($workspace, $user, ['source_social_account_id' => $account->id]);

    expect($repurpose->status)->toBe(Status::Draft)
        ->and($repurpose->source_social_account_id)->toBe($account->id)
        ->and($repurpose->destinations)->toBe([])
        ->and($repurpose->activated_at)->toBeNull();
});

test('a second repurpose for the same source account and format is rejected', function () {
    [$workspace, $user, $account] = repurposeWorkspace();

    CreateRepurpose::execute($workspace, $user, ['source_social_account_id' => $account->id]);

    expect(fn () => CreateRepurpose::execute($workspace, $user, ['source_social_account_id' => $account->id]))
        ->toThrow(ValidationException::class);
});

test('one account can feed one repurpose per watched format', function () {
    [$workspace, $user, $account] = repurposeWorkspace();

    foreach ([SourceFormat::Reel, SourceFormat::Video, SourceFormat::Story] as $format) {
        CreateRepurpose::execute($workspace, $user, [
            'source_social_account_id' => $account->id,
            'source_format' => $format->value,
        ]);
    }

    expect(Repurpose::where('source_social_account_id', $account->id)->count())->toBe(3);
});

test('changing the watched format resets the watermark', function () {
    [$workspace, $user, $account] = repurposeWorkspace();

    $repurpose = Repurpose::factory()->active()->create([
        'workspace_id' => $workspace->id,
        'source_social_account_id' => $account->id,
        'source_format' => SourceFormat::Reel,
        'destinations' => [tiktokDestination($workspace)],
        'activated_at' => now()->subMonth(),
    ]);

    $updated = UpdateRepurpose::execute($repurpose, ['source_format' => SourceFormat::Story->value]);

    expect($updated->source_format)->toBe(SourceFormat::Story)
        ->and($updated->activated_at->isToday())->toBeTrue();
});

test('two accounts on the same network each get their own repurpose', function () {
    config()->set('trypost.allow_multiple_social_accounts', true);

    [$workspace, $user, $first] = repurposeWorkspace();
    $second = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::Instagram]);

    CreateRepurpose::execute($workspace, $user, ['source_social_account_id' => $first->id]);
    CreateRepurpose::execute($workspace, $user, ['source_social_account_id' => $second->id]);

    expect(Repurpose::where('workspace_id', $workspace->id)->count())->toBe(2);
});

test('destination meta survives create and update', function () {
    [$workspace, $user, $account] = repurposeWorkspace();
    $destination = tiktokDestination($workspace);

    $repurpose = CreateRepurpose::execute($workspace, $user, [
        'source_social_account_id' => $account->id,
        'destinations' => [$destination],
    ]);

    expect($repurpose->fresh()->destinations)->toEqual([$destination]);

    $updated = UpdateRepurpose::execute($repurpose, ['destinations' => [$destination]]);

    expect($updated->destinations)->toEqual([$destination]);
});

test('a destination that cannot publish without meta blocks activation', function () {
    [$workspace, $user, $account] = repurposeWorkspace();

    foreach ([
        [Platform::TikTok, ContentType::TikTokVideo],
        [Platform::Pinterest, ContentType::PinterestVideoPin],
        [Platform::Discord, ContentType::DiscordMessage],
    ] as [$platform, $contentType]) {
        $destination = SocialAccount::factory()->for($workspace)->create(['platform' => $platform]);

        $repurpose = Repurpose::factory()->create([
            'workspace_id' => $workspace->id,
            'source_social_account_id' => $account->id,
            'source_format' => SourceFormat::Reel,
            'destinations' => [[
                'social_account_id' => $destination->id,
                'content_type' => $contentType->value,
                'meta' => [],
            ]],
        ]);

        expect(fn () => ActivateRepurpose::execute($repurpose))
            ->toThrow(ValidationException::class);

        $repurpose->delete();
        $destination->delete();
    }
});

test('a destination carrying its required meta activates', function () {
    [$workspace, $user, $account] = repurposeWorkspace();

    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $workspace->id,
        'source_social_account_id' => $account->id,
        'destinations' => [tiktokDestination($workspace)],
    ]);

    expect(ActivateRepurpose::execute($repurpose)->status)->toBe(Status::Active);
});

test('a destination that needs no meta activates', function () {
    [$workspace, $user, $account] = repurposeWorkspace();

    $telegram = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::Telegram]);

    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $workspace->id,
        'source_social_account_id' => $account->id,
        'destinations' => [[
            'social_account_id' => $telegram->id,
            'content_type' => ContentType::TelegramPost->value,
            'meta' => [],
        ]],
    ]);

    expect(ActivateRepurpose::execute($repurpose)->status)->toBe(Status::Active);
});

test('activation requires at least one destination', function () {
    $repurpose = Repurpose::factory()->create();

    expect(fn () => ActivateRepurpose::execute($repurpose))->toThrow(ValidationException::class);
});

test('activation stamps the watermark', function () {
    [$workspace, $user, $account] = repurposeWorkspace();

    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $workspace->id,
        'source_social_account_id' => $account->id,
        'destinations' => [tiktokDestination($workspace)],
    ]);

    $activated = ActivateRepurpose::execute($repurpose);

    expect($activated->status)->toBe(Status::Active)
        ->and($activated->activated_at)->not->toBeNull();
});

test('pausing keeps the watermark and resuming does not move it', function () {
    [$workspace, $user, $account] = repurposeWorkspace();

    // Resuming runs the same health gates as activating, so the repurpose needs
    // a usable source and destination for this watermark test to reach them.
    $repurpose = Repurpose::factory()->active()->create([
        'workspace_id' => $workspace->id,
        'source_social_account_id' => $account->id,
        'destinations' => [tiktokDestination($workspace)],
    ]);
    $watermark = $repurpose->activated_at;

    $paused = PauseRepurpose::execute($repurpose);

    expect($paused->status)->toBe(Status::Paused)
        ->and($paused->activated_at->equalTo($watermark))->toBeTrue();

    $resumed = ResumeRepurpose::execute($paused);

    expect($resumed->status)->toBe(Status::Active)
        ->and($resumed->activated_at->equalTo($watermark))->toBeTrue();
});

test('disabling clears the watermark so re-activation starts fresh', function () {
    [$workspace, $user, $account] = repurposeWorkspace();

    $repurpose = Repurpose::factory()->active()->create([
        'workspace_id' => $workspace->id,
        'source_social_account_id' => $account->id,
        'destinations' => [tiktokDestination($workspace)],
    ]);

    $disabled = DisableRepurpose::execute($repurpose);

    expect($disabled->status)->toBe(Status::Disabled)
        ->and($disabled->activated_at)->toBeNull();

    $reactivated = ActivateRepurpose::execute($disabled);

    expect($reactivated->activated_at->isToday())->toBeTrue();
});

test('changing the source account resets the watermark', function () {
    config()->set('trypost.allow_multiple_social_accounts', true);

    [$workspace, $user, $account] = repurposeWorkspace();

    $repurpose = Repurpose::factory()->active()->create([
        'workspace_id' => $workspace->id,
        'source_social_account_id' => $account->id,
        'destinations' => [tiktokDestination($workspace)],
        'activated_at' => now()->subMonth(),
    ]);
    $newAccount = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::Instagram]);

    $updated = UpdateRepurpose::execute($repurpose, ['source_social_account_id' => $newAccount->id]);

    expect($updated->source_social_account_id)->toBe($newAccount->id)
        ->and($updated->activated_at->isToday())->toBeTrue();
});

test('deleting a repurpose removes its items but keeps the posts it created', function () {
    $repurpose = Repurpose::factory()->create();
    $item = RepurposeItem::factory()->for($repurpose)->create();
    $post = Post::factory()->create(['workspace_id' => $repurpose->workspace_id, 'repurpose_item_id' => $item->id]);

    DeleteRepurpose::execute($repurpose);

    expect(RepurposeItem::whereKey($item->id)->exists())->toBeFalse()
        ->and(Post::whereKey($post->id)->exists())->toBeTrue()
        ->and($post->fresh()->repurpose_item_id)->toBeNull();
});

test('an active repurpose cannot be updated into a state it could not be activated in', function () {
    [$workspace, $user, $account] = repurposeWorkspace();

    $repurpose = Repurpose::factory()->active()->create([
        'workspace_id' => $workspace->id,
        'source_social_account_id' => $account->id,
        'destinations' => [tiktokDestination($workspace)],
    ]);

    expect(fn () => UpdateRepurpose::execute($repurpose, ['destinations' => []]))
        ->toThrow(ValidationException::class);

    $discord = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::Discord]);

    expect(fn () => UpdateRepurpose::execute($repurpose, ['destinations' => [[
        'social_account_id' => $discord->id,
        'content_type' => ContentType::DiscordMessage->value,
        'meta' => [],
    ]]]))->toThrow(ValidationException::class);
});

test('a repurpose can only be resumed from paused', function () {
    $repurpose = Repurpose::factory()->disabled()->create();

    expect(fn () => ResumeRepurpose::execute($repurpose))->toThrow(ValidationException::class);
});

test('a draft cannot be paused, so resuming can never start from a blank watermark', function () {
    $repurpose = Repurpose::factory()->create(['status' => Status::Draft]);

    expect(fn () => PauseRepurpose::execute($repurpose))->toThrow(ValidationException::class);

    expect($repurpose->fresh()->status)->toBe(Status::Draft);
});

test('resuming stamps a watermark when the repurpose somehow lacks one', function () {
    [$workspace, $user, $account] = repurposeWorkspace();

    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $workspace->id,
        'source_social_account_id' => $account->id,
        'destinations' => [tiktokDestination($workspace)],
        'status' => Status::Paused,
        'activated_at' => null,
    ]);

    $resumed = ResumeRepurpose::execute($repurpose);

    expect($resumed->status)->toBe(Status::Active)
        ->and($resumed->activated_at)->not->toBeNull();
});

test('an already active repurpose cannot be activated again over its watermark', function () {
    [$workspace, $user, $account] = repurposeWorkspace();

    $repurpose = Repurpose::factory()->active()->create([
        'workspace_id' => $workspace->id,
        'source_social_account_id' => $account->id,
        'destinations' => [tiktokDestination($workspace)],
        'activated_at' => now()->subDays(5),
    ]);

    expect(fn () => ActivateRepurpose::execute($repurpose))->toThrow(ValidationException::class);

    expect($repurpose->fresh()->activated_at->isSameDay(now()->subDays(5)))->toBeTrue();
});

test('a draft cannot be turned off', function () {
    $repurpose = Repurpose::factory()->create(['status' => Status::Draft]);

    expect(fn () => DisableRepurpose::execute($repurpose))->toThrow(ValidationException::class);

    expect($repurpose->fresh()->status)->toBe(Status::Draft);
});

test('an update the activation rules reject leaves the stored destinations untouched', function () {
    [$workspace, $user, $account] = repurposeWorkspace();

    $destination = tiktokDestination($workspace);

    $repurpose = Repurpose::factory()->active()->create([
        'workspace_id' => $workspace->id,
        'source_social_account_id' => $account->id,
        'destinations' => [$destination],
    ]);

    $pinterest = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::Pinterest]);

    expect(fn () => UpdateRepurpose::execute($repurpose, ['destinations' => [[
        'social_account_id' => $pinterest->id,
        'content_type' => ContentType::PinterestPin->value,
        'meta' => [],
    ]]]))->toThrow(ValidationException::class);

    expect($repurpose->fresh()->destinations)->toEqual([$destination]);
});

test('a transition reads the stored status, not the copy the caller is holding', function () {
    $repurpose = Repurpose::factory()->active()->create();

    Repurpose::query()->whereKey($repurpose->id)->update(['status' => Status::Paused]);

    expect($repurpose->status)->toBe(Status::Active)
        ->and(fn () => PauseRepurpose::execute($repurpose))->toThrow(ValidationException::class);

    expect($repurpose->fresh()->status)->toBe(Status::Paused);
});

test('an update that repeats the current source and format leaves the watermark alone', function () {
    [$workspace, $user, $account] = repurposeWorkspace();

    $repurpose = Repurpose::factory()->active()->create([
        'workspace_id' => $workspace->id,
        'source_social_account_id' => $account->id,
        'source_format' => SourceFormat::Reel,
        'destinations' => [tiktokDestination($workspace)],
        'activated_at' => now()->subDays(3),
    ]);

    $watermark = $repurpose->activated_at;

    UpdateRepurpose::execute($repurpose, [
        'source_social_account_id' => $repurpose->source_social_account_id,
        'source_format' => SourceFormat::Reel->value,
        'publish_mode' => PublishMode::Draft->value,
    ]);

    $fresh = $repurpose->fresh();

    expect($fresh->activated_at->equalTo($watermark))->toBeTrue()
        ->and($fresh->publish_mode)->toBe(PublishMode::Draft);
});
