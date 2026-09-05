<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\Repurpose\Status;
use App\Enums\SocialAccount\Platform;
use App\Enums\UserWorkspace\Role;
use App\Models\Repurpose;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $this->source = SocialAccount::factory()->for($this->workspace)->create(['platform' => Platform::Instagram]);
    $this->tiktok = SocialAccount::factory()->for($this->workspace)->create(['platform' => Platform::TikTok]);
});

function destinationPayload(SocialAccount $account): array
{
    return [
        'social_account_id' => $account->id,
        'content_type' => ContentType::TikTokVideo->value,
        'meta' => ['privacy_level' => 'PUBLIC_TO_EVERYONE'],
    ];
}

test('the index lists repurposes and the ready-made templates', function () {
    Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
    ]);

    $this->actingAs($this->user)
        ->get(route('app.repurposes.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('repurposes/Index')
            ->has('repurposes', 1)
            ->has('templates', 2)
            ->has('sourceAccounts', 1));
});

test('only networks we can download from are offered as a source', function () {
    $this->actingAs($this->user)
        ->get(route('app.repurposes.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('sourceAccounts', 1)
            ->where('sourceAccounts.0.id', $this->source->id));
});

test('storing creates a draft and redirects to its page', function () {
    $response = $this->actingAs($this->user)
        ->post(route('app.repurposes.store'), ['source_social_account_id' => $this->source->id]);

    $repurpose = Repurpose::sole();

    $response->assertRedirect(route('app.repurposes.show', $repurpose));

    expect($repurpose->status)->toBe(Status::Draft);
});

test('storing for an account that already has a repurpose redirects to the existing one', function () {
    $existing = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
    ]);

    $this->actingAs($this->user)
        ->post(route('app.repurposes.store'), ['source_social_account_id' => $this->source->id])
        ->assertRedirect(route('app.repurposes.show', $existing));

    expect(Repurpose::count())->toBe(1);
});

test('the show page renders the repurpose, its destinations and its items', function () {
    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
    ]);

    $this->actingAs($this->user)
        ->get(route('app.repurposes.show', $repurpose))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('repurposes/Show')
            ->where('repurpose.id', $repurpose->id)
            ->has('destinationAccounts', 1)
            ->has('items'));
});

test('updating saves destinations with their platform meta', function () {
    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
    ]);

    $destination = destinationPayload($this->tiktok);

    $this->actingAs($this->user)
        ->put(route('app.repurposes.update', $repurpose), ['destinations' => [$destination]])
        ->assertRedirect();

    expect($repurpose->fresh()->destinations)->toEqual([$destination]);
});

test('the status transitions are exposed', function () {
    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
        'destinations' => [destinationPayload($this->tiktok)],
    ]);

    $this->actingAs($this->user)->post(route('app.repurposes.activate', $repurpose))->assertRedirect();
    expect($repurpose->fresh()->status)->toBe(Status::Active);

    $this->actingAs($this->user)->post(route('app.repurposes.pause', $repurpose))->assertRedirect();
    expect($repurpose->fresh()->status)->toBe(Status::Paused);

    $this->actingAs($this->user)->post(route('app.repurposes.resume', $repurpose))->assertRedirect();
    expect($repurpose->fresh()->status)->toBe(Status::Active);

    $this->actingAs($this->user)->post(route('app.repurposes.disable', $repurpose))->assertRedirect();
    expect($repurpose->fresh()->status)->toBe(Status::Disabled);
});

test('activating without a destination fails validation', function () {
    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
    ]);

    $this->actingAs($this->user)
        ->post(route('app.repurposes.activate', $repurpose))
        ->assertSessionHasErrors('destinations');
});

test('deleting removes the repurpose', function () {
    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
    ]);

    $this->actingAs($this->user)
        ->delete(route('app.repurposes.destroy', $repurpose))
        ->assertRedirect(route('app.repurposes.index'));

    expect(Repurpose::count())->toBe(0);
});

test('a viewer cannot create a repurpose', function () {
    $viewer = User::factory()->create([
        'account_id' => $this->user->account_id,
        'current_workspace_id' => $this->workspace->id,
    ]);
    $this->workspace->members()->attach($viewer->id, ['role' => Role::Viewer->value]);

    $this->actingAs($viewer)
        ->post(route('app.repurposes.store'), ['source_social_account_id' => $this->source->id])
        ->assertForbidden();
});

test('a repurpose from another workspace is not reachable', function () {
    $stranger = Repurpose::factory()->create();

    $this->actingAs($this->user)
        ->get(route('app.repurposes.show', $stranger))
        ->assertForbidden();
});
