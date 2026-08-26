<?php

declare(strict_types=1);

use App\Enums\Post\CreatedVia;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\PostPlatform\Status;
use App\Enums\SocialAccount\Platform;
use App\Enums\UserWorkspace\Role;
use App\Jobs\PublishPost;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->user = User::factory()->create([]);
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $this->socialAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
    ]);
});

// Index tests
test('posts index requires authentication', function () {
    $response = $this->get(route('app.posts.index'));

    $response->assertRedirect(route('login'));
});

test('posts index shows posts for current workspace', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)->get(route('app.posts.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('posts/Index', false)
        ->has('posts.data', 1)
    );
});

test('posts index exposes workspace labels for filter dropdown', function () {
    WorkspaceLabel::factory()->count(3)->create(['workspace_id' => $this->workspace->id]);
    WorkspaceLabel::factory()->create(); // belongs to a different workspace; must not leak.

    $response = $this->actingAs($this->user)->get(route('app.posts.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('labels', 3)
        ->where('filters.labels', [])
    );
});

test('posts index filters posts by a single label id', function () {
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);

    $taggedPost = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
    $taggedPost->labels()->attach($label);

    Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)
        ->get(route('app.posts.index', ['labels' => [$label->id]]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('posts.data', 1)
        ->where('posts.data.0.id', $taggedPost->id)
        ->where('filters.labels', [$label->id])
    );
});

test('posts index filters posts by multiple labels (OR semantics)', function () {
    $marketing = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    $sales = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    $unrelated = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);

    $postWithMarketing = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
    $postWithMarketing->labels()->attach($marketing);

    $postWithSales = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
    $postWithSales->labels()->attach($sales);

    $postWithUnrelated = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
    $postWithUnrelated->labels()->attach($unrelated);

    $response = $this->actingAs($this->user)
        ->get(route('app.posts.index', ['labels' => [$marketing->id, $sales->id]]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('posts.data', 2)
        ->where('filters.labels', [$marketing->id, $sales->id])
    );
});

test('posts index ignores blank label query params', function () {
    Post::factory()->count(2)->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)
        ->get(route('app.posts.index', ['labels' => ['']]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('posts.data', 2)
        ->where('filters.labels', [])
    );
});

test('posts index redirects to create workspace if no workspace', function () {
    $this->user->update(['current_workspace_id' => null]);

    $response = $this->actingAs($this->user)->get(route('app.posts.index'));

    $response->assertRedirect(route('app.workspaces.create'));
});

// Calendar tests
test('calendar requires authentication', function () {
    $response = $this->get(route('app.calendar'));

    $response->assertRedirect(route('login'));
});

test('calendar shows posts for current week', function () {
    $response = $this->actingAs($this->user)->get(route('app.calendar'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('posts/Calendar')
        ->has('workspace')
        ->has('posts')
        ->has('currentWeekStart')
        ->has('view')
    );
});

test('calendar supports month view', function () {
    $response = $this->actingAs($this->user)->get(route('app.calendar', ['view' => 'month']));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('view', 'month')
    );
});

test('calendar payload exposes post content for rendering', function () {
    $scheduledAt = now('UTC')->startOfWeek()->addDays(2)->setTime(12, 0);

    Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'content' => 'Caption visible in the calendar',
        'status' => PostStatus::Scheduled,
        'scheduled_at' => $scheduledAt,
    ]);

    $dateKey = $scheduledAt->format('Y-m-d');

    $response = $this->actingAs($this->user)->get(route('app.calendar'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where("posts.{$dateKey}.0.content", 'Caption visible in the calendar')
    );
});

test('calendar does not include unscheduled drafts', function () {
    $scheduledAt = now('UTC')->startOfWeek()->addDays(2)->setTime(12, 0);

    Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'content' => 'Unscheduled draft stays off the calendar',
        'status' => PostStatus::Draft,
        'scheduled_at' => null,
    ]);

    Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'content' => 'Scheduled post appears on the calendar',
        'status' => PostStatus::Scheduled,
        'scheduled_at' => $scheduledAt,
    ]);

    $dateKey = $scheduledAt->format('Y-m-d');

    $this->actingAs($this->user)
        ->get(route('app.calendar'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has("posts.{$dateKey}", 1)
            ->where("posts.{$dateKey}.0.content", 'Scheduled post appears on the calendar')
        );
});

// Create tests
test('create requires authentication', function () {
    $response = $this->get(route('app.posts.create'));

    $response->assertRedirect(route('login'));
});

test('create renders the wizard page', function () {
    $response = $this->actingAs($this->user)->get(route('app.posts.create'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('posts/Create', false)
        ->where('date', null)
        ->has('socialAccounts', 1)
        ->where('socialAccounts.0.id', $this->socialAccount->id)
    );
});

test('create forwards date query param to the page', function () {
    $response = $this->actingAs($this->user)->get(route('app.posts.create', ['date' => '2026-06-01']));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('posts/Create', false)
        ->where('date', '2026-06-01')
    );
});

test('create redirects to workspaces.create when user has no workspace', function () {
    $newUser = User::factory()->create();

    $response = $this->actingAs($newUser)->get(route('app.posts.create'));

    $response->assertRedirect(route('app.workspaces.create'));
});

// Store tests
test('store post requires authentication', function () {
    $response = $this->post(route('app.posts.store'));

    $response->assertRedirect(route('login'));
});

test('store post redirects to accounts if no social accounts connected', function () {
    $this->socialAccount->delete();

    $response = $this->actingAs($this->user)->post(route('app.posts.store'));

    $response->assertRedirect(route('app.accounts'));
});

test('store post creates draft and redirects to edit', function () {
    $response = $this->actingAs($this->user)->post(route('app.posts.store'));

    $response->assertRedirect();

    $post = Post::where('workspace_id', $this->workspace->id)->first();
    expect($post)->not->toBeNull();
    expect($post->status)->toBe(PostStatus::Draft);
    expect($post->created_via)->toBe(CreatedVia::Web);
    expect($post->postPlatforms)->toHaveCount(1);
});

test('store post leaves scheduled_at null when no date is provided', function () {
    $this->actingAs($this->user)->post(route('app.posts.store'))->assertRedirect();

    $post = Post::where('workspace_id', $this->workspace->id)->first();
    expect($post->scheduled_at)->toBeNull();
});

test('store post schedules draft on the date param when provided', function () {
    $this->actingAs($this->user)->post(route('app.posts.store'), [
        'date' => '2026-06-15',
    ])->assertRedirect();

    $post = Post::where('workspace_id', $this->workspace->id)->first();
    expect($post->scheduled_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-06-15 09:00:00');
});

test('store post rejects invalid date format', function () {
    $this->actingAs($this->user)
        ->post(route('app.posts.store'), ['date' => 'not-a-date'])
        ->assertSessionHasErrors(['date']);

    expect(Post::where('workspace_id', $this->workspace->id)->count())->toBe(0);
});

// Edit tests
test('edit post requires authentication', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $response = $this->get(route('app.posts.edit', $post));

    $response->assertRedirect(route('login'));
});

test('edit post shows edit page', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);

    PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
    ]);

    $response = $this->actingAs($this->user)->get(route('app.posts.edit', $post));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('posts/Edit')
        ->has('post')
        ->has('socialAccounts')
    );
});

test('edit exposes null scheduled_at for an unscheduled draft', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'scheduled_at' => null,
    ]);

    PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
    ]);

    $this->actingAs($this->user)
        ->get(route('app.posts.edit', $post))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('posts/Edit')
            ->where('post.scheduled_at', null)
        );
});

test('edit post returns 404 for post from different workspace', function () {
    $otherWorkspace = Workspace::factory()->create();
    $post = Post::factory()->create([
        'workspace_id' => $otherWorkspace->id,
        'user_id' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)->get(route('app.posts.edit', $post));

    $response->assertNotFound();
});

test('edit redirects to show for non-editable statuses', function () {
    foreach ([PostStatus::Published, PostStatus::PartiallyPublished, PostStatus::Publishing, PostStatus::Failed] as $status) {
        $post = Post::factory()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->user->id,
            'status' => $status,
        ]);

        PostPlatform::factory()->create([
            'post_id' => $post->id,
            'social_account_id' => $this->socialAccount->id,
        ]);

        $this->actingAs($this->user)
            ->get(route('app.posts.edit', $post))
            ->assertRedirect(route('app.posts.show', $post));
    }
});

test('edit allows draft and scheduled posts', function () {
    foreach ([PostStatus::Draft, PostStatus::Scheduled] as $status) {
        $post = Post::factory()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->user->id,
            'status' => $status,
        ]);

        PostPlatform::factory()->create([
            'post_id' => $post->id,
            'social_account_id' => $this->socialAccount->id,
        ]);

        $this->actingAs($this->user)
            ->get(route('app.posts.edit', $post))
            ->assertOk();
    }
});

// Update tests
test('update post requires authentication', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $response = $this->put(route('app.posts.update', $post), []);

    $response->assertRedirect(route('login'));
});

test('update post saves changes', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'content' => 'Original content',
    ]);

    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
    ]);

    $response = $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'draft',
        'content' => 'Updated content',
        'platforms' => [
            [
                'id' => $postPlatform->id,
                'content_type' => ContentType::LinkedInPost->value,
            ],
        ],
    ]);

    $response->assertRedirect();

    $post->refresh();
    expect($post->content)->toBe('Updated content');
    $postPlatform->refresh();
    expect($postPlatform->content_type)->toBe(ContentType::LinkedInPost);
});

test('update post cannot update published posts', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Published,
    ]);

    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
    ]);

    $response = $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'draft',
        'content' => 'Test content',
        'platforms' => [
            [
                'id' => $postPlatform->id,
                'content_type' => ContentType::LinkedInPost->value,
            ],
        ],
    ]);

    $response->assertRedirect();
});

test('cannot re-publish a failed post', function () {
    Bus::fake();

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Failed,
    ]);

    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
    ]);

    $response = $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'publishing',
        'content' => 'Test content',
        'platforms' => [
            [
                'id' => $postPlatform->id,
                'content_type' => ContentType::LinkedInPost->value,
            ],
        ],
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('flash.bannerStyle', 'danger');

    $post->refresh();
    expect($post->status)->toBe(PostStatus::Failed);
    Bus::assertNotDispatched(PublishPost::class);
});

test('cannot update a post in publishing state', function () {
    Bus::fake();

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Publishing,
    ]);

    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
    ]);

    $response = $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'draft',
        'content' => 'Test content',
        'platforms' => [
            [
                'id' => $postPlatform->id,
                'content_type' => ContentType::LinkedInPost->value,
            ],
        ],
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('flash.bannerStyle', 'danger');

    $post->refresh();
    expect($post->status)->toBe(PostStatus::Publishing);
    Bus::assertNotDispatched(PublishPost::class);
});

test('cannot update a partially published post', function () {
    Bus::fake();

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::PartiallyPublished,
    ]);

    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
    ]);

    $response = $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'publishing',
        'content' => 'Test content',
        'platforms' => [
            [
                'id' => $postPlatform->id,
                'content_type' => ContentType::LinkedInPost->value,
            ],
        ],
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('flash.bannerStyle', 'danger');

    $post->refresh();
    expect($post->status)->toBe(PostStatus::PartiallyPublished);
    Bus::assertNotDispatched(PublishPost::class);
});

test('cannot update a published post', function () {
    Bus::fake();

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Published,
    ]);

    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
    ]);

    $response = $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'publishing',
        'content' => 'Test content',
        'platforms' => [
            [
                'id' => $postPlatform->id,
                'content_type' => ContentType::LinkedInPost->value,
            ],
        ],
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('flash.bannerStyle', 'danger');

    $post->refresh();
    expect($post->status)->toBe(PostStatus::Published);
    Bus::assertNotDispatched(PublishPost::class);
});

test('publish now updates scheduled_at to current time', function () {
    Mail::fake();
    $this->freezeTime();

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'content' => 'Test content',
        'scheduled_at' => now()->addDays(7),
    ]);

    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
    ]);

    $response = $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'publishing',
        'content' => 'Test content',
        'platforms' => [
            [
                'id' => $postPlatform->id,
                'content_type' => ContentType::LinkedInPost->value,
            ],
        ],
    ]);

    $response->assertRedirect();

    $post->refresh();
    expect($post->scheduled_at->toDateTimeString())->toBe(now()->toDateTimeString());
});

test('publish now is allowed when the draft has no scheduled_at', function () {
    Bus::fake();
    $this->freezeTime();

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'content' => 'Test content',
        'scheduled_at' => null,
    ]);

    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
    ]);

    $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'publishing',
        'content' => 'Test content',
        'platforms' => [
            [
                'id' => $postPlatform->id,
                'content_type' => ContentType::LinkedInPost->value,
            ],
        ],
    ])->assertRedirect();

    $post->refresh();
    expect($post->status)->toBe(PostStatus::Publishing)
        ->and($post->scheduled_at->toDateTimeString())->toBe(now()->toDateTimeString());
    Bus::assertDispatched(PublishPost::class);
});

test('update rejects scheduled status without a future scheduled_at', function (?string $existingScheduledAt) {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'scheduled_at' => $existingScheduledAt,
        'content' => 'Test content',
    ]);

    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
    ]);

    $payload = [
        'status' => 'scheduled',
        'content' => 'Test content',
        'platforms' => [
            [
                'id' => $postPlatform->id,
                'content_type' => ContentType::LinkedInPost->value,
            ],
        ],
    ];

    $this->actingAs($this->user)
        ->put(route('app.posts.update', $post), $payload)
        ->assertSessionHasErrors('scheduled_at');

    $this->actingAs($this->user)
        ->put(route('app.posts.update', $post), [
            ...$payload,
            'scheduled_at' => now()->subHour()->toIso8601String(),
        ])
        ->assertSessionHasErrors('scheduled_at');

    expect($post->fresh()->status)->toBe(PostStatus::Draft);
})->with([
    'missing schedule' => [null],
    'past schedule' => [now()->subDay()->toDateTimeString()],
]);

test('update accepts scheduled status reusing an existing future scheduled_at', function () {
    $scheduledAt = now()->addDay()->startOfSecond();

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'scheduled_at' => $scheduledAt,
        'content' => 'Test content',
    ]);

    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
    ]);

    $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'scheduled',
        'content' => 'Test content',
        'platforms' => [
            [
                'id' => $postPlatform->id,
                'content_type' => ContentType::LinkedInPost->value,
            ],
        ],
    ])->assertRedirect();

    $post->refresh();
    expect($post->status)->toBe(PostStatus::Scheduled)
        ->and($post->scheduled_at->toDateTimeString())->toBe($scheduledAt->toDateTimeString());
});

test('update schedules an unscheduled draft with an explicit future scheduled_at', function () {
    $scheduledAt = now()->addDay()->startOfSecond();

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'scheduled_at' => null,
        'content' => 'Test content',
    ]);

    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
    ]);

    $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'scheduled',
        'scheduled_at' => $scheduledAt->toIso8601String(),
        'content' => 'Test content',
        'platforms' => [
            [
                'id' => $postPlatform->id,
                'content_type' => ContentType::LinkedInPost->value,
            ],
        ],
    ])->assertRedirect();

    $post->refresh();
    expect($post->status)->toBe(PostStatus::Scheduled)
        ->and($post->scheduled_at->toDateTimeString())->toBe($scheduledAt->toDateTimeString());
});

test('update keeps an unscheduled draft when saving as draft without scheduled_at', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'scheduled_at' => null,
        'content' => 'Original',
    ]);

    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
    ]);

    $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'draft',
        'content' => 'Still a draft',
        'platforms' => [
            [
                'id' => $postPlatform->id,
                'content_type' => ContentType::LinkedInPost->value,
            ],
        ],
    ])->assertRedirect();

    $post->refresh();
    expect($post->status)->toBe(PostStatus::Draft)
        ->and($post->scheduled_at)->toBeNull()
        ->and($post->content)->toBe('Still a draft');
});

// Destroy tests
test('destroy post requires authentication', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $response = $this->delete(route('app.posts.destroy', $post));

    $response->assertRedirect(route('login'));
});

test('destroy post deletes the post and redirects to posts index', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)
        ->delete(route('app.posts.destroy', $post));

    $response->assertRedirect(route('app.posts.index'));
    expect(Post::find($post->id))->toBeNull();
});

test('destroy post with redirect param redirects to calendar', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)
        ->delete(route('app.posts.destroy', $post).'?redirect=app.calendar');

    $response->assertRedirect(route('app.calendar'));
    expect(Post::find($post->id))->toBeNull();
});

test('destroy post with redirect param redirects to specified route', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)
        ->delete(route('app.posts.destroy', $post).'?redirect=app.posts.index');

    $response->assertRedirect(route('app.posts.index'));
    expect(Post::find($post->id))->toBeNull();
});

test('destroy post returns 404 for post from different workspace', function () {
    $otherWorkspace = Workspace::factory()->create();
    $post = Post::factory()->create([
        'workspace_id' => $otherWorkspace->id,
        'user_id' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)->delete(route('app.posts.destroy', $post));

    $response->assertNotFound();
});

// Label tests
test('edit post includes workspace labels', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);

    PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
    ]);

    $label = WorkspaceLabel::factory()->create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Marketing',
        'color' => '#FF0000',
    ]);

    $response = $this->actingAs($this->user)->get(route('app.posts.edit', $post));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('posts/Edit')
        ->has('labels', 1)
        ->where('labels.0.name', 'Marketing')
    );
});

test('update post can attach labels', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);

    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
    ]);

    $label = WorkspaceLabel::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $response = $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'draft',
        'platforms' => [
            [
                'id' => $postPlatform->id,
                'content_type' => ContentType::LinkedInPost->value,
            ],
        ],
        'label_ids' => [$label->id],
    ]);

    $response->assertRedirect();

    $post->refresh();
    expect($post->labels)->toHaveCount(1);
    expect($post->labels->first()->id)->toBe($label->id);
});

test('update post can detach labels', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);

    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
    ]);

    $label = WorkspaceLabel::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $post->labels()->attach($label);

    $response = $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'draft',
        'platforms' => [
            [
                'id' => $postPlatform->id,
                'content_type' => ContentType::LinkedInPost->value,
            ],
        ],
        'label_ids' => [],
    ]);

    $response->assertRedirect();

    $post->refresh();
    expect($post->labels)->toHaveCount(0);
});

test('update post can sync multiple labels', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);

    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
    ]);

    $label1 = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    $label2 = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    $label3 = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);

    // Attach initial label
    $post->labels()->attach($label1);

    // Update with different labels
    $response = $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'draft',
        'platforms' => [
            [
                'id' => $postPlatform->id,
                'content_type' => ContentType::LinkedInPost->value,
            ],
        ],
        'label_ids' => [$label2->id, $label3->id],
    ]);

    $response->assertRedirect();

    $post->refresh();
    expect($post->labels)->toHaveCount(2);
    expect($post->labels->pluck('id')->toArray())->toEqualCanonicalizing([$label2->id, $label3->id]);
});

test('platform metrics returns unsupported when post not published', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);

    $pp = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
        'status' => Status::Pending,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson(route('app.posts.platforms.metrics', ['post' => $post->id, 'postPlatform' => $pp->id]));

    $response->assertOk();
    $response->assertJson(['unsupported' => true, 'reason' => 'not_published']);
});

test('platform metrics returns 404 for post in another workspace', function () {
    $otherWorkspace = Workspace::factory()->create();
    $otherPost = Post::factory()->create(['workspace_id' => $otherWorkspace->id]);
    $pp = PostPlatform::factory()->create([
        'post_id' => $otherPost->id,
        'social_account_id' => $this->socialAccount->id,
    ]);

    $this->actingAs($this->user)
        ->getJson(route('app.posts.platforms.metrics', ['post' => $otherPost->id, 'postPlatform' => $pp->id]))
        ->assertNotFound();
});

test('platform metrics returns 404 when post platform belongs to different post', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $otherPost = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $pp = PostPlatform::factory()->create([
        'post_id' => $otherPost->id,
        'social_account_id' => $this->socialAccount->id,
    ]);

    $this->actingAs($this->user)
        ->getJson(route('app.posts.platforms.metrics', ['post' => $post->id, 'postPlatform' => $pp->id]))
        ->assertNotFound();
});

test('platform metrics dispatches X analytics for X platform', function () {
    $xAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::X,
    ]);

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $pp = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $xAccount->id,
        'platform' => Platform::X,
        'status' => Status::Published,
        'platform_post_id' => '1234567890',
    ]);

    Http::fake([
        'https://api.x.com/2/tweets/1234567890*' => Http::response([
            'data' => [
                'public_metrics' => [
                    'impression_count' => 500,
                    'like_count' => 42,
                    'retweet_count' => 7,
                    'reply_count' => 3,
                    'quote_count' => 1,
                    'bookmark_count' => 4,
                ],
            ],
        ], 200),
    ]);

    $response = $this->actingAs($this->user)
        ->getJson(route('app.posts.platforms.metrics', ['post' => $post->id, 'postPlatform' => $pp->id]));

    $response->assertOk();
    $response->assertJsonFragment(['label' => 'Impressions', 'value' => 500]);
    $response->assertJsonFragment(['label' => 'Likes', 'value' => 42]);
});

test('show page renders for non-editable posts', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Published,
        'content' => 'Hello world',
    ]);

    PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
        'enabled' => true,
        'platform_url' => 'https://linkedin.com/posts/abc',
    ]);

    $response = $this->actingAs($this->user)->get(route('app.posts.show', $post));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('posts/Show', false)
        ->has('post.platforms', 1)
    );
});

test('show page redirects editable posts to edit', function () {
    foreach ([PostStatus::Draft, PostStatus::Scheduled] as $status) {
        $post = Post::factory()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->user->id,
            'status' => $status,
        ]);

        $this->actingAs($this->user)
            ->get(route('app.posts.show', $post))
            ->assertRedirect(route('app.posts.edit', $post));
    }
});

test('failed posts render show without redirecting to edit', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Failed,
    ]);

    $this->actingAs($this->user)
        ->get(route('app.posts.show', $post))
        ->assertOk();
});

test('destroy blocks published posts', function () {
    foreach ([PostStatus::Publishing, PostStatus::Published, PostStatus::PartiallyPublished] as $status) {
        $post = Post::factory()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->user->id,
            'status' => $status,
        ]);

        $this->actingAs($this->user)
            ->delete(route('app.posts.destroy', $post))
            ->assertRedirect();

        expect(Post::find($post->id))->not->toBeNull();
    }
});

test('show page returns 404 for post in another workspace', function () {
    $otherWorkspace = Workspace::factory()->create();
    $post = Post::factory()->create([
        'workspace_id' => $otherWorkspace->id,
        'user_id' => $this->user->id,
    ]);

    $this->actingAs($this->user)
        ->get(route('app.posts.show', $post))
        ->assertNotFound();
});

test('update post redirects to show page after publishing', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'content' => 'Test',
    ]);

    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
    ]);

    $response = $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'publishing',
        'content' => 'Test',
        'platforms' => [
            ['id' => $postPlatform->id, 'content_type' => ContentType::LinkedInPost->value],
        ],
    ]);

    $response->assertRedirect(route('app.posts.show', $post));
});

test('update post rejects scheduling youtube short with image', function () {
    $youtubeAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::YouTube,
    ]);

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'content' => 'Test',
    ]);

    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $youtubeAccount->id,
    ]);

    $response = $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'scheduled',
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'media' => [
            [
                'id' => 'media-1',
                'path' => 'media/foo.jpg',
                'url' => 'https://example.com/foo.jpg',
                'type' => 'image',
                'mime_type' => 'image/jpeg',
            ],
        ],
        'platforms' => [
            [
                'id' => $postPlatform->id,
                'content_type' => ContentType::YouTubeShort->value,
            ],
        ],
    ]);

    $response->assertSessionHasErrors('platforms.0.content_type');
});

test('update post rejects scheduling instagram reel with no media', function () {
    $instagramAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
    ]);

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'content' => 'Test',
    ]);

    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $instagramAccount->id,
    ]);

    $response = $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'scheduled',
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'platforms' => [
            [
                'id' => $postPlatform->id,
                'content_type' => ContentType::InstagramReel->value,
            ],
        ],
    ]);

    $response->assertSessionHasErrors('platforms.0.content_type');
});

test('update post rejects invalid instagram aspect_ratio meta', function () {
    $instagramAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
    ]);

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);

    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $instagramAccount->id,
    ]);

    $response = $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'draft',
        'platforms' => [
            [
                'id' => $postPlatform->id,
                'content_type' => ContentType::InstagramFeed->value,
                'meta' => ['aspect_ratio' => '2:1'],
            ],
        ],
    ]);

    $response->assertSessionHasErrors('platforms.0.meta.aspect_ratio');
});

test('update post accepts valid instagram aspect_ratio meta', function () {
    $instagramAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
    ]);

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);

    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $instagramAccount->id,
    ]);

    $response = $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'draft',
        'platforms' => [
            [
                'id' => $postPlatform->id,
                'content_type' => ContentType::InstagramFeed->value,
                'meta' => ['aspect_ratio' => '4:5'],
            ],
        ],
    ]);

    $response->assertSessionDoesntHaveErrors('platforms.0.meta.aspect_ratio');
    $postPlatform->refresh();
    expect(data_get($postPlatform->meta, 'aspect_ratio'))->toBe('4:5');
});

test('update post accepts instagram collaborators and strips at signs', function () {
    $instagramAccount = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);

    $postPlatform = PostPlatform::factory()->instagram()->create([
        'post_id' => $post->id,
        'social_account_id' => $instagramAccount->id,
    ]);

    $response = $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'draft',
        'platforms' => [
            [
                'id' => $postPlatform->id,
                'content_type' => ContentType::InstagramFeed->value,
                'meta' => ['collaborators' => ['@Host_One', 'host_two']],
            ],
        ],
    ]);

    $response->assertSessionDoesntHaveErrors('platforms.0.meta.collaborators');
    $postPlatform->refresh();
    expect(data_get($postPlatform->meta, 'collaborators'))->toBe(['Host_One', 'host_two']);
});

test('update post rejects instagram collaborators sent as a comma-separated string', function () {
    $instagramAccount = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);

    $postPlatform = PostPlatform::factory()->instagram()->create([
        'post_id' => $post->id,
        'social_account_id' => $instagramAccount->id,
    ]);

    $response = $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'draft',
        'platforms' => [
            [
                'id' => $postPlatform->id,
                'content_type' => ContentType::InstagramFeed->value,
                'meta' => ['collaborators' => 'Host_One,host_two'],
            ],
        ],
    ]);

    $response->assertSessionHasErrors('platforms.0.meta.collaborators');
});

test('update post clears instagram collaborators when switching to a story without sending meta', function () {
    $instagramAccount = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);

    $postPlatform = PostPlatform::factory()->instagram()->create([
        'post_id' => $post->id,
        'social_account_id' => $instagramAccount->id,
        'content_type' => ContentType::InstagramReel->value,
        'meta' => ['collaborators' => ['Host_One'], 'aspect_ratio' => '4:5'],
    ]);

    $response = $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'draft',
        'platforms' => [
            [
                'id' => $postPlatform->id,
                'content_type' => ContentType::InstagramStory->value,
            ],
        ],
    ]);

    $response->assertSessionDoesntHaveErrors();
    $postPlatform->refresh();

    expect(data_get($postPlatform->meta, 'collaborators'))->toBe([])
        ->and(data_get($postPlatform->meta, 'aspect_ratio'))->toBe('4:5')
        ->and($postPlatform->content_type)->toBe(ContentType::InstagramStory);
});

test('update post clears leftover instagram collaborators on an already-story row without sending content_type', function () {
    $instagramAccount = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);

    $postPlatform = PostPlatform::factory()->instagram()->create([
        'post_id' => $post->id,
        'social_account_id' => $instagramAccount->id,
        'content_type' => ContentType::InstagramStory->value,
        'meta' => ['collaborators' => ['Host_One'], 'aspect_ratio' => '4:5'],
    ]);

    $response = $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'draft',
        'platforms' => [
            [
                'id' => $postPlatform->id,
            ],
        ],
    ]);

    $response->assertSessionDoesntHaveErrors();
    $postPlatform->refresh();

    expect(data_get($postPlatform->meta, 'collaborators'))->toBe([])
        ->and(data_get($postPlatform->meta, 'aspect_ratio'))->toBe('4:5')
        ->and($postPlatform->content_type)->toBe(ContentType::InstagramStory);
});

test('update post rejects tagging the connected instagram account as a collaborator', function () {
    $instagramAccount = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $this->workspace->id,
        'username' => 'testuser',
    ]);

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);

    $postPlatform = PostPlatform::factory()->instagram()->create([
        'post_id' => $post->id,
        'social_account_id' => $instagramAccount->id,
    ]);

    $response = $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'draft',
        'platforms' => [
            [
                'id' => $postPlatform->id,
                'content_type' => ContentType::InstagramFeed->value,
                'meta' => ['collaborators' => ['testuser']],
            ],
        ],
    ]);

    $response->assertSessionHasErrors('platforms.0.meta.collaborators.0');
});

test('update post rejects an invalid instagram collaborator username', function () {
    $instagramAccount = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);

    $postPlatform = PostPlatform::factory()->instagram()->create([
        'post_id' => $post->id,
        'social_account_id' => $instagramAccount->id,
    ]);

    $response = $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'draft',
        'platforms' => [
            [
                'id' => $postPlatform->id,
                'content_type' => ContentType::InstagramFeed->value,
                'meta' => ['collaborators' => ['.user']],
            ],
        ],
    ]);

    $response->assertSessionHasErrors('platforms.0.meta.collaborators.0');
});

test('update post rejects more than three instagram collaborators', function () {
    $instagramAccount = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);

    $postPlatform = PostPlatform::factory()->instagram()->create([
        'post_id' => $post->id,
        'social_account_id' => $instagramAccount->id,
    ]);

    $response = $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'draft',
        'platforms' => [
            [
                'id' => $postPlatform->id,
                'content_type' => ContentType::InstagramFeed->value,
                'meta' => ['collaborators' => ['a', 'b', 'c', 'd']],
            ],
        ],
    ]);

    $response->assertSessionHasErrors('platforms.0.meta.collaborators');
});

test('scheduling without content_type per platform fails', function () {
    $youtubeAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::YouTube,
    ]);

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'content' => 'Test',
    ]);

    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $youtubeAccount->id,
    ]);

    $response = $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'scheduled',
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'platforms' => [
            ['id' => $postPlatform->id],
        ],
    ]);

    $response->assertSessionHasErrors('platforms.0.content_type');
});

test('draft post does not enforce media-vs-content-type compatibility', function () {
    $youtubeAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::YouTube,
    ]);

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);

    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $youtubeAccount->id,
    ]);

    $response = $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'draft',
        'media' => [
            [
                'id' => 'media-1',
                'path' => 'media/foo.jpg',
                'url' => 'https://example.com/foo.jpg',
                'type' => 'image',
                'mime_type' => 'image/jpeg',
            ],
        ],
        'platforms' => [
            [
                'id' => $postPlatform->id,
                'content_type' => ContentType::YouTubeShort->value,
            ],
        ],
    ]);

    $response->assertSessionDoesntHaveErrors('platforms.0.content_type');
});

test('update post validates label_ids exist', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);

    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
    ]);

    $response = $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'draft',
        'platforms' => [
            [
                'id' => $postPlatform->id,
                'content_type' => ContentType::LinkedInPost->value,
            ],
        ],
        'label_ids' => ['non-existent-uuid'],
    ]);

    $response->assertSessionHasErrors('label_ids.0');
});

// Member authorization tests
test('member can view posts index', function () {
    $member = User::factory()->create([
        'account_id' => $this->workspace->account_id,
    ]);
    $this->workspace->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $this->workspace->id]);

    $response = $this->actingAs($member)->get(route('app.posts.index'));

    $response->assertOk();
});

test('member can create post', function () {
    $member = User::factory()->create([
        'account_id' => $this->workspace->account_id,
    ]);
    $this->workspace->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $this->workspace->id]);

    $response = $this->actingAs($member)->post(route('app.posts.store'));

    $response->assertRedirect();
});
