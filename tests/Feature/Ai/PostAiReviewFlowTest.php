<?php

declare(strict_types=1);

use App\Ai\Agents\PostContentGenerator;
use App\Ai\Agents\PostContentHumanizer;
use App\Enums\Ai\DraftStatus;
use App\Enums\SocialAccount\Platform;
use App\Enums\UserWorkspace\Role;
use App\Jobs\Ai\PreparePostContent;
use App\Jobs\Ai\StreamPostCreation;
use App\Models\AiPostDraft;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Bus;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

test('prepare requires authentication', function () {
    $this->postJson(route('app.posts.ai.drafts.prepare'), ['prompt' => 'x', 'format' => 'x_post'])
        ->assertStatus(Response::HTTP_UNAUTHORIZED);
});

test('prepare creates a draft and dispatches PreparePostContent', function () {
    Bus::fake();

    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.drafts.prepare'), [
            'prompt' => 'Five tips about productivity',
            'format' => 'instagram_carousel',
            'social_account_id' => $account->id,
            'image_count' => 3,
        ])
        ->assertStatus(Response::HTTP_ACCEPTED)
        ->assertJsonStructure(['draft_id', 'channel']);

    $draftId = $response->json('draft_id');
    $draft = AiPostDraft::find($draftId);

    expect($draft)->not->toBeNull();
    expect($draft->status)->toBe(DraftStatus::Preparing);
    expect($draft->workspace_id)->toBe($this->workspace->id);
    expect($draft->image_count)->toBe(3);

    Bus::assertDispatched(PreparePostContent::class, fn ($job) => $job->draftId === $draftId);
});

test('prepare rejects social_account_id from another workspace', function () {
    Bus::fake();

    $other = Workspace::factory()->create();
    $foreign = SocialAccount::factory()->create([
        'workspace_id' => $other->id,
        'platform' => Platform::X,
    ]);

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.drafts.prepare'), [
            'prompt' => 'hello',
            'format' => 'x_post',
            'social_account_id' => $foreign->id,
        ])
        ->assertStatus(Response::HTTP_FORBIDDEN);
});

test('review renders the review page for the own draft', function () {
    $draft = AiPostDraft::factory()->ready(['caption' => 'c', 'slides' => []])->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $this->actingAs($this->user)
        ->get(route('app.posts.ai.drafts.review', $draft->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('posts/ai/Review')->where('draft.id', $draft->id));
});

test('review forbids another users draft', function () {
    $other = Workspace::factory()->create();
    $draft = AiPostDraft::factory()->create(['workspace_id' => $other->id]);

    $this->actingAs($this->user)
        ->get(route('app.posts.ai.drafts.review', $draft->id))
        ->assertForbidden();
});

test('generate saves the reviewed structure and dispatches StreamPostCreation with the prepared structure', function () {
    Bus::fake();

    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
    ]);

    $draft = AiPostDraft::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'social_account_id' => $account->id,
        'format' => 'instagram_carousel',
        'image_count' => 2,
        'status' => DraftStatus::Ready,
    ]);

    $structured = [
        'caption' => 'edited caption',
        'slides' => [
            ['role' => 'hook', 'title' => 'A', 'body' => 'B', 'image_keywords' => ['x']],
        ],
    ];

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.drafts.generate', $draft->id), ['structured' => $structured])
        ->assertStatus(Response::HTTP_ACCEPTED)
        ->assertJson(['creation_id' => $draft->id]);

    $draft->refresh();
    expect($draft->status)->toBe(DraftStatus::Generating);
    expect($draft->structured)->toBe($structured);

    Bus::assertDispatched(
        StreamPostCreation::class,
        fn ($job) => $job->draftId === $draft->id
            && $job->creationId === $draft->id
            && $job->preparedStructured === $structured,
    );
});

test('generate rejects a draft that is not ready (replay guard)', function () {
    Bus::fake();

    $draft = AiPostDraft::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => DraftStatus::Generating,
    ]);

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.drafts.generate', $draft->id), ['structured' => ['caption' => 'x']])
        ->assertStatus(Response::HTTP_CONFLICT);

    Bus::assertNotDispatched(StreamPostCreation::class);
});

test('generate forbids another workspace draft', function () {
    Bus::fake();

    $other = Workspace::factory()->create();
    $draft = AiPostDraft::factory()->create(['workspace_id' => $other->id]);

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.drafts.generate', $draft->id), ['structured' => ['caption' => 'x']])
        ->assertForbidden();
});

test('autosave persists the structure while the draft is ready', function () {
    $draft = AiPostDraft::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => DraftStatus::Ready,
    ]);

    $structured = ['caption' => 'auto-saved', 'slides' => [['title' => 'x']]];

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.drafts.autosave', $draft->id), ['structured' => $structured])
        ->assertNoContent();

    expect($draft->fresh()->structured)->toBe($structured);
});

test('autosave is a no-op once the draft left the ready state', function () {
    $draft = AiPostDraft::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => DraftStatus::Generating,
        'structured' => ['caption' => 'original'],
    ]);

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.drafts.autosave', $draft->id), ['structured' => ['caption' => 'changed']])
        ->assertNoContent();

    expect($draft->fresh()->structured)->toBe(['caption' => 'original']);
});

test('autosave forbids another workspace draft', function () {
    $other = Workspace::factory()->create();
    $draft = AiPostDraft::factory()->create(['workspace_id' => $other->id, 'status' => DraftStatus::Ready]);

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.drafts.autosave', $draft->id), ['structured' => ['caption' => 'x']])
        ->assertForbidden();
});

test('generate validates structured is required', function () {
    Bus::fake();

    $draft = AiPostDraft::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.drafts.generate', $draft->id), [])
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonValidationErrors(['structured']);
});

test('PreparePostContent composes the text and marks the draft ready, preserving untouched fields', function () {
    PostContentGenerator::fake([[
        'caption' => 'Swipe to see',
        'slides' => [
            ['role' => 'hook', 'title' => 'T', 'body' => 'B', 'image_keywords' => ['sunrise', 'desk']],
        ],
    ]]);
    PostContentHumanizer::fake([[
        'caption' => 'Swipe to see',
        'slides' => [
            ['title' => 'T polished', 'body' => 'B polished'],
        ],
    ]]);

    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
    ]);

    $draft = AiPostDraft::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'social_account_id' => $account->id,
        'format' => 'instagram_carousel',
        'image_count' => 1,
        'status' => DraftStatus::Preparing,
    ]);

    (new PreparePostContent($draft->id))->handle();

    $draft->refresh();

    expect($draft->status)->toBe(DraftStatus::Ready);
    expect($draft->structured['caption'])->toBe('Swipe to see');
    // The humanizer only returns title/body; role and image_keywords survive the merge.
    expect($draft->structured['slides'][0]['title'])->toBe('T polished');
    expect($draft->structured['slides'][0]['role'])->toBe('hook');
    expect($draft->structured['slides'][0]['image_keywords'])->toBe(['sunrise', 'desk']);
});
