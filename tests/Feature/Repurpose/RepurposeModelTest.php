<?php

declare(strict_types=1);

use App\Enums\Repurpose\ItemStatus;
use App\Enums\Repurpose\Status;
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

test('a workspace cannot have two repurposes for the same source account', function () {
    $repurpose = Repurpose::factory()->create();

    expect(fn () => Repurpose::factory()->create([
        'workspace_id' => $repurpose->workspace_id,
        'source_social_account_id' => $repurpose->source_social_account_id,
    ]))->toThrow(QueryException::class);
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
