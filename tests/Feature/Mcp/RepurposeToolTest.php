<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\Repurpose\ItemStatus;
use App\Enums\Repurpose\SourceFormat;
use App\Enums\Repurpose\Status;
use App\Enums\SocialAccount\Platform;
use App\Enums\UserWorkspace\Role;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Repurpose\ActivateRepurposeTool;
use App\Mcp\Tools\Repurpose\CreateRepurposeTool;
use App\Mcp\Tools\Repurpose\DeleteRepurposeTool;
use App\Mcp\Tools\Repurpose\GetRepurposeTool;
use App\Mcp\Tools\Repurpose\ListRepurposeItemsTool;
use App\Mcp\Tools\Repurpose\ListRepurposesTool;
use App\Mcp\Tools\Repurpose\ListRepurposeTemplatesTool;
use App\Mcp\Tools\Repurpose\PauseRepurposeTool;
use App\Mcp\Tools\Repurpose\UpdateRepurposeTool;
use App\Models\Repurpose;
use App\Models\RepurposeItem;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $this->source = SocialAccount::factory()->for($this->workspace)->create(['platform' => Platform::Instagram]);
    $this->tiktok = SocialAccount::factory()->for($this->workspace)->create(['platform' => Platform::TikTok]);
});

function tiktokDestinationForMcp(SocialAccount $account): array
{
    return [
        'social_account_id' => $account->id,
        'content_type' => ContentType::TikTokVideo->value,
        'meta' => ['privacy_level' => 'PUBLIC_TO_EVERYONE'],
    ];
}

test('a repurpose is created with its watched format and destination meta', function () {
    $response = TryPostServer::actingAs($this->user)
        ->tool(CreateRepurposeTool::class, [
            'source_social_account_id' => $this->source->id,
            'source_format' => SourceFormat::Story->value,
            'destinations' => [tiktokDestinationForMcp($this->tiktok)],
        ]);

    $response->assertOk();

    $repurpose = Repurpose::sole();

    expect($repurpose->source_format)->toBe(SourceFormat::Story)
        ->and($repurpose->status)->toBe(Status::Draft)
        ->and($repurpose->destinations)->toEqual([tiktokDestinationForMcp($this->tiktok)]);
});

test('destination meta survives a read back through the get tool', function () {
    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
        'destinations' => [tiktokDestinationForMcp($this->tiktok)],
    ]);

    TryPostServer::actingAs($this->user)
        ->tool(GetRepurposeTool::class, ['repurpose_id' => $repurpose->id])
        ->assertOk()
        ->assertSee('PUBLIC_TO_EVERYONE');
});

test('the list tool returns the workspace repurposes and nobody else\'s', function () {
    $reel = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
        'source_format' => SourceFormat::Reel,
        'created_at' => now()->subHour(),
    ]);

    $story = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
        'source_format' => SourceFormat::Story,
        'created_at' => now(),
    ]);

    $otherWorkspace = Workspace::factory()->create();
    Repurpose::factory()->create([
        'workspace_id' => $otherWorkspace->id,
        'source_social_account_id' => SocialAccount::factory()->create([
            'workspace_id' => $otherWorkspace->id,
            'platform' => Platform::Instagram,
        ])->id,
    ]);

    TryPostServer::actingAs($this->user)
        ->tool(ListRepurposesTool::class, [])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->has('repurposes', 2)
            ->where('repurposes.0.id', $story->id)
            ->where('repurposes.0.source_format', SourceFormat::Story->value)
            ->where('repurposes.1.id', $reel->id)
            ->where('repurposes.1.source_format', SourceFormat::Reel->value)
            ->etc());
});

test('updating replaces the destinations and the watched format', function () {
    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
        'source_format' => SourceFormat::Reel,
    ]);

    TryPostServer::actingAs($this->user)
        ->tool(UpdateRepurposeTool::class, [
            'repurpose_id' => $repurpose->id,
            'source_social_account_id' => $this->source->id,
            'source_format' => SourceFormat::Video->value,
            'destinations' => [tiktokDestinationForMcp($this->tiktok)],
        ])
        ->assertOk();

    expect($repurpose->fresh()->source_format)->toBe(SourceFormat::Video)
        ->and($repurpose->fresh()->destinations)->toEqual([tiktokDestinationForMcp($this->tiktok)]);
});

test('activate and pause move the status', function () {
    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
        'destinations' => [tiktokDestinationForMcp($this->tiktok)],
    ]);

    TryPostServer::actingAs($this->user)
        ->tool(ActivateRepurposeTool::class, ['repurpose_id' => $repurpose->id])
        ->assertOk();

    expect($repurpose->fresh()->status)->toBe(Status::Active);

    TryPostServer::actingAs($this->user)
        ->tool(PauseRepurposeTool::class, ['repurpose_id' => $repurpose->id])
        ->assertOk();

    expect($repurpose->fresh()->status)->toBe(Status::Paused);
});

test('activating without a destination reports an error instead of activating', function () {
    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
    ]);

    TryPostServer::actingAs($this->user)
        ->tool(ActivateRepurposeTool::class, ['repurpose_id' => $repurpose->id])
        ->assertHasErrors();

    expect($repurpose->fresh()->status)->toBe(Status::Draft);
});

test('the items tool exposes why a video was skipped', function () {
    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
    ]);

    RepurposeItem::factory()->for($repurpose)->create([
        'status' => ItemStatus::Skipped,
        'reason' => 'published_via_trypost',
    ]);

    TryPostServer::actingAs($this->user)
        ->tool(ListRepurposeItemsTool::class, ['repurpose_id' => $repurpose->id])
        ->assertOk()
        ->assertSee('published_via_trypost');
});

test('templates and source formats are listed', function () {
    TryPostServer::actingAs($this->user)
        ->tool(ListRepurposeTemplatesTool::class, [])
        ->assertOk()
        ->assertSee('instagram_everywhere');
});

test('a repurpose from another workspace is not reachable', function () {
    $stranger = Repurpose::factory()->create();

    TryPostServer::actingAs($this->user)
        ->tool(GetRepurposeTool::class, ['repurpose_id' => $stranger->id])
        ->assertHasErrors();
});

test('deleting removes the repurpose', function () {
    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
    ]);

    TryPostServer::actingAs($this->user)
        ->tool(DeleteRepurposeTool::class, ['repurpose_id' => $repurpose->id])
        ->assertOk();

    expect(Repurpose::count())->toBe(0);
});

test('an account from another workspace is rejected as a destination', function () {
    $stranger = SocialAccount::factory()->create(['platform' => Platform::TikTok]);

    TryPostServer::actingAs($this->user)
        ->tool(CreateRepurposeTool::class, [
            'source_social_account_id' => $this->source->id,
            'destinations' => [tiktokDestinationForMcp($stranger)],
        ])
        ->assertHasErrors();

    expect(Repurpose::count())->toBe(0);
});
