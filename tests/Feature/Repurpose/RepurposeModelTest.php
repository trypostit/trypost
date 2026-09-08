<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\Repurpose\ItemStatus;
use App\Enums\Repurpose\SourceFormat;
use App\Enums\Repurpose\Status;
use App\Enums\SocialAccount\Platform;
use App\Models\Repurpose;
use App\Models\RepurposeItem;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

test('a repurpose belongs to a workspace, a source account and many items', function () {
    $repurpose = Repurpose::factory()->create();
    $item = RepurposeItem::factory()->for($repurpose)->create();

    expect($repurpose->status)->toBe(Status::Draft)
        ->and($repurpose->workspace)->not->toBeNull()
        ->and($repurpose->sourceAccount)->not->toBeNull()
        ->and($repurpose->items->pluck('id')->all())->toBe([$item->id])
        ->and($item->status)->toBe(ItemStatus::Pending);
});

test('destinations round-trip as an array', function () {
    $destinations = [
        ['social_account_id' => (string) Str::uuid(), 'content_type' => 'tiktok_video', 'meta' => ['privacy_level' => 'PUBLIC_TO_EVERYONE']],
    ];

    $repurpose = Repurpose::factory()->create(['destinations' => $destinations]);

    expect($repurpose->fresh()->destinations)->toEqual($destinations);
});

test('the same source media id cannot be logged twice for one repurpose', function () {
    $repurpose = Repurpose::factory()->create();
    RepurposeItem::factory()->for($repurpose)->create(['source_media_id' => 'media-1']);

    expect(fn () => RepurposeItem::factory()->for($repurpose)->create(['source_media_id' => 'media-1']))
        ->toThrow(QueryException::class);
});

test('one source account can feed a repurpose per watched format', function () {
    $repurpose = Repurpose::factory()->create(['source_format' => SourceFormat::Reel]);

    Repurpose::factory()->create([
        'workspace_id' => $repurpose->workspace_id,
        'source_social_account_id' => $repurpose->source_social_account_id,
        'source_format' => SourceFormat::Story,
    ]);

    expect(Repurpose::where('source_social_account_id', $repurpose->source_social_account_id)->count())->toBe(2);
});

test('a workspace can have one repurpose per connected account of the same network', function () {
    config()->set('trypost.allow_multiple_social_accounts', true);

    $workspace = Workspace::factory()->create();
    $first = SocialAccount::factory()->for($workspace)->create();
    $second = SocialAccount::factory()->for($workspace)->create();

    Repurpose::factory()->create(['workspace_id' => $workspace->id, 'source_social_account_id' => $first->id]);
    Repurpose::factory()->create(['workspace_id' => $workspace->id, 'source_social_account_id' => $second->id]);

    expect(Repurpose::where('workspace_id', $workspace->id)->count())->toBe(2);
});

test('the database refuses a duplicate source and format for one workspace', function () {
    $repurpose = Repurpose::factory()->create(['source_format' => SourceFormat::Reel]);

    expect(fn () => Repurpose::factory()->create([
        'workspace_id' => $repurpose->workspace_id,
        'source_social_account_id' => $repurpose->source_social_account_id,
        'source_format' => SourceFormat::Reel,
    ]))->toThrow(QueryException::class);
});

test('a repurpose knows which accounts it depends on', function () {
    $workspace = Workspace::factory()->create();
    $source = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::Instagram]);
    $destination = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::TikTok]);
    $stranger = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::Mastodon]);

    $repurpose = Repurpose::factory()->for($workspace)->create([
        'source_social_account_id' => $source->id,
        'destinations' => [
            ['social_account_id' => $destination->id, 'content_type' => ContentType::TikTokVideo->value, 'meta' => []],
        ],
    ]);

    expect($repurpose->dependsOn($source))->toBeTrue()
        ->and($repurpose->dependsOn($destination))->toBeTrue()
        ->and($repurpose->dependsOn($stranger))->toBeFalse()
        ->and($repurpose->hasDestination($destination->id))->toBeTrue()
        ->and($repurpose->hasDestination($source->id))->toBeFalse();
});

test('a repurpose with no destinations depends only on its source', function () {
    $workspace = Workspace::factory()->create();
    $source = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::Instagram]);
    $other = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::TikTok]);

    $repurpose = Repurpose::factory()->for($workspace)->create([
        'source_social_account_id' => $source->id,
        'destinations' => [],
    ]);

    expect($repurpose->dependsOn($source))->toBeTrue()
        ->and($repurpose->dependsOn($other))->toBeFalse();
});
