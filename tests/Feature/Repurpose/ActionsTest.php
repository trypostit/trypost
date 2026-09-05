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
use App\Enums\Repurpose\SourceFormat;
use App\Enums\Repurpose\Status;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\Repurpose;
use App\Models\RepurposeItem;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;
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
    $repurpose = Repurpose::factory()->active()->create([
        'source_format' => SourceFormat::Reel,
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
    $repurpose = Repurpose::factory()->active()->create();
    $watermark = $repurpose->activated_at;

    $paused = PauseRepurpose::execute($repurpose);

    expect($paused->status)->toBe(Status::Paused)
        ->and($paused->activated_at->equalTo($watermark))->toBeTrue();

    $resumed = ResumeRepurpose::execute($paused);

    expect($resumed->status)->toBe(Status::Active)
        ->and($resumed->activated_at->equalTo($watermark))->toBeTrue();
});

test('disabling clears the watermark so re-activation starts fresh', function () {
    $repurpose = Repurpose::factory()->active()->create([
        'destinations' => [['social_account_id' => (string) Str::uuid(), 'content_type' => ContentType::TikTokVideo->value, 'meta' => []]],
    ]);

    $disabled = DisableRepurpose::execute($repurpose);

    expect($disabled->status)->toBe(Status::Disabled)
        ->and($disabled->activated_at)->toBeNull();

    $reactivated = ActivateRepurpose::execute($disabled);

    expect($reactivated->activated_at->isToday())->toBeTrue();
});

test('changing the source account resets the watermark', function () {
    config()->set('trypost.allow_multiple_social_accounts', true);

    $repurpose = Repurpose::factory()->active()->create(['activated_at' => now()->subMonth()]);
    $newAccount = SocialAccount::factory()->create(['workspace_id' => $repurpose->workspace_id]);

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
