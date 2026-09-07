<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\PostPlatform\Status as PostPlatformStatus;
use App\Enums\Repurpose\ItemStatus;
use App\Enums\Repurpose\PauseReason;
use App\Enums\Repurpose\PublishMode;
use App\Enums\Repurpose\SourceFormat;
use App\Enums\Repurpose\Status;
use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status as AccountStatus;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\Repurpose;
use App\Models\RepurposeItem;
use App\Models\SocialAccount;
use Symfony\Component\HttpFoundation\Response;

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
    foreach ([SourceFormat::Reel, SourceFormat::Story] as $format) {
        Repurpose::factory()->create([
            'workspace_id' => $this->workspace->id,
            'source_social_account_id' => $this->source->id,
            'source_format' => $format,
        ]);
    }

    $this->withHeaders(apiHeaders($this->token))
        ->getJson(route('api.repurposes.index'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.per_page', 15)
        ->assertJsonPath('data.0.source_account.id', $this->source->id);
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

test('an account from another workspace is rejected as a source', function () {
    $stranger = SocialAccount::factory()->create(['platform' => Platform::Instagram]);

    $this->withHeaders(apiHeaders($this->token))
        ->postJson(route('api.repurposes.store'), ['source_social_account_id' => $stranger->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('source_social_account_id');
});

test('an account from another workspace is rejected as a destination', function () {
    $stranger = SocialAccount::factory()->create(['platform' => Platform::TikTok]);

    $this->withHeaders(apiHeaders($this->token))
        ->postJson(route('api.repurposes.store'), [
            'source_social_account_id' => $this->source->id,
            'destinations' => [tiktokDestinationPayload($stranger)],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('destinations.0.social_account_id');
});

test('a network we cannot download from is rejected as a source', function () {
    $tiktokSource = SocialAccount::factory()->for($this->workspace)->create(['platform' => Platform::YouTube]);

    $this->withHeaders(apiHeaders($this->token))
        ->postJson(route('api.repurposes.store'), ['source_social_account_id' => $tiktokSource->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('source_social_account_id');
});

test('a content type from another network is rejected for a destination', function () {
    $this->withHeaders(apiHeaders($this->token))
        ->postJson(route('api.repurposes.store'), [
            'source_social_account_id' => $this->source->id,
            'destinations' => [[
                'social_account_id' => $this->tiktok->id,
                'content_type' => ContentType::YouTubeShort->value,
            ]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('destinations.0.content_type');
});

test('the api refuses a transition the interface would never offer', function () {
    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'source_social_account_id' => $this->source->id,
        'destinations' => [tiktokDestinationPayload($this->tiktok)],
    ]);

    $this->withHeaders(apiHeaders($this->token))
        ->postJson(route('api.repurposes.pause', $repurpose))
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);

    $this->withHeaders(apiHeaders($this->token))
        ->postJson(route('api.repurposes.disable', $repurpose))
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);

    $this->withHeaders(apiHeaders($this->token))
        ->postJson(route('api.repurposes.activate', $repurpose))
        ->assertOk();

    $watermark = $repurpose->fresh()->activated_at;

    $this->withHeaders(apiHeaders($this->token))
        ->postJson(route('api.repurposes.activate', $repurpose))
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);

    expect($repurpose->fresh()->activated_at->equalTo($watermark))->toBeTrue();

    $this->withHeaders(apiHeaders($this->token))
        ->postJson(route('api.repurposes.resume', $repurpose))
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
});

test('the publishing mode round-trips through the api', function () {
    $created = $this->withHeaders(apiHeaders($this->token))
        ->postJson(route('api.repurposes.store'), [
            'source_social_account_id' => $this->source->id,
            'publish_mode' => PublishMode::Draft->value,
        ])
        ->assertStatus(Response::HTTP_CREATED)
        ->assertJsonPath('publish_mode', PublishMode::Draft->value);

    $this->withHeaders(apiHeaders($this->token))
        ->putJson(route('api.repurposes.update', $created->json('id')), [
            'publish_mode' => PublishMode::Publish->value,
        ])
        ->assertOk()
        ->assertJsonPath('publish_mode', PublishMode::Publish->value);

    $this->withHeaders(apiHeaders($this->token))
        ->putJson(route('api.repurposes.update', $created->json('id')), ['publish_mode' => 'whenever'])
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
});

test('the api exposes why a repurpose stopped and refuses to resume it while broken', function () {
    $repurpose = Repurpose::factory()->for($this->workspace)->create([
        'source_social_account_id' => $this->source->id,
        'status' => Status::Paused,
        'paused_reason' => PauseReason::SourceUnavailable,
        'destinations' => [tiktokDestinationPayload($this->tiktok)],
    ]);

    $this->source->update(['status' => AccountStatus::Disconnected]);

    $this->withHeaders(apiHeaders($this->token))
        ->getJson(route('api.repurposes.show', $repurpose))
        ->assertOk()
        ->assertJsonPath('paused_reason', PauseReason::SourceUnavailable->value);

    // The health gate lives in the action, so every surface inherits it.
    $this->withHeaders(apiHeaders($this->token))
        ->postJson(route('api.repurposes.resume', $repurpose))
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonValidationErrors('source_social_account_id');
});

test('the api accepts a switched-off account as a destination', function () {
    $repurpose = Repurpose::factory()->for($this->workspace)->create([
        'source_social_account_id' => $this->source->id,
    ]);

    $this->tiktok->update(['is_active' => false]);

    $this->withHeaders(apiHeaders($this->token))
        ->putJson(route('api.repurposes.update', $repurpose), [
            'destinations' => [tiktokDestinationPayload($this->tiktok)],
        ])
        ->assertOk();

    expect($repurpose->fresh()->destinations)->toHaveCount(1);
});

test('the api activity list carries each replicated post status', function () {
    $repurpose = Repurpose::factory()->for($this->workspace)->create([
        'source_social_account_id' => $this->source->id,
    ]);

    $item = RepurposeItem::factory()->for($repurpose)->create();

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'repurpose_item_id' => $item->id,
    ]);
    PostPlatform::factory()->for($post)->create([
        'platform' => Platform::TikTok,
        'enabled' => true,
        'status' => PostPlatformStatus::Published,
    ]);

    $this->withHeaders(apiHeaders($this->token))
        ->getJson(route('api.repurposes.items', $repurpose))
        ->assertOk()
        ->assertJsonPath('data.0.posts.0.platforms.0.status', PostPlatformStatus::Published->value);
});
