<?php

declare(strict_types=1);

use App\Enums\Post\CreatedVia;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Jobs\PublishPost;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use Illuminate\Support\Facades\Bus;

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
            'platforms' => [
                [
                    'social_account_id' => $this->socialAccount->id,
                    'content_type' => 'linkedin_post',
                ],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('status', PostStatus::Draft->value)
        ->assertJsonPath('scheduled_at', null);

    $post = Post::where('workspace_id', $this->workspace->id)->first();
    expect($post)->not->toBeNull();
    expect($post->created_via)->toBe(CreatedVia::Api);
    expect($post->scheduled_at)->toBeNull();
});

it('ignores a client-supplied created_via and always records api', function () {
    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.store'), [
            'created_via' => 'web',
            'platforms' => [
                [
                    'social_account_id' => $this->socialAccount->id,
                    'content_type' => 'linkedin_post',
                ],
            ],
        ])
        ->assertCreated();

    $post = Post::where('workspace_id', $this->workspace->id)->first();
    expect($post->created_via)->toBe(CreatedVia::Api);
});

it('creates a post with content, media, and labels', function () {
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);

    $payload = [
        'content' => 'Hello from the API',
        'media' => [['id' => 'media-1', 'path' => 'media/foo.jpg', 'url' => 'https://example.com/foo.jpg', 'type' => 'image']],
        'platforms' => [
            ['social_account_id' => $this->socialAccount->id, 'content_type' => 'linkedin_post'],
        ],
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

it('rejects creating a post with an inactive social account', function () {
    $inactive = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
        'is_active' => false,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.store'), [
            'platforms' => [
                ['social_account_id' => $inactive->id, 'content_type' => 'linkedin_post'],
            ],
        ])
        ->assertJsonValidationErrors(['platforms.0.social_account_id']);
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
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);

    $postPlatform = PostPlatform::factory()->linkedin()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
        'enabled' => true,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->putJson(route('api.posts.update', $post), [
            'status' => 'draft',
            'platforms' => [
                [
                    'id' => $postPlatform->id,
                    'content_type' => ContentType::LinkedInPost->value,
                ],
            ],
        ])
        ->assertOk();
});

it('rejects creating a post with instagram_carousel — carousel is not a stored content_type', function () {
    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.store'), [
            'platforms' => [
                ['social_account_id' => $this->socialAccount->id, 'content_type' => 'instagram_carousel'],
            ],
        ])
        ->assertJsonValidationErrors(['platforms.0.content_type']);
});

it('rejects updating a post with instagram_carousel — carousel is not a stored content_type', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);

    $postPlatform = PostPlatform::factory()->linkedin()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
        'enabled' => true,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->putJson(route('api.posts.update', $post), [
            'status' => 'draft',
            'platforms' => [
                ['id' => $postPlatform->id, 'content_type' => 'instagram_carousel'],
            ],
        ])
        ->assertJsonValidationErrors(['platforms.0.content_type']);
});

it('cannot update post from another workspace', function () {
    $otherWorkspace = Workspace::factory()->create();
    $otherSocialAccount = SocialAccount::factory()->create([
        'workspace_id' => $otherWorkspace->id,
        'platform' => Platform::LinkedIn,
    ]);
    $post = Post::factory()->create([
        'workspace_id' => $otherWorkspace->id,
        'user_id' => $this->user->id,
    ]);
    $postPlatform = PostPlatform::factory()->linkedin()->create([
        'post_id' => $post->id,
        'social_account_id' => $otherSocialAccount->id,
        'enabled' => true,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->putJson(route('api.posts.update', $post), [
            'status' => 'draft',
            'platforms' => [
                [
                    'id' => $postPlatform->id,
                    'content_type' => ContentType::LinkedInPost->value,
                ],
            ],
        ])
        ->assertNotFound();
});

it('cannot update post in any terminal state', function (PostStatus $status) {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => $status,
    ]);

    $postPlatform = PostPlatform::factory()->linkedin()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
        'enabled' => true,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->putJson(route('api.posts.update', $post), [
            'status' => 'draft',
            'platforms' => [
                [
                    'id' => $postPlatform->id,
                    'content_type' => ContentType::LinkedInPost->value,
                ],
            ],
        ])
        ->assertUnprocessable();
})->with([
    PostStatus::Published,
    PostStatus::PartiallyPublished,
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
            'platforms' => [
                ['content' => 'missing id'],
            ],
        ])
        ->assertUnprocessable();
});

it('validates post creation requires platforms', function () {
    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.store'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['platforms']);
});

it('validates post creation platform fields', function () {
    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.store'), [
            'platforms' => [
                ['content' => 'missing social_account_id and content_type'],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['platforms.0.social_account_id', 'platforms.0.content_type']);
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

it('rejects creating a post with content_type not in the enum', function () {
    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.store'), [
            'platforms' => [
                ['social_account_id' => $this->socialAccount->id, 'content_type' => 'made_up_type'],
            ],
        ])
        ->assertJsonValidationErrors(['platforms.0.content_type']);
});

it('rejects scheduling an over-limit threads post via the api store', function () {
    $threadsAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Threads,
    ]);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.store'), [
            'content' => str_repeat('a', 537),
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'platforms' => [
                ['social_account_id' => $threadsAccount->id, 'content_type' => ContentType::ThreadsPost->value],
            ],
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
            'platforms' => [
                ['social_account_id' => $threadsAccount->id, 'content_type' => ContentType::ThreadsPost->value],
            ],
        ])
        ->assertCreated();
});

it('rejects scheduling an over-limit threads post via the api update', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
    $threadsAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Threads,
    ]);
    $threadsPlatform = PostPlatform::factory()->threads()->create([
        'post_id' => $post->id,
        'social_account_id' => $threadsAccount->id,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->putJson(route('api.posts.update', $post), [
            'status' => PostStatus::Scheduled->value,
            'content' => str_repeat('a', 600),
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'platforms' => [
                ['id' => $threadsPlatform->id, 'content_type' => ContentType::ThreadsPost->value],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['content']);
});

it('saving an over-limit threads post as draft via api skips the content-length check', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
    $threadsAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Threads,
    ]);
    $threadsPlatform = PostPlatform::factory()->threads()->create([
        'post_id' => $post->id,
        'social_account_id' => $threadsAccount->id,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->putJson(route('api.posts.update', $post), [
            'status' => PostStatus::Draft->value,
            'content' => str_repeat('a', 1000),
            'platforms' => [
                ['id' => $threadsPlatform->id, 'content_type' => ContentType::ThreadsPost->value],
            ],
        ])
        ->assertSuccessful();
});

it('rejects creating a post when content_type does not match the social account platform', function () {
    // x_post on a LinkedIn account — ContentTypeMatchesPlatform should reject.
    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.store'), [
            'platforms' => [
                ['social_account_id' => $this->socialAccount->id, 'content_type' => 'x_post'],
            ],
        ])
        ->assertJsonValidationErrors(['platforms.0.content_type']);
});

it('rejects creating a post with a label from another workspace', function () {
    $otherWorkspace = Workspace::factory()->create();
    $foreignLabel = WorkspaceLabel::factory()->create(['workspace_id' => $otherWorkspace->id]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.store'), [
            'platforms' => [
                ['social_account_id' => $this->socialAccount->id, 'content_type' => 'linkedin_post'],
            ],
            'label_ids' => [$foreignLabel->id],
        ])
        ->assertJsonValidationErrors(['label_ids.0']);
});

it('rejects updating a post with a platforms[].id that belongs to another post', function () {
    $myPost = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);

    $otherPost = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);
    $foreignPlatform = PostPlatform::factory()->linkedin()->create([
        'post_id' => $otherPost->id,
        'social_account_id' => $this->socialAccount->id,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->putJson(route('api.posts.update', $myPost), [
            'status' => 'draft',
            'platforms' => [
                ['id' => $foreignPlatform->id, 'content_type' => ContentType::LinkedInPost->value],
            ],
        ])
        ->assertJsonValidationErrors(['platforms.0.id']);
});

it('rejects updating a post when content_type does not match the post_platform', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);
    $postPlatform = PostPlatform::factory()->linkedin()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
        'enabled' => true,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->putJson(route('api.posts.update', $post), [
            'status' => 'draft',
            'platforms' => [
                ['id' => $postPlatform->id, 'content_type' => 'x_post'],
            ],
        ])
        ->assertJsonValidationErrors(['platforms.0.content_type']);
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
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
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
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
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

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'scheduled_at' => null,
    ]);

    PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
        'enabled' => true,
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
            'platforms' => [
                ['social_account_id' => $this->socialAccount->id, 'content_type' => 'linkedin_post'],
            ],
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

it('creates a post with platform meta (aspect_ratio) and returns it', function () {
    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.store'), [
            'platforms' => [
                ['social_account_id' => $this->socialAccount->id, 'content_type' => 'linkedin_post', 'meta' => ['aspect_ratio' => '4:5']],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('platforms.0.meta.aspect_ratio', '4:5');

    $platform = Post::where('workspace_id', $this->workspace->id)->first()
        ->postPlatforms()->where('social_account_id', $this->socialAccount->id)->first();
    expect($platform->meta['aspect_ratio'])->toBe('4:5');
});

it('rejects creating a post with an invalid aspect_ratio', function () {
    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.store'), [
            'platforms' => [
                ['social_account_id' => $this->socialAccount->id, 'content_type' => 'linkedin_post', 'meta' => ['aspect_ratio' => '3:2']],
            ],
        ])
        ->assertJsonValidationErrors(['platforms.0.meta.aspect_ratio']);
});

it('rejects updating a post with an invalid aspect_ratio', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);
    $postPlatform = PostPlatform::factory()->linkedin()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
        'enabled' => true,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->putJson(route('api.posts.update', $post), [
            'status' => 'draft',
            'platforms' => [
                ['id' => $postPlatform->id, 'content_type' => 'linkedin_post', 'meta' => ['aspect_ratio' => '3:2']],
            ],
        ])
        ->assertJsonValidationErrors(['platforms.0.meta.aspect_ratio']);
});

it('accepts a valid aspect_ratio on update and persists it', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);
    $postPlatform = PostPlatform::factory()->linkedin()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
        'enabled' => true,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->putJson(route('api.posts.update', $post), [
            'status' => 'draft',
            'platforms' => [
                ['id' => $postPlatform->id, 'content_type' => 'linkedin_post', 'meta' => ['aspect_ratio' => '16:9']],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('platforms.0.meta.aspect_ratio', '16:9');

    expect($postPlatform->fresh()->meta['aspect_ratio'])->toBe('16:9');
});

it('accepts the original aspect_ratio (no crop) on create', function () {
    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.store'), [
            'platforms' => [
                ['social_account_id' => $this->socialAccount->id, 'content_type' => 'linkedin_post', 'meta' => ['aspect_ratio' => 'original']],
            ],
        ])
        ->assertCreated();

    $platform = Post::where('workspace_id', $this->workspace->id)->first()
        ->postPlatforms()->where('social_account_id', $this->socialAccount->id)->first();
    expect($platform->meta['aspect_ratio'])->toBe('original');
});
