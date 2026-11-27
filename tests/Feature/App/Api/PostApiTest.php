<?php

declare(strict_types=1);

use App\Enums\Post\CreatedVia;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Jobs\PublishPost;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $result = createApiTestToken();
    $this->user = $result['user'];
    $this->workspace = $result['workspace'];
    $this->plainToken = $result['plain_token'];

    $this->socialAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
    ]);
});

it('lists posts', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->getJson(route('api.posts.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('shows a post', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->getJson(route('api.posts.show', $post))
        ->assertOk()
        ->assertJsonPath('id', $post->id);
});

it('cannot show post from another workspace', function () {
    $otherWorkspace = Workspace::factory()->create();
    $post = Post::factory()->create([
        'workspace_id' => $otherWorkspace->id,
        'user_id' => $this->user->id,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->getJson(route('api.posts.show', $post))
        ->assertNotFound();
});

it('creates a post', function () {
    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.store'), [
            'social_account_id' => $this->socialAccount->id,
            'content_type' => 'linkedin_post',
        ])
        ->assertCreated()
        ->assertJsonPath('status', PostStatus::Draft->value)
        ->assertJsonPath('scheduled_at', null);

    $post = Post::where('workspace_id', $this->workspace->id)->first();
    expect($post)->not->toBeNull();
    expect($post->created_via)->toBe(CreatedVia::Api);
    expect($post->scheduled_at)->toBeNull();
    expect($post->social_account_id)->toBe($this->socialAccount->id)
        ->and($post->content_type)->toBe(ContentType::LinkedInPost);
});

it('rejects the removed platforms list on the single post endpoint', function () {
    $secondAccount = SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.store'), [
            'platforms' => [
                ['social_account_id' => $this->socialAccount->id, 'content_type' => ContentType::LinkedInPost->value],
                ['social_account_id' => $secondAccount->id, 'content_type' => ContentType::LinkedInPost->value],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['platforms' => __('validation.prohibited', ['attribute' => 'platforms'])]);

    expect(Post::query()->where('workspace_id', $this->workspace->id)->count())->toBe(0);
});

it('creates an ordered batch of independent posts', function () {
    $secondAccount = SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.batch.store'), [
            'status' => 'draft',
            'content' => 'Base',
            'destinations' => [
                ['social_account_id' => $secondAccount->id, 'content_type' => ContentType::LinkedInPost->value, 'content' => 'Segundo primeiro'],
                ['social_account_id' => $this->socialAccount->id, 'content_type' => ContentType::LinkedInPost->value],
            ],
        ])
        ->assertCreated()
        ->assertJsonCount(2, 'posts');

    $firstPost = Post::findOrFail($response->json('posts.0.id'));
    $secondPost = Post::findOrFail($response->json('posts.1.id'));
    expect($firstPost->content)->toBe('Segundo primeiro')
        ->and($firstPost->social_account_id)->toBe($secondAccount->id)
        ->and($secondPost->content)->toBe('Base')
        ->and($secondPost->social_account_id)->toBe($this->socialAccount->id);
});

it('rejects the whole batch when a destination belongs to another workspace', function () {
    $foreignWorkspace = Workspace::factory()->create();
    $foreignAccount = SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $foreignWorkspace->id,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.batch.store'), [
            'status' => 'draft',
            'destinations' => [
                ['social_account_id' => $this->socialAccount->id, 'content_type' => ContentType::LinkedInPost->value],
                ['social_account_id' => $foreignAccount->id, 'content_type' => ContentType::LinkedInPost->value],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('destinations.1.social_account_id');

    expect(Post::query()->where('workspace_id', $this->workspace->id)->count())->toBe(0);
});

it('rejects malformed hosted media identifiers with a validation response', function () {
    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.batch.store'), [
            'status' => 'draft',
            'media' => [['id' => 'not-a-uuid']],
            'destinations' => [
                ['social_account_id' => $this->socialAccount->id, 'content_type' => ContentType::LinkedInPost->value],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('media.0.id');
});

it('ignores a client-supplied created_via and always records api', function () {
    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.store'), [
            'created_via' => 'web',
            'social_account_id' => $this->socialAccount->id,
            'content_type' => 'linkedin_post',
        ])
        ->assertCreated();

    $post = Post::where('workspace_id', $this->workspace->id)->first();
    expect($post->created_via)->toBe(CreatedVia::Api);
});

it('creates a post with content, media, and labels', function () {
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    Storage::fake();
    Http::fake([
        '93.184.216.34/photo.png' => Http::response(
            file_get_contents(base_path('tests/fixtures/1x1.png')),
            200,
            ['Content-Type' => 'image/png'],
        ),
    ]);

    $payload = [
        'content' => 'Hello from the API',
        'media' => [['url' => 'https://93.184.216.34/photo.png']],
        'social_account_id' => $this->socialAccount->id,
        'content_type' => 'linkedin_post',
        'label_ids' => [$label->id],
    ];

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.store'), $payload)
        ->assertCreated();

    $post = Post::where('workspace_id', $this->workspace->id)->first();

    expect($post->content)->toBe('Hello from the API');
    expect($post->media)->toHaveCount(1);
    expect($post->labels()->pluck('workspace_labels.id')->all())->toContain($label->id);

    $response->assertJsonPath('content', 'Hello from the API');
});

it('deletes a post', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->deleteJson(route('api.posts.destroy', $post))
        ->assertNoContent();

    expect(Post::find($post->id))->toBeNull();
});

it('cannot delete post from another workspace', function () {
    $otherWorkspace = Workspace::factory()->create();
    $post = Post::factory()->create([
        'workspace_id' => $otherWorkspace->id,
        'user_id' => $this->user->id,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->deleteJson(route('api.posts.destroy', $post))
        ->assertNotFound();
});

it('updates a post', function () {
    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->putJson(route('api.posts.update', $post), [
            'status' => 'draft',
            'content_type' => ContentType::LinkedInPost->value,
        ])
        ->assertOk();
});

it('keeps the account fixed when updating through the API', function () {
    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);
    $otherAccount = SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->putJson(route('api.posts.update', $post), [
            'status' => 'draft',
            'social_account_id' => $otherAccount->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('social_account_id');

    expect($post->fresh()->social_account_id)->toBe($this->socialAccount->id);
});

it('rejects creating a post with instagram_carousel — carousel is not a stored content_type', function () {
    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.store'), [
            'social_account_id' => $this->socialAccount->id,
            'content_type' => 'instagram_carousel',
        ])
        ->assertJsonValidationErrors(['content_type']);
});

it('rejects updating a post with instagram_carousel — carousel is not a stored content_type', function () {
    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->putJson(route('api.posts.update', $post), [
            'status' => 'draft',
            'content_type' => 'instagram_carousel',
        ])
        ->assertJsonValidationErrors(['content_type']);
});

it('cannot update post from another workspace', function () {
    $otherWorkspace = Workspace::factory()->create();
    $otherSocialAccount = SocialAccount::factory()->create([
        'workspace_id' => $otherWorkspace->id,
        'platform' => Platform::LinkedIn,
    ]);
    $post = Post::factory()->forAccount($otherSocialAccount)->create([
        'user_id' => $this->user->id,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->putJson(route('api.posts.update', $post), [
            'status' => 'draft',
            'content_type' => ContentType::LinkedInPost->value,
        ])
        ->assertNotFound();
});

it('cannot update post in any terminal state', function (PostStatus $status) {
    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'user_id' => $this->user->id,
        'status' => $status,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->putJson(route('api.posts.update', $post), [
            'status' => 'draft',
            'content_type' => ContentType::LinkedInPost->value,
        ])
        ->assertUnprocessable();
})->with([
    PostStatus::Published,
    PostStatus::Failed,
    PostStatus::Publishing,
]);

it('validates post update fields', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->putJson(route('api.posts.update', $post), [
            'platforms' => [['content' => 'old shape']],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['platforms']);
});

it('validates post creation requires a social account', function () {
    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.store'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['social_account_id']);
});

it('validates post creation platform fields', function () {
    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.store'), [
            'content' => 'missing social_account_id',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['social_account_id'])
        ->assertJsonMissingValidationErrors(['content_type']);
});

it('validates post update invalid status', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->putJson(route('api.posts.update', $post), [
            'status' => 'invalid_status',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);
});

it('validates post update scheduled_at must be date', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->putJson(route('api.posts.update', $post), [
            'scheduled_at' => 'not-a-date',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['scheduled_at']);
});

it('validates post update label_ids must be uuids', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->putJson(route('api.posts.update', $post), [
            'label_ids' => ['not-a-uuid'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['label_ids.0']);
});

it('rejects a deleted label on post update', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    $label->delete();

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->putJson(route('api.posts.update', $post), [
            'label_ids' => [$label->id],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['label_ids.0']);
});

it('rejects creating a post with content_type not in the enum', function () {
    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.store'), [
            'social_account_id' => $this->socialAccount->id,
            'content_type' => 'made_up_type',
        ])
        ->assertJsonValidationErrors(['content_type']);
});

it('rejects scheduling an over-limit threads post via the api store', function () {
    $threadsAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Threads,
    ]);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.store'), [
            'content' => str_repeat('a', 537),
            'status' => 'scheduled',
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'social_account_id' => $threadsAccount->id,
            'content_type' => ContentType::ThreadsPost->value,
        ]);

    $response->assertUnprocessable()->assertJsonValidationErrors(['content']);
    expect($response->json('errors.content.0'))
        ->toContain('Threads')
        ->toContain('500')
        ->toContain('37');
});

it('accepts creating an over-limit draft (no scheduled_at) via the api store', function () {
    $threadsAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Threads,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.store'), [
            'content' => str_repeat('a', 1000),
            'social_account_id' => $threadsAccount->id,
            'content_type' => ContentType::ThreadsPost->value,
        ])
        ->assertCreated();
});

it('rejects scheduling an over-limit threads post via the api update', function () {
    $threadsAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Threads,
    ]);
    $post = Post::factory()->forAccount($threadsAccount)->create([
        'user_id' => $this->user->id,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->putJson(route('api.posts.update', $post), [
            'status' => PostStatus::Scheduled->value,
            'content' => str_repeat('a', 600),
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'content_type' => ContentType::ThreadsPost->value,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['content']);
});

it('saving an over-limit threads post as draft via api skips the content-length check', function () {
    $threadsAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Threads,
    ]);
    $post = Post::factory()->forAccount($threadsAccount)->create([
        'user_id' => $this->user->id,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->putJson(route('api.posts.update', $post), [
            'status' => PostStatus::Draft->value,
            'content' => str_repeat('a', 1000),
            'content_type' => ContentType::ThreadsPost->value,
        ])
        ->assertSuccessful();
});

it('rejects creating a post when content_type does not match the social account platform', function () {
    // x_post on a LinkedIn account — ContentTypeMatchesPlatform should reject.
    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.store'), [
            'social_account_id' => $this->socialAccount->id,
            'content_type' => 'x_post',
        ])
        ->assertJsonValidationErrors(['content_type']);
});

it('rejects creating a post with a label from another workspace', function () {
    $otherWorkspace = Workspace::factory()->create();
    $foreignLabel = WorkspaceLabel::factory()->create(['workspace_id' => $otherWorkspace->id]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.store'), [
            'social_account_id' => $this->socialAccount->id,
            'content_type' => 'linkedin_post',
            'label_ids' => [$foreignLabel->id],
        ])
        ->assertJsonValidationErrors(['label_ids.0']);
});

it('rejects updating a post when content_type does not match the post channel', function () {
    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->putJson(route('api.posts.update', $post), [
            'status' => 'draft',
            'content_type' => 'x_post',
        ])
        ->assertJsonValidationErrors(['content_type']);
});

it('rejects scheduled status without a future scheduled_at', function (?string $existingScheduledAt) {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'scheduled_at' => $existingScheduledAt,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->putJson(route('api.posts.update', $post), [
            'status' => 'scheduled',
        ])
        ->assertJsonValidationErrors(['scheduled_at']);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->putJson(route('api.posts.update', $post), [
            'status' => 'scheduled',
            'scheduled_at' => now()->subHour()->toIso8601String(),
        ])
        ->assertJsonValidationErrors(['scheduled_at']);
})->with([
    'missing schedule' => [null],
    'past schedule' => [now()->subDay()->toDateTimeString()],
]);

it('accepts scheduled status reusing an existing future scheduled_at', function () {
    $scheduledAt = now()->addDay()->startOfSecond();
    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'user_id' => $this->user->id,
        'content' => 'Ready',
        'status' => PostStatus::Draft,
        'scheduled_at' => $scheduledAt,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->putJson(route('api.posts.update', $post), [
            'status' => 'scheduled',
        ])
        ->assertOk()
        ->assertJsonPath('status', PostStatus::Scheduled->value);

    expect($post->fresh()->scheduled_at->toDateTimeString())->toBe($scheduledAt->toDateTimeString());
});

it('schedules an unscheduled draft with an explicit future scheduled_at', function () {
    $scheduledAt = now()->addDay()->startOfSecond();
    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'user_id' => $this->user->id,
        'content' => 'Ready',
        'status' => PostStatus::Draft,
        'scheduled_at' => null,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->putJson(route('api.posts.update', $post), [
            'status' => 'scheduled',
            'scheduled_at' => $scheduledAt->toIso8601String(),
        ])
        ->assertOk()
        ->assertJsonPath('status', PostStatus::Scheduled->value);

    expect($post->fresh()->scheduled_at->toDateTimeString())->toBe($scheduledAt->toDateTimeString());
});

it('accepts draft status with no scheduled_at', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'scheduled_at' => null,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->putJson(route('api.posts.update', $post), [
            'status' => 'draft',
        ])
        ->assertOk()
        ->assertJsonPath('scheduled_at', null);

    expect($post->fresh()->scheduled_at)->toBeNull();
});

it('publishes an unscheduled draft without requiring scheduled_at', function () {
    Bus::fake();
    $this->freezeTime();

    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'scheduled_at' => null,
        'content' => 'Ready to publish',
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->putJson(route('api.posts.update', $post), [
            'status' => 'publishing',
        ])
        ->assertOk()
        ->assertJsonPath('status', PostStatus::Publishing->value);

    expect($post->fresh()->scheduled_at->toDateTimeString())->toBe(now()->toDateTimeString());
    Bus::assertDispatched(PublishPost::class);
});

it('rejects creating a post with a past scheduled_at', function () {
    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.store'), [
            'social_account_id' => $this->socialAccount->id,
            'content_type' => 'linkedin_post',
            'scheduled_at' => now()->subDay()->toIso8601String(),
        ])
        ->assertJsonValidationErrors(['scheduled_at']);
});

it('list posts returns correct structure', function () {
    Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->getJson(route('api.posts.index'))
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'status', 'scheduled_at', 'published_at', 'created_at', 'updated_at'],
            ],
        ]);
});

it('show post returns correct structure', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->getJson(route('api.posts.show', $post))
        ->assertOk()
        ->assertJsonStructure(['id', 'status', 'scheduled_at', 'published_at']);
});

it('creates a post with platform meta (link_preview) and returns it', function () {
    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.store'), [
            'social_account_id' => $this->socialAccount->id,
            'content_type' => 'linkedin_post',
            'meta' => ['link_preview' => false],
        ])
        ->assertCreated()
        ->assertJsonPath('meta.link_preview', false);

    $post = Post::where('workspace_id', $this->workspace->id)->sole();
    expect($post->meta['link_preview'])->toBeFalse();
});

it('rejects creating a post with an invalid link_preview', function () {
    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.store'), [
            'social_account_id' => $this->socialAccount->id,
            'content_type' => 'linkedin_post',
            'meta' => ['link_preview' => 'nope'],
        ])
        ->assertJsonValidationErrors(['meta.link_preview']);
});

it('rejects updating a post with an invalid link_preview', function () {
    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->putJson(route('api.posts.update', $post), [
            'status' => 'draft',
            'content_type' => 'linkedin_post',
            'meta' => ['link_preview' => 'nope'],
        ])
        ->assertJsonValidationErrors(['meta.link_preview']);
});

it('accepts a valid platform meta on update and persists it', function () {
    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->putJson(route('api.posts.update', $post), [
            'status' => 'draft',
            'content_type' => 'linkedin_post',
            'meta' => ['link_preview' => false],
        ])
        ->assertOk()
        ->assertJsonPath('meta.link_preview', false);

    expect($post->fresh()->meta['link_preview'])->toBeFalse();
});

it('no longer stores an aspect_ratio meta on create', function (string $ratio) {
    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.store'), [
            'social_account_id' => $this->socialAccount->id,
            'content_type' => 'linkedin_post',
            'meta' => ['aspect_ratio' => $ratio],
        ])
        ->assertCreated()
        ->assertJsonMissingPath('meta.aspect_ratio');

    $post = Post::where('workspace_id', $this->workspace->id)->sole();
    expect(data_get($post->meta, 'aspect_ratio'))->toBeNull();
})->with(['4:5', 'original', '3:2']);

it('shows the origin of imported and trypost posts', function () {
    $imported = Post::factory()->imported()->create(['workspace_id' => $this->workspace->id]);
    $ours = Post::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->getJson(route('api.posts.show', $imported))
        ->assertOk()
        ->assertJsonPath('origin', 'network')
        ->assertJsonPath('status', 'published');

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->getJson(route('api.posts.show', $ours))
        ->assertJsonPath('origin', 'trypost');
});

it('orders the post list the same way on every page with null and tied scheduled_at', function () {
    config(['app.pagination.default' => 2]);
    $tied = now()->addDays(3)->startOfSecond();

    $make = fn (?CarbonInterface $scheduledAt, CarbonInterface $createdAt) => Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'scheduled_at' => $scheduledAt,
        'created_at' => $createdAt,
    ]);

    $draftOld = $make(null, now()->subDays(5));
    $draftNew = $make(null, now()->subDays(1));
    $tiedOld = $make($tied, now()->subDays(4));
    $tiedNew = $make($tied, now()->subDays(2));
    $later = $make($tied->copy()->addDay(), now()->subDays(6));

    $expected = [$draftNew->id, $draftOld->id, $later->id, $tiedNew->id, $tiedOld->id];

    $ids = [];
    foreach ([1, 2, 3] as $page) {
        $ids = array_merge($ids, collect($this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
            ->getJson(route('api.posts.index', ['page' => $page]))
            ->assertOk()
            ->json('data'))->pluck('id')->all());
    }

    expect($ids)->toBe($expected);
});

it('edits a draft whose kept time has passed', function () {
    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'scheduled_at' => now()->subDay(),
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->putJson(route('api.posts.update', $post), ['content' => 'New text'])
        ->assertOk();

    expect($post->fresh()->content)->toBe('New text')
        ->and($post->fresh()->status)->toBe(PostStatus::Draft);
});
