<?php

declare(strict_types=1);

use App\Actions\Repurpose\CreateRepurpose;
use App\Actions\Repurpose\UpdateRepurpose;
use App\Enums\PostPlatform\ContentType;
use App\Enums\Repurpose\SourceFormat;
use App\Enums\SocialAccount\Platform;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Repurpose\CreateRepurposeTool;
use App\Mcp\Tools\Repurpose\UpdateRepurposeTool;
use App\Models\Repurpose;
use App\Models\SocialAccount;
use App\Rules\Repurpose\NotTheSourceAccount;
use App\Rules\Repurpose\SourceIsFree;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The database enforces both of these. These tests are about the user never
 * meeting it: every surface has to say no first, in words.
 */
beforeEach(function () {
    config()->set('trypost.allow_multiple_social_accounts', true);

    ['plain_token' => $token, 'workspace' => $this->workspace] = createApiTestToken();

    $this->user = $this->workspace->owner;
    $this->headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

    $this->source = SocialAccount::factory()->for($this->workspace)->create(['platform' => Platform::Instagram]);
    $this->other = SocialAccount::factory()->for($this->workspace)->create(['platform' => Platform::Instagram]);
});

function selfDestination(SocialAccount $account): array
{
    return [
        'social_account_id' => $account->id,
        'content_type' => ContentType::InstagramReel->value,
        'meta' => [],
    ];
}

test('the source cannot be a destination of itself on any surface', function () {
    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
    ]);

    $payload = ['destinations' => [selfDestination($this->source)]];

    $this->actingAs($this->user)
        ->put(route('app.repurposes.update', $repurpose), $payload)
        ->assertSessionHasErrors(['destinations.0.social_account_id' => __('repurposes.errors.destination_is_source')]);

    $this->withHeaders($this->headers)
        ->putJson(route('api.repurposes.update', $repurpose), $payload)
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonValidationErrors(['destinations.0.social_account_id']);

    $this->withHeaders($this->headers)
        ->postJson(route('api.repurposes.store'), [
            'source_social_account_id' => $this->other->id,
            'destinations' => [selfDestination($this->other)],
        ])
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);

    TryPostServer::actingAs($this->user)
        ->tool(UpdateRepurposeTool::class, ['repurpose_id' => $repurpose->id, ...$payload])
        ->assertHasErrors();

    TryPostServer::actingAs($this->user)
        ->tool(CreateRepurposeTool::class, [
            'source_social_account_id' => $this->other->id,
            'destinations' => [selfDestination($this->other)],
        ])
        ->assertHasErrors();

    expect($repurpose->fresh()->destinations)->toBe([]);
});

test('a source and format already watched is refused on any surface', function () {
    Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->other->id,
        'source_format' => SourceFormat::Reel,
    ]);

    $mine = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
        'source_format' => SourceFormat::Reel,
    ]);

    $payload = ['source_social_account_id' => $this->other->id];

    $this->actingAs($this->user)
        ->put(route('app.repurposes.update', $mine), $payload)
        ->assertSessionHasErrors(['source_social_account_id' => __('repurposes.errors.source_already_used')]);

    $this->withHeaders($this->headers)
        ->putJson(route('api.repurposes.update', $mine), $payload)
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonValidationErrors(['source_social_account_id']);

    $this->withHeaders($this->headers)
        ->postJson(route('api.repurposes.store'), $payload)
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);

    TryPostServer::actingAs($this->user)
        ->tool(UpdateRepurposeTool::class, ['repurpose_id' => $mine->id, ...$payload])
        ->assertHasErrors();

    TryPostServer::actingAs($this->user)
        ->tool(CreateRepurposeTool::class, $payload)
        ->assertHasErrors();

    expect($mine->fresh()->source_social_account_id)->toBe($this->source->id);
});

test('keeping the same source on an update is not read as a clash with itself', function () {
    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
        'source_format' => SourceFormat::Reel,
    ]);

    $this->actingAs($this->user)
        ->put(route('app.repurposes.update', $repurpose), [
            'source_social_account_id' => $this->source->id,
            'source_format' => SourceFormat::Reel->value,
        ])
        ->assertSessionHasNoErrors();
});

test('a race past the validation still reads as a message, never as a constraint', function () {
    Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->other->id,
        'source_format' => SourceFormat::Reel,
    ]);

    $mine = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
        'source_format' => SourceFormat::Reel,
    ]);

    expect(fn () => UpdateRepurpose::execute($mine, ['source_social_account_id' => $this->other->id]))
        ->toThrow(ValidationException::class, __('repurposes.errors.source_already_used'));

    expect(fn () => CreateRepurpose::execute($this->workspace, $this->user, [
        'source_social_account_id' => $this->other->id,
        'source_format' => SourceFormat::Reel->value,
    ]))->toThrow(ValidationException::class, __('repurposes.errors.source_already_used'));
});

test('the rule itself refuses a source and format already watched', function () {
    Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->other->id,
        'source_format' => SourceFormat::Reel,
    ]);

    $failures = [];
    $collect = function (string $message) use (&$failures): void {
        $failures[] = $message;
    };

    (new SourceIsFree($this->workspace->id, SourceFormat::Reel))
        ->validate('source_social_account_id', $this->other->id, $collect);

    expect($failures)->toBe([__('repurposes.errors.source_already_used')]);

    $failures = [];

    (new SourceIsFree($this->workspace->id, SourceFormat::Story))
        ->validate('source_social_account_id', $this->other->id, $collect);

    (new SourceIsFree($this->workspace->id, SourceFormat::Reel))
        ->validate('source_social_account_id', $this->source->id, $collect);

    expect($failures)->toBe([]);
});

test('the rule itself refuses a repurpose that already holds the pair, unless it is the one being edited', function () {
    $mine = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->other->id,
        'source_format' => SourceFormat::Reel,
    ]);

    $failures = [];
    $collect = function (string $message) use (&$failures): void {
        $failures[] = $message;
    };

    (new SourceIsFree($this->workspace->id, SourceFormat::Reel, $mine->id))
        ->validate('source_social_account_id', $this->other->id, $collect);

    expect($failures)->toBe([]);
});

test('the rule itself refuses the source as its own destination', function () {
    $failures = [];
    $collect = function (string $message) use (&$failures): void {
        $failures[] = $message;
    };

    (new NotTheSourceAccount($this->source->id))
        ->validate('destinations.0.social_account_id', $this->source->id, $collect);

    expect($failures)->toBe([__('repurposes.errors.destination_is_source')]);

    $failures = [];

    (new NotTheSourceAccount($this->source->id))
        ->validate('destinations.0.social_account_id', $this->other->id, $collect);

    (new NotTheSourceAccount(null))
        ->validate('destinations.0.social_account_id', $this->source->id, $collect);

    expect($failures)->toBe([]);
});
