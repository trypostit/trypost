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

beforeEach(function () {
    ['plain_token' => $this->token, 'workspace' => $this->workspace] = createApiTestToken();

    $this->source = SocialAccount::factory()->for($this->workspace)->create(['platform' => Platform::Instagram]);
    $this->tiktok = SocialAccount::factory()->for($this->workspace)->create(['platform' => Platform::TikTok]);
});

function apiHeaders(string $token): array
{
    return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
}

function tiktokDestinationPayload(SocialAccount $account): array
{
    return [
        'social_account_id' => $account->id,
        'content_type' => ContentType::TikTokVideo->value,
        'meta' => ['privacy_level' => 'PUBLIC_TO_EVERYONE'],
    ];
}

test('a repurpose can be created, read, updated and deleted', function () {
    $created = $this->withHeaders(apiHeaders($this->token))
        ->postJson(route('api.repurposes.store'), [
            'source_social_account_id' => $this->source->id,
            'source_format' => SourceFormat::Story->value,
            'destinations' => [tiktokDestinationPayload($this->tiktok)],
        ])
        ->assertCreated()
        ->json();

    expect($created['source_format'])->toBe('story')
        ->and($created['status'])->toBe('draft');

    $this->withHeaders(apiHeaders($this->token))
        ->getJson(route('api.repurposes.show', $created['id']))
        ->assertOk()
        ->assertJsonPath('source_social_account_id', $this->source->id);

    $this->withHeaders(apiHeaders($this->token))
        ->putJson(route('api.repurposes.update', $created['id']), [
            'source_social_account_id' => $this->source->id,
            'source_format' => SourceFormat::Reel->value,
            'destinations' => [tiktokDestinationPayload($this->tiktok)],
        ])
        ->assertOk()
        ->assertJsonPath('source_format', 'reel');

    $this->withHeaders(apiHeaders($this->token))
        ->deleteJson(route('api.repurposes.destroy', $created['id']))
        ->assertNoContent();

    expect(Repurpose::count())->toBe(0);
});

test('destination meta survives a round trip through the api', function () {
    $destination = tiktokDestinationPayload($this->tiktok);

    $id = $this->withHeaders(apiHeaders($this->token))
        ->postJson(route('api.repurposes.store'), [
            'source_social_account_id' => $this->source->id,
            'destinations' => [$destination],
        ])
        ->assertCreated()
        ->json('id');

    $this->withHeaders(apiHeaders($this->token))
        ->getJson(route('api.repurposes.show', $id))
        ->assertOk()
        ->assertJsonPath('destinations.0.meta.privacy_level', 'PUBLIC_TO_EVERYONE');
});

test('a destination format that cannot carry a video is rejected', function () {
    $pinterest = SocialAccount::factory()->for($this->workspace)->create(['platform' => Platform::Pinterest]);

    $this->withHeaders(apiHeaders($this->token))
        ->postJson(route('api.repurposes.store'), [
            'source_social_account_id' => $this->source->id,
            'destinations' => [[
                'social_account_id' => $pinterest->id,
                'content_type' => ContentType::PinterestPin->value,
            ]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('destinations.0.content_type');
});

test('the index lists the workspace repurposes', function () {
    Repurpose::factory()->count(2)->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
    ]);

    $this->withHeaders(apiHeaders($this->token))
        ->getJson(route('api.repurposes.index'))
        ->assertOk()
        ->assertJsonCount(2);
});

test('the status transitions are exposed', function () {
    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
        'destinations' => [tiktokDestinationPayload($this->tiktok)],
    ]);

    $this->withHeaders(apiHeaders($this->token))
        ->postJson(route('api.repurposes.activate', $repurpose))
        ->assertOk()
        ->assertJsonPath('status', Status::Active->value);

    $this->withHeaders(apiHeaders($this->token))
        ->postJson(route('api.repurposes.pause', $repurpose))
        ->assertOk()
        ->assertJsonPath('status', Status::Paused->value);

    $this->withHeaders(apiHeaders($this->token))
        ->postJson(route('api.repurposes.resume', $repurpose))
        ->assertOk()
        ->assertJsonPath('status', Status::Active->value);

    $this->withHeaders(apiHeaders($this->token))
        ->postJson(route('api.repurposes.disable', $repurpose))
        ->assertOk()
        ->assertJsonPath('status', Status::Disabled->value);
});

test('activating without a destination fails validation', function () {
    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
    ]);

    $this->withHeaders(apiHeaders($this->token))
        ->postJson(route('api.repurposes.activate', $repurpose))
        ->assertUnprocessable();
});

test('items are paginated at the documented page size', function () {
    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
    ]);

    RepurposeItem::factory()->count(20)->for($repurpose)->create(['status' => ItemStatus::Published]);

    $this->withHeaders(apiHeaders($this->token))
        ->getJson(route('api.repurposes.items', $repurpose))
        ->assertOk()
        ->assertJsonCount(15, 'data')
        ->assertJsonPath('meta.total', 20);
});

test('templates and source formats are listed', function () {
    $this->withHeaders(apiHeaders($this->token))
        ->getJson(route('api.repurpose-templates.index'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonCount(3, 'source_formats');
});

test('a repurpose from another workspace is not reachable', function () {
    $stranger = Repurpose::factory()->create();

    $this->withHeaders(apiHeaders($this->token))
        ->getJson(route('api.repurposes.show', $stranger))
        ->assertForbidden();
});
