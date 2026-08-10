<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\UserWorkspace\Role;
use App\Jobs\Ai\StreamPostCreation;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AiPromptRules;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

test('start requires authentication', function () {
    $this->postJson(route('app.posts.ai.create'), ['prompt' => 'hello', 'format' => 'x_post', 'creation_id' => Str::uuid()->toString()])
        ->assertStatus(Response::HTTP_UNAUTHORIZED);
});

test('start validates prompt is required', function () {
    Bus::fake();

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.create'), ['format' => 'x_post', 'creation_id' => Str::uuid()->toString()])
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonValidationErrors(['prompt']);
});

test('start requires a valid creation_id', function (mixed $creationId) {
    Bus::fake();

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.create'), [
            'prompt' => 'hello',
            'format' => 'x_post',
            'creation_id' => $creationId,
        ])
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonValidationErrors(['creation_id']);

    Bus::assertNotDispatched(StreamPostCreation::class);
})->with([
    'missing' => [null],
    'not a uuid' => ['not-a-uuid'],
]);

test('start rejects a prompt shorter than the minimum length', function () {
    Bus::fake();

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.create'), ['prompt' => 'hi', 'format' => 'x_post', 'creation_id' => Str::uuid()->toString()])
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonValidationErrors(['prompt']);

    Bus::assertNotDispatched(StreamPostCreation::class);
});

test('start accepts a prompt at the minimum length', function () {
    Bus::fake();

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.create'), [
            'prompt' => str_repeat('a', AiPromptRules::PROMPT_MIN_LENGTH),
            'format' => 'x_post',
            'creation_id' => Str::uuid()->toString(),
        ])
        ->assertStatus(Response::HTTP_ACCEPTED);

    Bus::assertDispatched(StreamPostCreation::class);
});

test('start rejects a prompt longer than the maximum length', function () {
    Bus::fake();

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.create'), [
            'prompt' => str_repeat('a', AiPromptRules::PROMPT_MAX_LENGTH + 1),
            'format' => 'x_post',
            'creation_id' => Str::uuid()->toString(),
        ])
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonValidationErrors(['prompt']);

    Bus::assertNotDispatched(StreamPostCreation::class);
});

test('start accepts a prompt at the maximum length', function () {
    Bus::fake();

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.create'), [
            'prompt' => str_repeat('a', AiPromptRules::PROMPT_MAX_LENGTH),
            'format' => 'x_post',
            'creation_id' => Str::uuid()->toString(),
        ])
        ->assertStatus(Response::HTTP_ACCEPTED);

    Bus::assertDispatched(StreamPostCreation::class);
});

test('start validates format is required', function () {
    Bus::fake();

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.create'), ['prompt' => 'hello', 'creation_id' => Str::uuid()->toString()])
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonValidationErrors(['format']);
});

test('start validates format must be a known value', function () {
    Bus::fake();

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.create'), ['prompt' => 'hello', 'format' => 'tiktok_video', 'creation_id' => Str::uuid()->toString()])
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonValidationErrors(['format']);
});

test('start accepts instagram_carousel as a generation format', function () {
    Bus::fake();

    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
    ]);

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.create'), [
            'prompt' => 'Five tips about productivity',
            'format' => 'instagram_carousel',
            'social_account_id' => $account->id,
            'image_count' => 5,
            'creation_id' => Str::uuid()->toString(),
        ])
        ->assertStatus(Response::HTTP_ACCEPTED);

    Bus::assertDispatched(StreamPostCreation::class, fn ($job) => $job->format === 'instagram_carousel');
});

test('start rejects social_account_id from another workspace', function () {
    Bus::fake();

    $otherWorkspace = Workspace::factory()->create();
    $foreignAccount = SocialAccount::factory()->create([
        'workspace_id' => $otherWorkspace->id,
        'platform' => Platform::X,
    ]);

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.create'), [
            'prompt' => 'hello',
            'format' => 'x_post',
            'social_account_id' => $foreignAccount->id,
            'creation_id' => Str::uuid()->toString(),
        ])
        ->assertStatus(Response::HTTP_FORBIDDEN);
});

test('start dispatches StreamPostCreation using the client-supplied creation id', function () {
    Bus::fake();

    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::X,
    ]);

    $creationId = Str::uuid()->toString();

    $response = $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.create'), [
            'prompt' => 'Write a post about productivity',
            'format' => 'x_post',
            'social_account_id' => $account->id,
            'image_count' => 2,
            'creation_id' => $creationId,
        ])
        ->assertStatus(Response::HTTP_ACCEPTED);

    expect($response->json('creation_id'))->toBe($creationId);
    expect($response->json('channel'))->toBe("user.{$this->user->id}.ai-creation.{$creationId}");

    Bus::assertDispatched(StreamPostCreation::class, function ($job) use ($creationId, $account) {
        return $job->userId === $this->user->id
            && $job->creationId === $creationId
            && $job->workspaceId === $this->workspace->id
            && $job->format === 'x_post'
            && $job->socialAccountId === $account->id
            && $job->imageCount === 2
            && $job->prompt === 'Write a post about productivity';
    });
});

test('start does not dispatch StreamPostCreation twice for the same creation_id', function () {
    Bus::fake();

    $creationId = Str::uuid()->toString();
    $payload = [
        'prompt' => 'Write a post about productivity',
        'format' => 'x_post',
        'creation_id' => $creationId,
    ];

    $this->actingAs($this->user)->postJson(route('app.posts.ai.create'), $payload)
        ->assertStatus(Response::HTTP_ACCEPTED);

    $this->actingAs($this->user)->postJson(route('app.posts.ai.create'), $payload)
        ->assertStatus(Response::HTTP_ACCEPTED);

    Bus::assertDispatchedTimes(StreamPostCreation::class, 1);
});

test('start dispatches for two different users sharing the same creation_id', function () {
    Bus::fake();

    $otherUser = User::factory()->create();
    $otherWorkspace = Workspace::factory()->create(['user_id' => $otherUser->id]);
    $otherWorkspace->members()->attach($otherUser->id, ['role' => Role::Member->value]);
    $otherUser->update(['current_workspace_id' => $otherWorkspace->id]);

    $creationId = Str::uuid()->toString();
    $payload = [
        'prompt' => 'Write a post about productivity',
        'format' => 'x_post',
        'creation_id' => $creationId,
    ];

    $this->actingAs($this->user)->postJson(route('app.posts.ai.create'), $payload)
        ->assertStatus(Response::HTTP_ACCEPTED);

    $this->actingAs($otherUser)->postJson(route('app.posts.ai.create'), $payload)
        ->assertStatus(Response::HTTP_ACCEPTED);

    Bus::assertDispatchedTimes(StreamPostCreation::class, 2);
});

test('start works without social_account_id', function () {
    Bus::fake();

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.create'), [
            'prompt' => 'Write a LinkedIn post',
            'format' => 'linkedin_post',
            'creation_id' => Str::uuid()->toString(),
        ])
        ->assertStatus(Response::HTTP_ACCEPTED)
        ->assertJsonStructure(['creation_id', 'channel']);

    Bus::assertDispatched(StreamPostCreation::class, fn ($job) => is_null($job->socialAccountId));
});

test('start dispatches the job carrying the date param when provided', function () {
    Bus::fake();

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.create'), [
            'prompt' => 'hello',
            'format' => 'x_post',
            'date' => '2026-06-15',
            'creation_id' => Str::uuid()->toString(),
        ])
        ->assertAccepted();

    Bus::assertDispatched(StreamPostCreation::class, fn ($job) => $job->date === '2026-06-15');
});

test('start carries apply_brand_visuals=false when the user lets the AI decide colors', function () {
    Bus::fake();

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.create'), [
            'prompt' => 'hello',
            'format' => 'x_post',
            'apply_brand_visuals' => false,
            'creation_id' => Str::uuid()->toString(),
        ])
        ->assertAccepted();

    Bus::assertDispatched(StreamPostCreation::class, fn ($job) => $job->applyBrandVisuals === false);
});

test('start defaults apply_brand_visuals to true when omitted', function () {
    Bus::fake();

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.create'), [
            'prompt' => 'hello',
            'format' => 'x_post',
            'creation_id' => Str::uuid()->toString(),
        ])
        ->assertAccepted();

    Bus::assertDispatched(StreamPostCreation::class, fn ($job) => $job->applyBrandVisuals === true);
});

test('start rejects invalid date format', function () {
    Bus::fake();

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.create'), [
            'prompt' => 'hello',
            'format' => 'x_post',
            'date' => 'not-a-date',
            'creation_id' => Str::uuid()->toString(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['date']);

    Bus::assertNotDispatched(StreamPostCreation::class);
});

test('loading page requires authentication', function () {
    $this->get(route('app.posts.ai.loading', '019e0532-7b74-7369-b238-a5f2a93d12b7'))
        ->assertStatus(Response::HTTP_FOUND);
});

test('loading page renders the Inertia component with channel and query context', function () {
    $creationId = '019e0532-7b74-7369-b238-a5f2a93d12b7';

    $this->actingAs($this->user)
        ->get(route('app.posts.ai.loading', $creationId).'?images=5&format=instagram_carousel&prompt=Hello&template=tweet_card&apply_brand_visuals=0')
        ->assertInertia(fn ($page) => $page
            ->component('posts/ai/Loading')
            ->where('creationId', $creationId)
            ->where('channel', "user.{$this->user->id}.ai-creation.{$creationId}")
            ->where('imageCount', 5)
            ->where('format', 'instagram_carousel')
            ->where('prompt', 'Hello')
            ->where('template', 'tweet_card')
            ->where('applyBrandVisuals', false)
        );
});

test('loading page defaults template and applyBrandVisuals when omitted', function () {
    $creationId = '019e0532-7b74-7369-b238-a5f2a93d12b7';

    $this->actingAs($this->user)
        ->get(route('app.posts.ai.loading', $creationId))
        ->assertInertia(fn ($page) => $page
            ->where('template', 'image_card')
            ->where('applyBrandVisuals', true)
            ->where('socialAccountId', null)
            ->where('date', null)
        );
});

test('loading page rejects non-uuid creation ids', function () {
    $this->actingAs($this->user)
        ->get(route('app.posts.ai.loading', 'not-a-uuid'))
        ->assertStatus(Response::HTTP_NOT_FOUND);
});

it('defaults to the image_card template when none is given', function () {
    Bus::fake();

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.create'), [
            'format' => 'instagram_feed',
            'prompt' => 'a post about coffee',
            'creation_id' => Str::uuid()->toString(),
        ])
        ->assertAccepted();

    Bus::assertDispatched(StreamPostCreation::class, fn ($job) => $job->template === 'image_card');
});

it('rejects an unknown template', function () {
    Bus::fake();

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.create'), [
            'format' => 'instagram_feed',
            'prompt' => 'x',
            'template' => 'bogus',
            'creation_id' => Str::uuid()->toString(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['template']);
});

it('allows image_card without a social account', function () {
    Bus::fake();

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.create'), [
            'format' => 'instagram_feed',
            'prompt' => 'a post about coffee',
            'template' => 'image_card',
            'creation_id' => Str::uuid()->toString(),
        ])
        ->assertAccepted();
});

it('requires social_account_id when the template needsAccount', function () {
    Bus::fake();

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.create'), [
            'format' => 'x_post',
            'prompt' => 'a punchy take on productivity',
            'template' => 'tweet_card',
            'creation_id' => Str::uuid()->toString(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['social_account_id']);

    Bus::assertNotDispatched(StreamPostCreation::class);
});
