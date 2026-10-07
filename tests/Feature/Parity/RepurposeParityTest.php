<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\Repurpose\SourceFormat;
use App\Enums\Repurpose\Status;
use App\Enums\SocialAccount\Platform;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Repurpose\ActivateRepurposeTool;
use App\Mcp\Tools\Repurpose\CreateRepurposeTool;
use App\Mcp\Tools\Repurpose\DeleteRepurposeTool;
use App\Mcp\Tools\Repurpose\DisableRepurposeTool;
use App\Mcp\Tools\Repurpose\GetRepurposeTool;
use App\Mcp\Tools\Repurpose\ListRepurposeItemsTool;
use App\Mcp\Tools\Repurpose\ListRepurposeSourceFormatsTool;
use App\Mcp\Tools\Repurpose\ListRepurposesTool;
use App\Mcp\Tools\Repurpose\PauseRepurposeTool;
use App\Mcp\Tools\Repurpose\ResumeRepurposeTool;
use App\Mcp\Tools\Repurpose\UpdateRepurposeTool;
use App\Models\Repurpose;
use App\Models\RepurposeItem;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Support\Requests\Repurpose\RepurposeRequestRules;
use Illuminate\Testing\Fluent\AssertableJson;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    ['user' => $this->user, 'workspace' => $this->workspace, 'token' => $this->token] = parityContext();
    $this->instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    $this->facebook = SocialAccount::factory()->facebook()->create(['workspace_id' => $this->workspace->id]);
});

test('a repurpose created through the api and mcp is a draft with the same source and format, and activating without destinations is refused alike', function () {
    $other = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);

    auth()->forgetGuards();
    $api = $this->withHeaders(parityApi($this->token))
        ->postJson(route('api.repurposes.store'), ['source_social_account_id' => $this->instagram->id, 'source_format' => 'reel'])
        ->assertCreated()
        ->assertJsonPath('status', 'draft')
        ->json();

    TryPostServer::actingAs($this->user)
        ->tool(CreateRepurposeTool::class, ['source_social_account_id' => $other->id, 'source_format' => 'reel'])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('status', 'draft')
            ->where('source_social_account_id', $other->id)
            ->where('source_format', 'reel')
            ->etc());

    expect($api['source_format'])->toBe('reel')->and($api['source_social_account_id'])->toBe($this->instagram->id);

    $mcp = Repurpose::query()->where('source_social_account_id', $other->id)->firstOrFail();

    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))
        ->postJson(route('api.repurposes.activate', $api['id']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['destinations']);

    TryPostServer::actingAs($this->user)
        ->tool(ActivateRepurposeTool::class, ['repurpose_id' => $mcp->id])
        ->assertHasErrors([__('repurposes.errors.destinations_required')]);

    $this->actingAs($this->user)
        ->post(route('app.repurposes.activate', $api['id']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['destinations']);

    expect(Repurpose::query()->where('status', Status::Draft)->count())->toBe(2);
});

test('a source already used is refused by the api and mcp but the web store redirects to the existing repurpose', function () {
    $existing = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'source_social_account_id' => $this->instagram->id,
    ]);
    $payload = ['source_social_account_id' => $this->instagram->id, 'source_format' => 'reel'];

    $this->actingAs($this->user)
        ->post(route('app.repurposes.store'), $payload)
        ->assertRedirect(route('app.repurposes.show', $existing));

    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))
        ->postJson(route('api.repurposes.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['source_social_account_id']);

    TryPostServer::actingAs($this->user)
        ->tool(CreateRepurposeTool::class, $payload)
        ->assertHasErrors([__('repurposes.errors.source_already_used')]);

    expect(Repurpose::query()->count())->toBe(1);
});

test('google business is refused as a destination by the web update, the api and mcp', function () {
    $googleBusiness = SocialAccount::factory()->googleBusiness()->create(['workspace_id' => $this->workspace->id]);
    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'source_social_account_id' => $this->instagram->id,
    ]);
    $destinations = [['social_account_id' => $googleBusiness->id, 'content_type' => ContentType::GoogleBusinessPost->value]];
    $message = __('repurposes.errors.destination_not_supported');

    $this->actingAs($this->user)
        ->putJson(route('app.repurposes.update', $repurpose), ['destinations' => $destinations])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['destinations.0.social_account_id' => $message]);

    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))
        ->putJson(route('api.repurposes.update', $repurpose), ['destinations' => $destinations])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['destinations.0.social_account_id' => $message]);

    TryPostServer::actingAs($this->user)
        ->tool(UpdateRepurposeTool::class, ['repurpose_id' => $repurpose->id, 'destinations' => $destinations])
        ->assertHasErrors([$message]);

    TryPostServer::actingAs($this->user)
        ->tool(CreateRepurposeTool::class, [
            'source_social_account_id' => SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id])->id,
            'destinations' => $destinations,
        ])
        ->assertHasErrors([$message]);

    expect($repurpose->fresh()->destinations)->toBe([]);
});

test('updating destinations through the api and mcp stores the same destinations', function () {
    $first = Repurpose::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id, 'source_social_account_id' => $this->instagram->id]);
    $secondSource = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    $second = Repurpose::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id, 'source_social_account_id' => $secondSource->id]);
    $destinations = [['social_account_id' => $this->facebook->id, 'content_type' => ContentType::FacebookReel->value]];

    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))
        ->putJson(route('api.repurposes.update', $first), ['destinations' => $destinations])
        ->assertOk();

    TryPostServer::actingAs($this->user)
        ->tool(UpdateRepurposeTool::class, ['repurpose_id' => $second->id, 'destinations' => $destinations])
        ->assertOk();

    $this->actingAs($this->user)
        ->putJson(route('app.repurposes.update', $first), ['publish_mode' => 'draft'])
        ->assertRedirect();

    expect($first->fresh()->destinations)->toEqual($second->fresh()->destinations)
        ->and($first->fresh()->status)->toBe(Status::Draft)
        ->and($second->fresh()->status)->toBe(Status::Draft);
});

test('activate, pause, resume, disable and delete reach the same states through every surface', function () {
    $destinations = [['social_account_id' => $this->facebook->id, 'content_type' => ContentType::FacebookReel->value]];
    $make = fn (SocialAccount $source) => Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'source_social_account_id' => $source->id,
        'destinations' => $destinations,
    ]);
    $sources = SocialAccount::factory()->instagram()->count(2)->create(['workspace_id' => $this->workspace->id]);

    $viaApi = $make($sources[0]);
    $viaMcp = $make($sources[1]);

    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->postJson(route('api.repurposes.activate', $viaApi))->assertOk()->assertJsonPath('status', 'active');
    TryPostServer::actingAs($this->user)->tool(ActivateRepurposeTool::class, ['repurpose_id' => $viaMcp->id])->assertOk();
    expect($viaMcp->fresh()->status)->toBe(Status::Active)->and($viaApi->fresh()->status)->toBe(Status::Active);

    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->postJson(route('api.repurposes.pause', $viaApi))->assertOk()->assertJsonPath('status', 'paused');
    TryPostServer::actingAs($this->user)->tool(PauseRepurposeTool::class, ['repurpose_id' => $viaMcp->id])->assertOk();
    expect($viaMcp->fresh()->status)->toBe(Status::Paused)->and($viaMcp->fresh()->paused_reason)->toBe($viaApi->fresh()->paused_reason);

    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->postJson(route('api.repurposes.resume', $viaApi))->assertOk()->assertJsonPath('status', 'active');
    TryPostServer::actingAs($this->user)->tool(ResumeRepurposeTool::class, ['repurpose_id' => $viaMcp->id])->assertOk();
    expect($viaMcp->fresh()->status)->toBe(Status::Active)->and($viaApi->fresh()->status)->toBe(Status::Active);

    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->postJson(route('api.repurposes.disable', $viaApi))->assertOk()->assertJsonPath('status', 'disabled');
    TryPostServer::actingAs($this->user)->tool(DisableRepurposeTool::class, ['repurpose_id' => $viaMcp->id])->assertOk();
    expect($viaMcp->fresh()->status)->toBe(Status::Disabled)->and($viaApi->fresh()->status)->toBe(Status::Disabled);

    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->deleteJson(route('api.repurposes.destroy', $viaApi))->assertNoContent();
    TryPostServer::actingAs($this->user)->tool(DeleteRepurposeTool::class, ['repurpose_id' => $viaMcp->id])->assertOk();
    expect(Repurpose::query()->count())->toBe(0);
});

test('pausing a draft is refused with the same message on every surface', function () {
    $repurpose = Repurpose::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id, 'source_social_account_id' => $this->instagram->id]);

    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->postJson(route('api.repurposes.pause', $repurpose))->assertUnprocessable();
    TryPostServer::actingAs($this->user)
        ->tool(PauseRepurposeTool::class, ['repurpose_id' => $repurpose->id])
        ->assertHasErrors([__('repurposes.errors.only_active_pauses')]);
    $this->actingAs($this->user)->post(route('app.repurposes.pause', $repurpose))->assertUnprocessable();

    expect($repurpose->fresh()->status)->toBe(Status::Draft);
});

test('a member who needs approval and a foreign workspace repurpose are refused on every surface', function () {
    $approval = workspaceMember($this->workspace, 'approval');
    $repurpose = Repurpose::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id, 'source_social_account_id' => $this->instagram->id]);
    $foreign = Repurpose::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);

    $this->actingAs($approval)->post(route('app.repurposes.activate', $repurpose))->assertForbidden();
    $this->actingAs($approval)->get(route('app.repurposes.index'))->assertForbidden();

    TryPostServer::actingAs($approval)->tool(ListRepurposesTool::class, [])->assertHasErrors(['This action is unauthorized.']);
    TryPostServer::actingAs($approval)->tool(GetRepurposeTool::class, ['repurpose_id' => $repurpose->id])->assertHasErrors(['This action is unauthorized.']);

    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->getJson(route('api.repurposes.show', $foreign))->assertNotFound();
    TryPostServer::actingAs($this->user)->tool(GetRepurposeTool::class, ['repurpose_id' => $foreign->id])->assertHasErrors(['Repurpose not found.']);
    $this->actingAs($this->user)->get(route('app.repurposes.show', $foreign))->assertNotFound();

    expect($repurpose->fresh()->status)->toBe(Status::Draft);
});

test('the source formats are the same list on the api and mcp and match what the web offers for an instagram source', function () {
    $expected = array_map(
        fn (SourceFormat $format): array => ['value' => $format->value, 'label' => $format->label()],
        SourceFormat::forPlatform($this->instagram->platform),
    );

    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))
        ->getJson(route('api.repurpose-source-formats.index'))
        ->assertOk()
        ->assertJsonPath('data', $expected);

    TryPostServer::actingAs($this->user)
        ->tool(ListRepurposeSourceFormatsTool::class, [])
        ->assertStructuredContent(['source_formats' => $expected]);
});

test('the api and mcp list the same repurposes', function () {
    $repurpose = Repurpose::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id, 'source_social_account_id' => $this->instagram->id]);

    auth()->forgetGuards();
    $api = $this->withHeaders(parityApi($this->token))->getJson(route('api.repurposes.index'))->assertOk()->json();
    expect(data_get($api, 'data.0.id'))->toBe($repurpose->id)->and(data_get($api, 'meta.per_page'))->toBe((int) config('app.pagination.default'));

    TryPostServer::actingAs($this->user)
        ->tool(ListRepurposesTool::class, [])
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('repurposes.0.id', $repurpose->id)
            ->where('per_page', (int) config('app.pagination.default'))
            ->etc());
});

test('showing one repurpose returns the same id, status and source on the api and mcp', function () {
    $repurpose = Repurpose::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id, 'source_social_account_id' => $this->instagram->id]);

    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))
        ->getJson(route('api.repurposes.show', $repurpose))
        ->assertOk()
        ->assertJsonPath('id', $repurpose->id)
        ->assertJsonPath('status', 'draft')
        ->assertJsonPath('source_social_account_id', $this->instagram->id);

    TryPostServer::actingAs($this->user)
        ->tool(GetRepurposeTool::class, ['repurpose_id' => $repurpose->id])
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('id', $repurpose->id)
            ->where('status', 'draft')
            ->where('source_social_account_id', $this->instagram->id)
            ->etc());
});

test('the activity items are the same on the api and mcp', function () {
    $repurpose = Repurpose::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id, 'source_social_account_id' => $this->instagram->id]);
    $items = RepurposeItem::factory()->count(2)->create(['repurpose_id' => $repurpose->id]);

    auth()->forgetGuards();
    $api = $this->withHeaders(parityApi($this->token))->getJson(route('api.repurposes.items', $repurpose))->assertOk()->json();

    $mcp = null;
    TryPostServer::actingAs($this->user)
        ->tool(ListRepurposeItemsTool::class, ['repurpose_id' => $repurpose->id])
        ->assertStructuredContent(function (AssertableJson $json) use (&$mcp) {
            $mcp = $json->toArray();

            return $json->where('per_page', (int) config('app.pagination.default'))->etc();
        });

    expect(collect(data_get($api, 'data'))->pluck('id')->sort()->values()->all())
        ->toBe($items->pluck('id')->sort()->values()->all())
        ->and(collect(data_get($mcp, 'items'))->pluck('id')->sort()->values()->all())->toBe($items->pluck('id')->sort()->values()->all())
        ->and(data_get($api, 'meta.per_page'))->toBe(data_get($mcp, 'per_page'));
});

test('a taken source, a source used as destination and a google business destination are refused with the same messages on the api and mcp', function () {
    $googleBusiness = SocialAccount::factory()->googleBusiness()->create(['workspace_id' => $this->workspace->id]);
    Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'source_social_account_id' => $this->instagram->id,
    ]);
    $free = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    $cases = [
        [['source_social_account_id' => $this->instagram->id, 'source_format' => 'reel'], 'source_social_account_id', __('repurposes.errors.source_already_used')],
        [
            ['source_social_account_id' => $free->id, 'destinations' => [['social_account_id' => $free->id, 'content_type' => ContentType::InstagramReel->value]]],
            'destinations.0.social_account_id',
            __('repurposes.errors.destination_is_source'),
        ],
        [
            ['source_social_account_id' => $free->id, 'destinations' => [['social_account_id' => $googleBusiness->id, 'content_type' => ContentType::GoogleBusinessPost->value]]],
            'destinations.0.social_account_id',
            __('repurposes.errors.destination_not_supported'),
        ],
    ];

    foreach ($cases as [$payload, $field, $message]) {
        auth()->forgetGuards();
        $this->withHeaders(parityApi($this->token))
            ->postJson(route('api.repurposes.store'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field => $message]);

        TryPostServer::actingAs($this->user)
            ->tool(CreateRepurposeTool::class, $payload)
            ->assertHasErrors([$message]);
    }

    expect(Repurpose::query()->count())->toBe(1);
});

test('the shared repurpose rules validate from an explicit workspace without a request', function () {
    $other = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);

    expect(fn () => RepurposeRequestRules::validate(['source_social_account_id' => $other->id, 'source_format' => 'reel'], $this->workspace->id))
        ->not->toThrow(ValidationException::class);

    expect(fn () => RepurposeRequestRules::validate(['source_format' => 'reel'], $this->workspace->id))
        ->toThrow(ValidationException::class);
});

test('a reply media url in a repurpose destination is refused by the web update, the api and mcp', function () {
    $x = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::X]);
    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'source_social_account_id' => $this->instagram->id,
    ]);
    $destinations = [[
        'social_account_id' => $x->id,
        'content_type' => ContentType::XPost->value,
        'meta' => ['thread_replies' => [['text' => 'Two', 'media' => [['url' => 'https://example.com/reply.png']]]]],
    ]];
    $field = 'destinations.0.meta.thread_replies.0.media.0.url';

    $this->actingAs($this->user)
        ->putJson(route('app.repurposes.update', $repurpose), ['destinations' => $destinations])
        ->assertJsonValidationErrors([$field]);

    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))
        ->putJson(route('api.repurposes.update', $repurpose), ['destinations' => $destinations])
        ->assertJsonValidationErrors([$field]);

    TryPostServer::actingAs($this->user)
        ->tool(UpdateRepurposeTool::class, ['repurpose_id' => $repurpose->id, 'destinations' => $destinations])
        ->assertHasErrors();

    expect($repurpose->fresh()->destinations)->toBe([]);
});
