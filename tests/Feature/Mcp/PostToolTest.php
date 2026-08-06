<?php

declare(strict_types=1);

use App\Enums\Post\CreatedVia;
use App\Enums\SocialAccount\Platform;
use App\Enums\UserWorkspace\Role;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\CreatePostTool;
use App\Mcp\Tools\Post\DeletePostTool;
use App\Mcp\Tools\Post\GetPostTool;
use App\Mcp\Tools\Post\ListPostsTool;
use App\Mcp\Tools\Post\UpdatePostTool;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $this->socialAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
    ]);
});

test('list posts returns wrapped posts array with PostResource shape', function () {
    Post::factory()->count(3)->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(ListPostsTool::class, []);

    $response->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->has('posts', 3, function (AssertableJson $post) {
                $post->hasAll(['id', 'content', 'media', 'status', 'scheduled_at', 'published_at', 'platforms', 'labels', 'created_at', 'updated_at'])
                    ->missing('user_id')
                    ->missing('workspace_id');
            });
        });
});

test('list posts only returns own workspace posts', function () {
    Post::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);

    $otherWorkspace = Workspace::factory()->create();
    Post::factory()->create(['workspace_id' => $otherWorkspace->id, 'user_id' => $this->user->id]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(ListPostsTool::class, []);

    $response->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->has('posts', 1)->etc();
        });
});

test('get post returns PostResource shape', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'content' => 'Hello world',
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(GetPostTool::class, ['post_id' => $post->id]);

    $response->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) use ($post) {
            $json->where('id', $post->id)
                ->where('content', 'Hello world')
                ->missing('user_id')
                ->missing('workspace_id')
                ->etc();
        });
});

test('get post 404 from another workspace', function () {
    $otherWorkspace = Workspace::factory()->create();
    $post = Post::factory()->create(['workspace_id' => $otherWorkspace->id, 'user_id' => $this->user->id]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(GetPostTool::class, ['post_id' => $post->id]);

    $response->assertHasErrors(['Post not found.']);
});

test('create post with content and date', function () {
    $response = TryPostServer::actingAs($this->user)
        ->tool(CreatePostTool::class, [
            'content' => 'My new post',
            'scheduled_at' => '2099-12-31T15:30:00Z',
        ]);

    $response->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->where('content', 'My new post')
                ->where('status', 'draft')
                ->where('scheduled_at', '2099-12-31 15:30:00')
                ->etc();
        });

    $post = Post::where('workspace_id', $this->workspace->id)->first();
    expect($post)->not->toBeNull();
    expect($post->created_via)->toBe(CreatedVia::Mcp);
});

test('create post creates unscheduled draft without a schedule', function (string $case) {
    $payload = match ($case) {
        'empty' => [],
        'omitted' => [
            'content' => 'Draft without schedule',
            'platforms' => [
                ['social_account_id' => $this->socialAccount->id, 'content_type' => 'linkedin_post'],
            ],
        ],
        'null' => [
            'content' => 'Draft without schedule',
            'platforms' => [
                ['social_account_id' => $this->socialAccount->id, 'content_type' => 'linkedin_post'],
            ],
            'scheduled_at' => null,
        ],
    };

    TryPostServer::actingAs($this->user)
        ->tool(CreatePostTool::class, $payload)
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('status', 'draft')
            ->where('scheduled_at', null)
            ->etc());

    expect(Post::where('workspace_id', $this->workspace->id)
        ->latest('created_at')
        ->firstOrFail()
        ->scheduled_at)->toBeNull();
})->with([
    'empty args' => ['empty'],
    'omitted schedule' => ['omitted'],
    'explicit null schedule' => ['null'],
]);

test('create post with platforms enables only those', function () {
    $response = TryPostServer::actingAs($this->user)
        ->tool(CreatePostTool::class, [
            'content' => 'with platforms',
            'platforms' => [
                ['social_account_id' => $this->socialAccount->id, 'content_type' => 'linkedin_post'],
            ],
        ]);

    $response->assertOk();

    $post = Post::where('workspace_id', $this->workspace->id)->first();
    $enabled = $post->postPlatforms()->where('enabled', true)->get();
    expect($enabled)->toHaveCount(1);
    expect($enabled->first()->social_account_id)->toBe($this->socialAccount->id);
    expect($enabled->first()->content_type->value)->toBe('linkedin_post');
});

test('create post rejects scheduled_at in the past', function () {
    $response = TryPostServer::actingAs($this->user)
        ->tool(CreatePostTool::class, ['scheduled_at' => '2020-01-01T00:00:00Z']);

    $response->assertHasErrors();
});

test('create post rejects an inactive social account', function () {
    $inactive = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
        'is_active' => false,
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(CreatePostTool::class, [
            'platforms' => [
                ['social_account_id' => $inactive->id, 'content_type' => 'linkedin_post'],
            ],
        ]);

    $response->assertHasErrors();
});

test('create post rejects a content_type not in the enum', function () {
    $response = TryPostServer::actingAs($this->user)
        ->tool(CreatePostTool::class, [
            'platforms' => [
                ['social_account_id' => $this->socialAccount->id, 'content_type' => 'made_up_type'],
            ],
        ]);

    $response->assertHasErrors();
});

test('create post rejects instagram_carousel — carousel is not a stored content_type', function () {
    $response = TryPostServer::actingAs($this->user)
        ->tool(CreatePostTool::class, [
            'platforms' => [
                ['social_account_id' => $this->socialAccount->id, 'content_type' => 'instagram_carousel'],
            ],
        ]);

    $response->assertHasErrors();
});

test('update post rejects instagram_carousel — carousel is not a stored content_type', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
    $platform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(UpdatePostTool::class, [
            'post_id' => $post->id,
            'platforms' => [
                ['id' => $platform->id, 'content_type' => 'instagram_carousel'],
            ],
        ]);

    $response->assertHasErrors();
});

test('create post rejects a content_type that does not match the social account platform', function () {
    // x_post on a LinkedIn account — ContentTypeMatchesPlatform should reject.
    $response = TryPostServer::actingAs($this->user)
        ->tool(CreatePostTool::class, [
            'platforms' => [
                ['social_account_id' => $this->socialAccount->id, 'content_type' => 'x_post'],
            ],
        ]);

    $response->assertHasErrors();
});

test('create post rejects a label_id from another workspace', function () {
    $otherWorkspace = Workspace::factory()->create();
    $foreignLabel = WorkspaceLabel::factory()->create(['workspace_id' => $otherWorkspace->id]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(CreatePostTool::class, [
            'platforms' => [
                ['social_account_id' => $this->socialAccount->id, 'content_type' => 'linkedin_post'],
            ],
            'label_ids' => [$foreignLabel->id],
        ]);

    $response->assertHasErrors();
});

test('delete post removes from db', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(DeletePostTool::class, ['post_id' => $post->id]);

    $response->assertOk()
        ->assertStructuredContent(['deleted' => true]);

    expect(Post::find($post->id))->toBeNull();
});

test('delete post 404 from another workspace', function () {
    $otherWorkspace = Workspace::factory()->create();
    $post = Post::factory()->create(['workspace_id' => $otherWorkspace->id, 'user_id' => $this->user->id]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(DeletePostTool::class, ['post_id' => $post->id]);

    $response->assertHasErrors(['Post not found.']);
});

test('get post validates post_id required', function () {
    $response = TryPostServer::actingAs($this->user)
        ->tool(GetPostTool::class, []);

    $response->assertHasErrors();
});

test('delete post validates post_id required', function () {
    $response = TryPostServer::actingAs($this->user)
        ->tool(DeletePostTool::class, []);

    $response->assertHasErrors();
});

test('create post persists platform meta (aspect_ratio)', function () {
    $response = TryPostServer::actingAs($this->user)
        ->tool(CreatePostTool::class, [
            'platforms' => [
                ['social_account_id' => $this->socialAccount->id, 'content_type' => 'linkedin_post', 'meta' => ['aspect_ratio' => '4:5']],
            ],
        ]);

    $response->assertOk();

    $platform = Post::where('workspace_id', $this->workspace->id)->first()
        ->postPlatforms()->where('social_account_id', $this->socialAccount->id)->first();
    expect($platform->meta['aspect_ratio'])->toBe('4:5');
});

test('create post rejects an invalid aspect_ratio', function () {
    $response = TryPostServer::actingAs($this->user)
        ->tool(CreatePostTool::class, [
            'platforms' => [
                ['social_account_id' => $this->socialAccount->id, 'content_type' => 'linkedin_post', 'meta' => ['aspect_ratio' => '3:2']],
            ],
        ]);

    $response->assertHasErrors();
});

test('update post rejects an invalid aspect_ratio', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
    $platform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(UpdatePostTool::class, [
            'post_id' => $post->id,
            'platforms' => [
                ['id' => $platform->id, 'meta' => ['aspect_ratio' => '3:2']],
            ],
        ]);

    $response->assertHasErrors();
});

test('create post returns the platform meta in the response (read-back)', function () {
    TryPostServer::actingAs($this->user)
        ->tool(CreatePostTool::class, [
            'platforms' => [
                ['social_account_id' => $this->socialAccount->id, 'content_type' => 'linkedin_post', 'meta' => ['aspect_ratio' => '4:5']],
            ],
        ])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('platforms.0.meta.aspect_ratio', '4:5')->etc());
});

test('update post rejects scheduled status without a future scheduled_at', function (?string $existingScheduledAt) {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'scheduled_at' => $existingScheduledAt,
    ]);

    TryPostServer::actingAs($this->user)
        ->tool(UpdatePostTool::class, [
            'post_id' => $post->id,
            'status' => 'scheduled',
        ])
        ->assertHasErrors();

    TryPostServer::actingAs($this->user)
        ->tool(UpdatePostTool::class, [
            'post_id' => $post->id,
            'status' => 'scheduled',
            'scheduled_at' => now()->subHour()->toIso8601String(),
        ])
        ->assertHasErrors();

    expect($post->fresh()->status->value)->toBe('draft');
})->with([
    'missing schedule' => [null],
    'past schedule' => [now()->subDay()->toDateTimeString()],
]);

test('update post accepts scheduled status reusing an existing future scheduled_at', function () {
    $scheduledAt = now()->addDay()->startOfSecond();
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'scheduled_at' => $scheduledAt,
    ]);

    TryPostServer::actingAs($this->user)
        ->tool(UpdatePostTool::class, [
            'post_id' => $post->id,
            'status' => 'scheduled',
        ])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('status', 'scheduled')
            ->etc());

    expect($post->fresh()->scheduled_at->toDateTimeString())->toBe($scheduledAt->toDateTimeString());
});

test('update post schedules an unscheduled draft with an explicit future scheduled_at', function () {
    $scheduledAt = now()->addDay()->startOfSecond();
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'scheduled_at' => null,
    ]);

    TryPostServer::actingAs($this->user)
        ->tool(UpdatePostTool::class, [
            'post_id' => $post->id,
            'status' => 'scheduled',
            'scheduled_at' => $scheduledAt->toIso8601String(),
        ])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('status', 'scheduled')
            ->etc());

    expect($post->fresh()->scheduled_at->toDateTimeString())->toBe($scheduledAt->toDateTimeString());
});

test('update post keeps an unscheduled draft when saving as draft without scheduled_at', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'scheduled_at' => null,
        'content' => 'Original',
    ]);

    TryPostServer::actingAs($this->user)
        ->tool(UpdatePostTool::class, [
            'post_id' => $post->id,
            'status' => 'draft',
            'content' => 'Still a draft',
        ])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('status', 'draft')
            ->where('scheduled_at', null)
            ->etc());

    expect($post->fresh()->scheduled_at)->toBeNull()
        ->and($post->fresh()->content)->toBe('Still a draft');
});

test('update post accepts a valid aspect_ratio and persists it', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
    $platform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
    ]);

    TryPostServer::actingAs($this->user)
        ->tool(UpdatePostTool::class, [
            'post_id' => $post->id,
            'platforms' => [
                ['id' => $platform->id, 'meta' => ['aspect_ratio' => '16:9']],
            ],
        ])
        ->assertOk();

    expect($platform->fresh()->meta['aspect_ratio'])->toBe('16:9');
});

test('viewers can list and get posts via mcp', function () {
    $viewer = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($viewer->id, ['role' => Role::Viewer->value]);
    $viewer->update(['current_workspace_id' => $this->workspace->id]);

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'content' => 'Visible to viewers',
    ]);

    TryPostServer::actingAs($viewer)
        ->tool(ListPostsTool::class, [])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->has('posts', 1)->etc();
        });

    TryPostServer::actingAs($viewer)
        ->tool(GetPostTool::class, ['post_id' => $post->id])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) use ($post) {
            $json->where('id', $post->id)
                ->where('content', 'Visible to viewers')
                ->etc();
        });
});

test('viewers cannot create update or delete posts via mcp', function () {
    $viewer = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($viewer->id, ['role' => Role::Viewer->value]);
    $viewer->update(['current_workspace_id' => $this->workspace->id]);

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'content' => 'Protected',
    ]);

    TryPostServer::actingAs($viewer)
        ->tool(CreatePostTool::class, ['content' => 'Nope'])
        ->assertHasErrors(['Not authorized to create posts.']);

    TryPostServer::actingAs($viewer)
        ->tool(UpdatePostTool::class, [
            'post_id' => $post->id,
            'content' => 'Changed',
        ])
        ->assertHasErrors(['Not authorized to update this post.']);

    TryPostServer::actingAs($viewer)
        ->tool(DeletePostTool::class, ['post_id' => $post->id])
        ->assertHasErrors(['Not authorized to delete this post.']);

    expect($post->fresh()->content)->toBe('Protected');
});
