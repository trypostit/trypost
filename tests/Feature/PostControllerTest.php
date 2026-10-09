<?php

declare(strict_types=1);

use App\Enums\Analytics\PublicationContentType;
use App\Enums\Post\CreatedVia;
use App\Enums\Post\PublishStatus as Status;
use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Jobs\PublishPost;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\Post;
use App\Models\PostNote;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use App\Support\LinkTlds;
use App\Support\PostingSchedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $this->user = User::factory()->create([]);
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $this->socialAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
    ]);
});

// Index tests
test('schedule routes keep list and calendar as separate pages', function () {
    expect(route('app.posts.index', absolute: false))->toBe('/schedule')
        ->and(route('app.calendar', ['view' => 'month'], absolute: false))->toBe('/schedule/calendar/month');

    $this->actingAs($this->user)
        ->get(route('app.posts.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('publish/Index'));

    $this->get(route('app.calendar', ['view' => 'month']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('posts/Calendar')
            ->where('view', 'month'));
});

test('posts index requires authentication', function () {
    $response = $this->get(route('app.posts.index'));

    $response->assertRedirect(route('login'));
});

test('posts index shows posts for current workspace', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)->get(route('app.posts.index', ['tab' => 'drafts']));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('publish/Index', false)
        ->where('tab', 'drafts')
        ->loadDeferredProps(fn ($reload) => $reload
            ->has('posts.data', 1))
    );
});

test('posts index exposes note counts and opens a note on an existing post', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
    $note = PostNote::factory()->create([
        'post_id' => $post->id,
        'user_id' => $this->user->id,
    ]);

    $this->actingAs($this->user)
        ->get(route('app.posts.index', ['notes' => $post->id, 'note' => $note->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('publish/Index')
            ->where('tab', 'drafts')
            ->where('openPostNotesId', $post->id)
            ->where('highlightNoteId', $note->id)
            ->loadDeferredProps(fn ($reload) => $reload
                ->has('posts.data', 1)
                ->where('posts.data.0.notes_count', 1))
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
        ->get(route('app.posts.index', ['tab' => 'drafts', 'labels' => [$label->id]]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('filters.labels', [$label->id])
        ->loadDeferredProps(fn ($reload) => $reload
            ->has('posts.data', 1)
            ->where('posts.data.0.id', $taggedPost->id))
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
        ->get(route('app.posts.index', ['tab' => 'drafts', 'labels' => [$marketing->id, $sales->id]]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('filters.labels', [$marketing->id, $sales->id])
        ->loadDeferredProps(fn ($reload) => $reload
            ->has('posts.data', 2))
    );
});

test('posts index ignores blank label query params', function () {
    Post::factory()->count(2)->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)
        ->get(route('app.posts.index', ['tab' => 'drafts', 'labels' => ['']]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('filters.labels', [])
        ->loadDeferredProps(fn ($reload) => $reload
            ->has('posts.data', 2))
    );
});

test('posts index exposes each workspace channel separately for filtering', function () {
    $firstInstagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    $secondInstagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    SocialAccount::factory()->instagram()->create();

    $this->actingAs($this->user)
        ->get(route('app.posts.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('filterAccounts', 3)
            ->where('filterAccounts', fn ($accounts) => collect($accounts)->pluck('id')->contains($firstInstagram->id)
                && collect($accounts)->pluck('id')->contains($secondInstagram->id))
            ->where('filters.channels', []));
});

test('posts index filters individual accounts', function () {
    $firstInstagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    $secondInstagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    $sharedPost = Post::factory()->forAccount($firstInstagram)->instagram()->published()->create([
        'user_id' => $this->user->id,
    ]);
    Post::factory()->forAccount($secondInstagram)->instagram()->published()->create([
        'user_id' => $this->user->id,
    ]);
    Post::factory()->forAccount($secondInstagram)->instagram()->draft()->create([
        'user_id' => $this->user->id,
    ]);

    $this->actingAs($this->user)
        ->get(route('app.posts.index', ['tab' => 'sent', 'channels' => [$firstInstagram->id]]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.channels', [$firstInstagram->id])
            ->where('counts', ['queue' => 0, 'drafts' => 0, 'sent' => 1, 'approvals' => 0])
            ->loadDeferredProps(fn ($reload) => $reload
                ->has('posts.data', 1)
                ->where('posts.data.0.id', $sharedPost->id)
                ->where('posts.data.0.social_account_id', $firstInstagram->id)));
});

test('posts index combines selected channels with OR semantics', function () {
    $firstInstagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    $secondInstagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    $thirdInstagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    Post::factory()->forAccount($firstInstagram)->instagram()->create(['user_id' => $this->user->id]);
    Post::factory()->forAccount($secondInstagram)->instagram()->create(['user_id' => $this->user->id]);
    Post::factory()->forAccount($thirdInstagram)->instagram()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user)
        ->get(route('app.posts.index', ['channels' => [$firstInstagram->id, $secondInstagram->id], 'tab' => 'drafts']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('counts.drafts', 2)
            ->where('filters.channels', [$firstInstagram->id, $secondInstagram->id])
            ->loadDeferredProps(fn ($reload) => $reload
                ->has('posts.data', 2)));
});

test('posts index does not accept a channel from another workspace', function () {
    $otherAccount = SocialAccount::factory()->instagram()->create();
    Post::factory()->forAccount($this->socialAccount)->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user)
        ->get(route('app.posts.index', ['tab' => 'drafts', 'channels' => [$otherAccount->id]]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('counts', ['queue' => 0, 'drafts' => 0, 'sent' => 0, 'approvals' => 0])
            ->loadDeferredProps(fn ($reload) => $reload
                ->has('posts.data', 0)));
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

test('legacy calendar compose link opens the global composer without a query URL', function () {
    $date = now()->addDay()->format('Y-m-d');

    $this->actingAs($this->user)
        ->get(route('app.calendar', ['compose' => 1, 'date' => $date]))
        ->assertRedirect(route('app.calendar'))
        ->assertSessionHas('flash.openPostComposer.date', $date);
});

// Create tests
test('create requires authentication', function () {
    $response = $this->get(route('app.posts.create'));

    $response->assertRedirect(route('login'));
});

test('legacy create route opens the global composer on a clean posts URL', function () {
    $response = $this->actingAs($this->user)->get(route('app.posts.create'));

    $response->assertRedirect(route('app.posts.index'))
        ->assertSessionHas('flash.openPostComposer.assistant', false);
});

test('create forwards date query param to the composer', function () {
    $response = $this->actingAs($this->user)->get(route('app.posts.create', ['date' => '2026-06-01']));

    $response->assertRedirect(route('app.posts.index'))
        ->assertSessionHas('flash.openPostComposer.date', '2026-06-01');
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
    $accountId = $this->socialAccount->id;
    $this->socialAccount->delete();

    $response = $this->actingAs($this->user)->post(route('app.posts.store'), [
        'status' => 'draft',
        'content' => 'Text',
        'destinations' => [[
            'social_account_id' => $accountId,
            'content_type' => ContentType::LinkedInPost->value,
        ]],
    ]);

    $response->assertRedirect(route('app.workspace.channels'));
});

test('opening the composer creates no draft', function () {
    $this->actingAs($this->user)->get(route('app.posts.create'))
        ->assertRedirect(route('app.posts.index'));

    expect(Post::where('workspace_id', $this->workspace->id)->count())->toBe(0);
});

test('composer taken slots are loaded on demand for a channel of the current workspace', function () {
    $this->actingAs($this->user)
        ->getJson(route('app.posts.composer.taken-slots', ['account' => $this->socialAccount, 'from' => now()->toIso8601String(), 'to' => now()->addDay()->toIso8601String()]))
        ->assertOk()
        ->assertExactJson(['takenSlots' => []]);
});

test('composer data tells whether each channel has posting times', function () {
    $scheduled = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::X,
        'posting_schedule' => PostingSchedule::empty()->withTime(1, '09:00'),
    ]);

    $accounts = $this->actingAs($this->user)
        ->get(route('app.posts.index'))
        ->assertOk()
        ->inertiaProps('composer.accounts');

    expect($accounts[$scheduled->id]['has_posting_schedule'])->toBeTrue()
        ->and($accounts[$this->socialAccount->id]['has_posting_schedule'])->toBeFalse();
});

test('posts list and calendar expose the schedule mode', function () {
    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Scheduled,
        'scheduled_at' => now()->addDay(),
        'schedule_mode' => ScheduleMode::Queue,
    ]);

    $this->actingAs($this->user)
        ->get(route('app.posts.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('publish/Index', false)
            ->loadDeferredProps(fn ($reload) => $reload
                ->where('posts.data.0.id', $post->id)
                ->where('posts.data.0.schedule_mode', ScheduleMode::Queue->value))
        );

    $this->actingAs($this->user)
        ->get(route('app.calendar', ['view' => 'month', 'month' => $post->scheduled_at->format('Y-m-d')]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('posts/Calendar', false)
            ->where("posts.{$post->scheduled_at->format('Y-m-d')}.0.schedule_mode", ScheduleMode::Queue->value)
        );
});

test('posts tabs filter independently and expose counts', function () {
    Post::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id, 'status' => PostStatus::Draft]);
    Post::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id, 'status' => PostStatus::Scheduled]);
    Post::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id, 'status' => PostStatus::Published]);
    Post::factory()->create(['status' => PostStatus::Draft]);

    $this->actingAs($this->user)
        ->get(route('app.posts.index', ['tab' => 'drafts']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('publish/Index', false)
            ->where('tab', 'drafts')
            ->where('counts', ['queue' => 1, 'drafts' => 1, 'sent' => 1, 'approvals' => 0])
            ->loadDeferredProps(fn ($reload) => $reload
                ->has('posts.data', 1)));
});

test('store post creates one independent draft per selected account', function () {
    $accounts = collect([$this->socialAccount])->concat(
        SocialAccount::factory()->count(3)->create([
            'workspace_id' => $this->workspace->id,
            'platform' => Platform::LinkedIn,
        ]),
    );

    $this->actingAs($this->user)->post(route('app.posts.store'), [
        'status' => 'draft',
        'content' => 'Shared caption',
        'media' => [],
        'destinations' => $accounts->map(fn ($account) => [
            'social_account_id' => $account->id,
            'content_type' => ContentType::LinkedInPost->value,
            'meta' => [],
        ])->all(),
    ])->assertRedirect(route('app.posts.index', ['tab' => 'drafts']));

    $posts = Post::where('workspace_id', $this->workspace->id)->get();
    expect($posts)->toHaveCount(4);
    foreach ($posts as $post) {
        expect($post->status)->toBe(PostStatus::Draft)
            ->and($post->content)->toBe('Shared caption')
            ->and($post->created_via)->toBe(CreatedVia::Web)
            ->and($post->scheduled_at)->toBeNull()
            ->and($post->social_account_id)->not->toBeNull();
    }
});

test('store post schedules each selected account independently', function () {
    $other = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
    ]);
    $date = now()->addDay()->startOfMinute()->toIso8601String();

    $this->actingAs($this->user)->post(route('app.posts.store'), [
        'status' => 'scheduled',
        'content' => 'Scheduled caption',
        'media' => [],
        'scheduled_at' => $date,
        'destinations' => collect([$this->socialAccount, $other])->map(fn ($account) => [
            'social_account_id' => $account->id,
            'content_type' => ContentType::LinkedInPost->value,
            'meta' => [],
        ])->all(),
    ])->assertRedirect(route('app.posts.index', ['tab' => 'queue']));

    expect(Post::where('workspace_id', $this->workspace->id)->where('status', PostStatus::Scheduled)->count())->toBe(2);
    expect(Post::where('workspace_id', $this->workspace->id)->pluck('scheduled_at')->unique())->toHaveCount(1);
});

test('saving a zero-target legacy draft creates independent posts and removes the empty draft', function () {
    $legacy = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'content' => 'Legacy caption',
    ]);
    $other = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn]);
    $accounts = [$this->socialAccount, $other];

    $this->actingAs($this->user)->post(route('app.posts.store'), [
        'recover_post_id' => $legacy->id,
        'status' => 'draft',
        'content' => 'Recovered caption',
        'media' => [],
        'destinations' => array_map(fn ($account) => [
            'social_account_id' => $account->id,
            'content_type' => ContentType::LinkedInPost->value,
            'meta' => [],
        ], $accounts),
    ])->assertRedirect(route('app.posts.index', ['tab' => 'drafts']));

    expect(Post::find($legacy->id))->toBeNull()
        ->and(Post::where('workspace_id', $this->workspace->id)->count())->toBe(2)
        ->and(Post::where('workspace_id', $this->workspace->id)->where('content', 'Recovered caption')->count())->toBe(2);
});

test('a draft whose channel is gone opens in the composer and is recovered onto a new channel', function () {
    $orphaned = Post::factory()->forAccount($this->socialAccount)->create(['user_id' => $this->user->id, 'status' => PostStatus::Draft, 'content' => 'Lost channel']);
    $orphaned->forceFill(['social_account_id' => null])->save();

    $this->actingAs($this->user)->get(route('app.posts.edit', $orphaned))
        ->assertRedirect(route('app.posts.index', ['edit' => $orphaned->id]));

    $this->actingAs($this->user)->post(route('app.posts.store'), [
        'recover_post_id' => $orphaned->id,
        'status' => 'draft',
        'content' => 'Lost channel',
        'media' => [],
        'destinations' => [['social_account_id' => $this->socialAccount->id, 'content_type' => ContentType::LinkedInPost->value, 'meta' => []]],
    ])->assertSessionHasNoErrors();

    expect(Post::find($orphaned->id))->toBeNull()
        ->and(Post::where('workspace_id', $this->workspace->id)->where('content', 'Lost channel')->sole()->social_account_id)->toBe($this->socialAccount->id);
});

test('store post rejects invalid schedule format', function () {
    $this->actingAs($this->user)
        ->post(route('app.posts.store'), ['status' => 'scheduled', 'scheduled_at' => 'not-a-date', 'destinations' => [[
            'social_account_id' => $this->socialAccount->id,
            'content_type' => ContentType::LinkedInPost->value,
        ]]])
        ->assertSessionHasErrors(['scheduled_at']);

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

test('edit post opens its account in the composer', function () {
    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);

    $response = $this->actingAs($this->user)->get(route('app.posts.edit', $post));

    $response->assertRedirect(route('app.posts.index', ['edit' => $post->id]));
    $this->actingAs($this->user)->get(route('app.posts.index', ['edit' => $post->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('publish/Index')
            ->where('editPost.id', $post->id)
            ->has("composer.accounts.{$this->socialAccount->id}")
            ->where('channels.0.id', $this->socialAccount->id)
        );
});

test('edit does not open the composer for a scheduled post whose channel is gone', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Scheduled,
        'scheduled_at' => now()->addDay(),
        'platform' => Platform::LinkedIn,
        'content_type' => ContentType::LinkedInPost,
    ]);

    $this->actingAs($this->user)
        ->get(route('app.posts.edit', $post))
        ->assertRedirect(route('app.posts.index', ['post' => $post->id]));
});

test('edit exposes null scheduled_at for an unscheduled draft', function () {
    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'scheduled_at' => null,
    ]);

    $this->actingAs($this->user)
        ->get(route('app.posts.index', ['edit' => $post->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('publish/Index')
            ->where('editPost.scheduled_at', null)
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

test('edit redirects to the post details for non-editable statuses', function () {
    foreach ([PostStatus::Published, PostStatus::Publishing, PostStatus::Failed] as $status) {
        $post = Post::factory()->forAccount($this->socialAccount)->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->user->id,
            'status' => $status,
        ]);

        $this->actingAs($this->user)
            ->get(route('app.posts.edit', $post))
            ->assertRedirect(route('app.posts.index', ['post' => $post->id]));
    }
});

test('edit allows draft and scheduled posts', function () {
    foreach ([PostStatus::Draft, PostStatus::Scheduled] as $status) {
        $post = Post::factory()->forAccount($this->socialAccount)->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->user->id,
            'status' => $status,
        ]);

        $this->actingAs($this->user)
            ->get(route('app.posts.edit', $post))
            ->assertRedirect(route('app.posts.index', ['edit' => $post->id]));
    }
});

test('edit opens the note an email links to and ignores the old comments tab', function () {
    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);

    $this->actingAs($this->user)
        ->get(route('app.posts.edit', ['post' => $post, 'comment' => 'note-id']))
        ->assertRedirect(route('app.posts.index', ['notes' => $post->id, 'note' => 'note-id']));

    $this->actingAs($this->user)
        ->get(route('app.posts.edit', ['post' => $post, 'tab' => 'comments']))
        ->assertRedirect(route('app.posts.index', ['edit' => $post->id]));
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
    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'content' => 'Original content',
    ]);

    $response = $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'draft',
        'content' => 'Updated content',
        'content_type' => ContentType::LinkedInPost->value,
    ]);

    $response->assertRedirect();

    $post->refresh();
    expect($post->content)->toBe('Updated content');
    $post->refresh();
    expect($post->content_type)->toBe(ContentType::LinkedInPost);
});

test('update post cannot update published posts', function () {
    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Published,
    ]);

    $response = $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'draft',
        'content' => 'Test content',
        'content_type' => ContentType::LinkedInPost->value,
    ]);

    $response->assertRedirect();
});

test('cannot re-publish a failed post', function () {
    Bus::fake();

    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Failed,
    ]);

    $response = $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'publishing',
        'content' => 'Test content',
        'content_type' => ContentType::LinkedInPost->value,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('flash.bannerStyle', 'danger');

    $post->refresh();
    expect($post->status)->toBe(PostStatus::Failed);
    Bus::assertNotDispatched(PublishPost::class);
});

test('cannot update a post in publishing state', function () {
    Bus::fake();

    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Publishing,
    ]);

    $response = $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'draft',
        'content' => 'Test content',
        'content_type' => ContentType::LinkedInPost->value,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('flash.bannerStyle', 'danger');

    $post->refresh();
    expect($post->status)->toBe(PostStatus::Publishing);
    Bus::assertNotDispatched(PublishPost::class);
});

test('cannot update a published post', function () {
    Bus::fake();

    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Published,
    ]);

    $response = $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'publishing',
        'content' => 'Test content',
        'content_type' => ContentType::LinkedInPost->value,
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

    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'content' => 'Test content',
        'scheduled_at' => now()->addDays(7),
    ]);

    $response = $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'publishing',
        'content' => 'Test content',
        'content_type' => ContentType::LinkedInPost->value,
    ]);

    $response->assertRedirect(route('app.posts.index', ['tab' => 'queue']));

    $post->refresh();
    expect($post->scheduled_at->toDateTimeString())->toBe(now()->toDateTimeString());
});

test('publish now is allowed when the draft has no scheduled_at', function () {
    Bus::fake();
    $this->freezeTime();

    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'content' => 'Test content',
        'scheduled_at' => null,
    ]);

    $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'publishing',
        'content' => 'Test content',
        'content_type' => ContentType::LinkedInPost->value,
    ])->assertRedirect();

    $post->refresh();
    expect($post->status)->toBe(PostStatus::Publishing)
        ->and($post->scheduled_at->toDateTimeString())->toBe(now()->toDateTimeString());
    Bus::assertDispatched(PublishPost::class);
});

test('update rejects scheduled status without a future scheduled_at', function (?string $existingScheduledAt) {
    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'scheduled_at' => $existingScheduledAt,
        'content' => 'Test content',
    ]);

    $payload = [
        'status' => 'scheduled',
        'content' => 'Test content',
        'content_type' => ContentType::LinkedInPost->value,
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

    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'scheduled_at' => $scheduledAt,
        'content' => 'Test content',
    ]);

    $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'scheduled',
        'content' => 'Test content',
        'content_type' => ContentType::LinkedInPost->value,
    ])->assertRedirect();

    $post->refresh();
    expect($post->status)->toBe(PostStatus::Scheduled)
        ->and($post->scheduled_at->toDateTimeString())->toBe($scheduledAt->toDateTimeString());
});

test('update schedules an unscheduled draft with an explicit future scheduled_at', function () {
    $scheduledAt = now()->addDay()->startOfSecond();

    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'scheduled_at' => null,
        'content' => 'Test content',
    ]);

    $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'scheduled',
        'scheduled_at' => $scheduledAt->toIso8601String(),
        'content' => 'Test content',
        'content_type' => ContentType::LinkedInPost->value,
    ])->assertRedirect();

    $post->refresh();
    expect($post->status)->toBe(PostStatus::Scheduled)
        ->and($post->scheduled_at->toDateTimeString())->toBe($scheduledAt->toDateTimeString());
});

test('update keeps an unscheduled draft when saving as draft without scheduled_at', function () {
    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'scheduled_at' => null,
        'content' => 'Original',
    ]);

    $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'draft',
        'content' => 'Still a draft',
        'content_type' => ContentType::LinkedInPost->value,
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

test('destroy keeps a post that already went out or failed', function (PostStatus $status) {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => $status,
    ]);

    $this->actingAs($this->user)
        ->from(route('app.posts.index', ['tab' => 'sent']))
        ->delete(route('app.posts.destroy', $post))
        ->assertRedirect(route('app.posts.index', ['tab' => 'sent']));

    expect(Post::find($post->id))->not->toBeNull();
})->with([
    'publishing' => [PostStatus::Publishing],
    'published' => [PostStatus::Published],
    'failed' => [PostStatus::Failed],
]);

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
    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);

    $label = WorkspaceLabel::factory()->create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Marketing',
        'color' => '#FF0000',
    ]);

    $response = $this->actingAs($this->user)->get(route('app.posts.index', ['edit' => $post->id]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('publish/Index')
        ->has('labels', 1)
        ->where('labels.0.name', 'Marketing')
    );
});

test('update post can attach labels', function () {
    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);

    $label = WorkspaceLabel::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $response = $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'draft',
        'content_type' => ContentType::LinkedInPost->value,
        'label_ids' => [$label->id],
    ]);

    $response->assertRedirect();

    $post->refresh();
    expect($post->labels)->toHaveCount(1);
    expect($post->labels->first()->id)->toBe($label->id);
});

test('update post can detach labels', function () {
    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);

    $label = WorkspaceLabel::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $post->labels()->attach($label);

    $response = $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'draft',
        'content_type' => ContentType::LinkedInPost->value,
        'label_ids' => [],
    ]);

    $response->assertRedirect();

    $post->refresh();
    expect($post->labels)->toHaveCount(0);
});

test('update post can sync multiple labels', function () {
    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);

    $label1 = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    $label2 = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    $label3 = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);

    // Attach initial label
    $post->labels()->attach($label1);

    // Update with different labels
    $response = $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'draft',
        'content_type' => ContentType::LinkedInPost->value,
        'label_ids' => [$label2->id, $label3->id],
    ]);

    $response->assertRedirect();

    $post->refresh();
    expect($post->labels)->toHaveCount(2);
    expect($post->labels->pluck('id')->toArray())->toEqualCanonicalizing([$label2->id, $label3->id]);
});

test('post metrics returns unsupported when post not published', function () {
    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'publish_status' => Status::Pending,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson(route('app.posts.metrics', $post));

    $response->assertOk();
    $response->assertJson(['unsupported' => true, 'reason' => 'not_published']);
});

test('post metrics returns 404 for post in another workspace', function () {
    $otherWorkspace = Workspace::factory()->create();
    $otherPost = Post::factory()->forAccount($this->socialAccount)->create([
        'workspace_id' => $otherWorkspace->id,
    ]);

    $this->actingAs($this->user)
        ->getJson(route('app.posts.metrics', $otherPost))
        ->assertNotFound();
});

test('post metrics reads persisted X analytics without a provider request', function () {
    $xAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::X,
    ]);

    $post = Post::factory()->forAccount($xAccount)->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'platform' => Platform::X,
        'publish_status' => Status::Published,
        'platform_post_id' => '1234567890',
    ]);

    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $this->workspace->id,
        'social_account_id' => $xAccount->id,
        'social_account_key' => $xAccount->id,
        'post_id' => $post->id,
        'platform' => Platform::X,
        'network' => Platform::X->network(),
        'remote_id' => '1234567890',
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $publication->id,
        'impressions_count' => 500,
        'reactions_count' => 42,
        'metrics' => ['impressions' => ['value' => 500, 'unit' => 'count', 'availability' => 'available']],
    ]);
    Http::fake();

    $response = $this->actingAs($this->user)
        ->getJson(route('app.posts.metrics', $post));

    $response->assertOk();
    $response->assertJsonPath('available', true);
    $response->assertJsonPath('metrics.impressions.value', 500);
    $response->assertJsonPath('metrics.impressions.unit', 'count');
    Http::assertNothingSent();
});

test('post metrics reads persisted TikTok analytics without a provider request', function () {
    $tiktokAccount = SocialAccount::factory()->tiktok()->create([
        'workspace_id' => $this->workspace->id,
        'username' => 'tiktoker',
        'token_expires_at' => now()->addDays(1),
    ]);

    $post = Post::factory()->forAccount($tiktokAccount)->tiktok()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'platform' => Platform::TikTok,
        'publish_status' => Status::Published,
        'platform_post_id' => '7685359243088103444',
    ]);

    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $this->workspace->id,
        'social_account_id' => $tiktokAccount->id,
        'social_account_key' => $tiktokAccount->id,
        'post_id' => $post->id,
        'platform' => Platform::TikTok,
        'network' => Platform::TikTok->network(),
        'remote_id' => '7685359243088103444',
        'content_type' => PublicationContentType::Video,
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $publication->id,
        'views_count' => 220,
        'reactions_count' => 11,
        'metrics' => ['views' => ['value' => 220, 'unit' => 'count', 'availability' => 'available']],
    ]);
    Http::fake();

    $response = $this->actingAs($this->user)
        ->getJson(route('app.posts.metrics', $post));

    $response->assertOk();
    $response->assertJsonPath('available', true);
    $response->assertJsonPath('metrics.views.value', 220);
    $response->assertJsonPath('metrics.views.unit', 'count');
    Http::assertNothingSent();
});

test('post metrics excludes LinkedIn profile in V1', function () {
    $linkedinAccount = SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $this->workspace->id,
        'token_expires_at' => now()->addDay(),
    ]);

    $post = Post::factory()->forAccount($linkedinAccount)->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'platform' => Platform::LinkedIn,
        'content_type' => ContentType::LinkedInPost,
        'publish_status' => Status::Published,
        'platform_post_id' => 'urn:li:share:7503082467755646976',
    ]);

    Http::fake();

    $response = $this->actingAs($this->user)
        ->getJson(route('app.posts.metrics', $post));

    $response->assertOk();
    $response->assertJsonPath('unsupported', true);
    $response->assertJsonPath('reason', 'platform_not_supported');
    Http::assertNothingSent();
});

test('post metrics excludes LinkedIn Page in V1', function () {
    $pageAccount = SocialAccount::factory()->linkedinPage()->create([
        'workspace_id' => $this->workspace->id,
        'token_expires_at' => now()->addDay(),
        'platform_user_id' => '99920311',
    ]);

    $post = Post::factory()->forAccount($pageAccount)->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'platform' => Platform::LinkedInPage,
        'content_type' => ContentType::LinkedInPagePost,
        'publish_status' => Status::Published,
        'platform_post_id' => 'urn:li:ugcPost:7504988143797075969',
    ]);

    Http::fake();

    $response = $this->actingAs($this->user)
        ->getJson(route('app.posts.metrics', $post));

    $response->assertOk();
    $response->assertJsonPath('unsupported', true);
    $response->assertJsonPath('reason', 'platform_not_supported');
    Http::assertNothingSent();
});

test('the standalone post page no longer exists', function () {
    expect(Route::has('app.posts.show'))->toBeFalse();
});

test('the post details deep link opens a published post on the sent tab', function () {
    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Published,
        'content' => 'Hello world',
        'platform_url' => 'https://linkedin.com/posts/abc',
    ]);

    Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Published,
    ]);

    $this->actingAs($this->user)
        ->get(route('app.posts.index', ['post' => $post->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('publish/Index', false)
            ->where('tab', 'sent')
            ->where('openPostDetailsId', $post->id)
            ->where('openPostNotesId', null)
            ->loadDeferredProps(fn ($reload) => $reload
                ->has('posts.data', 1)
                ->where('posts.data.0.id', $post->id)
                ->where('posts.data.0.social_account_id', $this->socialAccount->id))
        );
});

test('the post details deep link exposes the content type of the post', function () {
    $facebookAccount = SocialAccount::factory()->facebook()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $post = Post::factory()->forAccount($facebookAccount)->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Published,
        'platform' => Platform::Facebook,
        'content_type' => ContentType::FacebookReel,
    ]);

    $this->actingAs($this->user)
        ->get(route('app.posts.index', ['post' => $post->id]))
        ->assertInertia(fn ($page) => $page
            ->component('publish/Index', false)
            ->loadDeferredProps(fn ($reload) => $reload
                ->where('posts.data.0.content_type', ContentType::FacebookReel->value))
        );
});

test('the post details deep link picks the tab of the post status', function (PostStatus $status, string $tab) {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => $status,
    ]);

    $this->actingAs($this->user)
        ->get(route('app.posts.index', ['post' => $post->id]))
        ->assertInertia(fn ($page) => $page
            ->where('tab', $tab)
            ->where('openPostDetailsId', $post->id)
        );
})->with([
    'draft' => [PostStatus::Draft, 'drafts'],
    'scheduled' => [PostStatus::Scheduled, 'queue'],
    'pending approval' => [PostStatus::PendingApproval, 'approvals'],
    'failed' => [PostStatus::Failed, 'sent'],
]);

test('a scheduled legacy post without an enabled account opens in the post details', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Scheduled,
    ]);

    $this->actingAs($this->user)
        ->get(route('app.posts.edit', $post))
        ->assertRedirect(route('app.posts.index', ['post' => $post->id]));
});

test('destroy blocks published posts', function () {
    foreach ([PostStatus::Publishing, PostStatus::Published] as $status) {
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

test('the post details deep link does not expose a post from another workspace', function () {
    $otherWorkspace = Workspace::factory()->create();
    $post = Post::factory()->published()->create([
        'workspace_id' => $otherWorkspace->id,
        'user_id' => $this->user->id,
    ]);

    $this->actingAs($this->user)
        ->get(route('app.posts.index', ['post' => $post->id, 'tab' => 'sent']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('hasData', false)->has('posts.data', 0));
});

test('update post goes back to the queue after publishing without opening the post details', function () {
    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'content' => 'Test',
    ]);

    $response = $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'publishing',
        'content' => 'Test',
        'content_type' => ContentType::LinkedInPost->value,
    ]);

    $response->assertRedirect(route('app.posts.index', ['tab' => 'queue']));
});

test('update post rejects scheduling youtube short with image', function () {
    $youtubeAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::YouTube,
    ]);

    $post = Post::factory()->forAccount($youtubeAccount)->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'content' => 'Test',
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
        'content_type' => ContentType::YouTubeShort->value,
    ]);

    $response->assertSessionHasErrors('content_type');
});

test('update post rejects scheduling instagram reel with no media', function () {
    $instagramAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
    ]);

    $post = Post::factory()->forAccount($instagramAccount)->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'content' => 'Test',
    ]);

    $response = $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'scheduled',
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'content_type' => ContentType::InstagramReel->value,
    ]);

    $response->assertSessionHasErrors('content_type');
});

test('update post no longer stores an aspect_ratio meta for facebook or instagram', function (Platform $platform, ContentType $contentType, string $ratio) {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => $platform,
    ]);

    $post = Post::factory()->forAccount($account)->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'platform' => $platform,
        'meta' => [],
    ]);

    $response = $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'draft',
        'content_type' => $contentType->value,
        'meta' => ['aspect_ratio' => $ratio],
    ]);

    $response->assertSessionHasNoErrors();
    expect(data_get($post->fresh()->meta, 'aspect_ratio'))->toBeNull();
})->with([
    'instagram 4:5' => [Platform::Instagram, ContentType::InstagramFeed, '4:5'],
    'instagram unknown' => [Platform::Instagram, ContentType::InstagramFeed, '2:1'],
    'facebook 16:9' => [Platform::Facebook, ContentType::FacebookPost, '16:9'],
]);

test('scheduling without content_type per platform fails', function () {
    $youtubeAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::YouTube,
    ]);

    $post = Post::factory()->forAccount($youtubeAccount)->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'content' => 'Test',
    ]);

    $response = $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'scheduled',
        'scheduled_at' => now()->addDay()->toIso8601String(),
    ]);

    $response->assertSessionHasErrors('content_type');
});

test('draft post does not enforce media-vs-content-type compatibility', function () {
    $youtubeAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::YouTube,
    ]);

    $post = Post::factory()->forAccount($youtubeAccount)->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
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
        'content_type' => ContentType::YouTubeShort->value,
    ]);

    $response->assertSessionDoesntHaveErrors('content_type');
});

test('update post validates label_ids exist', function () {
    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);

    $response = $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'draft',
        'content_type' => ContentType::LinkedInPost->value,
        'label_ids' => ['non-existent-uuid'],
    ]);

    $response->assertSessionHasErrors('label_ids.0');
});

test('update post rejects a deleted label', function () {
    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    $label->delete();

    $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => 'draft',
        'content_type' => ContentType::LinkedInPost->value,
        'label_ids' => [$label->id],
    ])->assertSessionHasErrors('label_ids.0');

    expect($post->labels()->count())->toBe(0);
});

// Member authorization tests
test('member can view posts index', function () {
    $member = User::factory()->create([
        'account_id' => $this->workspace->account_id,
    ]);
    $this->workspace->members()->attach($member->id, membershipPivot('member'));
    $member->update(['current_workspace_id' => $this->workspace->id]);

    $response = $this->actingAs($member)->get(route('app.posts.index'));

    $response->assertOk();
});

test('member can create post', function () {
    $member = User::factory()->create([
        'account_id' => $this->workspace->account_id,
    ]);
    $this->workspace->members()->attach($member->id, membershipPivot('member'));
    $member->update(['current_workspace_id' => $this->workspace->id]);

    $response = $this->actingAs($member)->post(route('app.posts.store'));

    $response->assertRedirect();
});

test('the editor receives the tld list only while x link defusing is on', function (bool $enabled, bool $expectsList) {
    config()->set('trypost.platforms.x.defuse_links', $enabled);

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $this->actingAs($this->user)
        ->get(route('app.posts.index', ['edit' => $post->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('publish/Index')
            ->when($expectsList, fn ($page) => $page->where('xLinkTlds', fn (Collection $tlds): bool => $tlds->contains('com') && $tlds->count() === count(LinkTlds::all())))
            ->when(! $expectsList, fn ($page) => $page->missing('xLinkTlds'))
        );
})->with([
    'enabled' => [true, true],
    'disabled' => [false, false],
]);

test('composer data carries each channel posting schedule', function () {
    $scheduled = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::X,
        'posting_schedule' => PostingSchedule::empty()->withTime(1, '09:00'),
    ]);

    $accounts = $this->actingAs($this->user)
        ->get(route('app.posts.index'))
        ->assertOk()
        ->inertiaProps('composer.accounts');

    expect($accounts[$scheduled->id]['posting_schedule'][1])->toEqual(['day' => 1, 'enabled' => true, 'times' => ['09:00']])
        ->and($accounts[$this->socialAccount->id]['posting_schedule'])->toEqual($this->socialAccount->fresh()->posting_schedule?->toArray());
});

test('composer data lists the instants already scheduled on each channel', function () {
    $other = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::X]);
    $taken = Post::factory()->forAccount($this->socialAccount)->scheduled()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'scheduled_at' => now()->addDays(2)->startOfMinute(),
    ]);
    $draft = Post::factory()->forAccount($this->socialAccount)->draft()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'scheduled_at' => now()->addDays(3),
    ]);

    $takenSlotsOn = fn (SocialAccount $account): array => $this->actingAs($this->user)
        ->getJson(route('app.posts.composer.taken-slots', ['account' => $account, 'from' => now()->addDays(2)->startOfDay()->toIso8601String(), 'to' => now()->addDays(4)->startOfDay()->toIso8601String()]))
        ->assertOk()
        ->json('takenSlots');

    expect($takenSlotsOn($this->socialAccount))->toBe([$taken->scheduled_at->toIso8601ZuluString()])
        ->and($takenSlotsOn($other))->toBe([]);
});

test('channel filters do not carry posting schedules', function () {
    $this->socialAccount->update(['posting_schedule' => PostingSchedule::empty()->withTime(1, '09:00')]);

    $this->actingAs($this->user)
        ->get(route('app.posts.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filterAccounts.0.has_posting_schedule', true)
            ->missing('filterAccounts.0.posting_schedule'));
});

test('composer data tells each channel time zone', function () {
    $tokyo = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::X,
        'timezone' => 'Asia/Tokyo',
    ]);

    $channels = collect($this->actingAs($this->user)
        ->get(route('app.posts.index'))
        ->assertOk()
        ->inertiaProps('channels'))->keyBy('id');

    expect($channels[$tokyo->id]['timezone'])->toBe('Asia/Tokyo');
});

function draftTabPost(mixed $test, array $attributes = []): Post
{
    $post = Post::factory()->forAccount($test->socialAccount)->create([
        'workspace_id' => $test->workspace->id,
        'user_id' => $test->user->id,
        'status' => PostStatus::Draft,
        'content' => 'Test',
        ...$attributes,
    ]);

    return $post;
}

test('saving a draft from the editor on the publish list goes to the drafts tab', function (string $from) {
    $post = draftTabPost($this);

    $this->actingAs($this->user)
        ->from(route('app.posts.index', ['tab' => $from, 'edit' => $post->id]))
        ->put(route('app.posts.update', $post), [
            'status' => 'draft',
            'content' => 'Edited',
            'content_type' => ContentType::LinkedInPost->value,
        ])
        ->assertRedirect(route('app.posts.index', ['tab' => 'drafts']));
})->with(['queue', 'drafts', 'sent']);

test('moving a scheduled post to drafts from the editor goes to the drafts tab', function () {
    $post = draftTabPost($this, ['status' => PostStatus::Scheduled, 'scheduled_at' => now()->addDay()]);

    $this->actingAs($this->user)
        ->from(route('app.posts.index', ['tab' => 'queue', 'edit' => $post->id]))
        ->put(route('app.posts.update', $post), [
            'status' => 'draft',
            'content' => 'Test',
            'content_type' => ContentType::LinkedInPost->value,
        ])
        ->assertRedirect(route('app.posts.index', ['tab' => 'drafts']));

    expect($post->fresh()->status)->toBe(PostStatus::Draft);
});

test('saving a draft from a channel page goes to that channel drafts tab', function () {
    $post = draftTabPost($this);

    $this->actingAs($this->user)
        ->from(route('app.channels.publish', ['account' => $this->socialAccount, 'tab' => 'queue', 'edit' => $post->id]))
        ->put(route('app.posts.update', $post), [
            'status' => 'draft',
            'content' => 'Edited',
            'content_type' => ContentType::LinkedInPost->value,
        ])
        ->assertRedirect(route('app.channels.publish', ['account' => $this->socialAccount, 'tab' => 'drafts']));
});

test('saving a draft from the calendar stays on the calendar', function () {
    $post = draftTabPost($this);
    $calendar = route('app.calendar', ['view' => 'month']);

    $this->actingAs($this->user)
        ->from($calendar)
        ->put(route('app.posts.update', $post), [
            'status' => 'draft',
            'content' => 'Edited',
            'content_type' => ContentType::LinkedInPost->value,
        ])
        ->assertRedirect($calendar);
});

test('a new draft goes to the drafts tab, from the publish list or anywhere else', function (?string $from) {
    $request = $this->actingAs($this->user);

    if ($from !== null) {
        $request = $request->from($from);
    }

    $request->post(route('app.posts.store'), [
        'status' => 'draft',
        'content' => 'New draft',
        'destinations' => [['social_account_id' => $this->socialAccount->id, 'content_type' => ContentType::LinkedInPost->value]],
    ])->assertRedirect(route('app.posts.index', ['tab' => 'drafts']));
})->with([
    'the queue tab' => fn () => route('app.posts.index', ['tab' => 'queue', 'compose' => 1]),
    'insights' => fn () => route('app.insights'),
]);

test('every way of scheduling from the editor lands on the queue tab', function (array $schedule) {
    $this->socialAccount->update(['posting_schedule' => PostingSchedule::empty()->withTime(1, '10:00')]);

    $this->actingAs($this->user)
        ->from(route('app.posts.index', ['tab' => 'drafts', 'compose' => 1]))
        ->post(route('app.posts.store'), [
            'content' => 'Scheduled caption',
            'media' => [],
            ...$schedule,
            'destinations' => [['social_account_id' => $this->socialAccount->id, 'content_type' => ContentType::LinkedInPost->value, 'meta' => []]],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('app.posts.index', ['tab' => 'queue']));
})->with([
    'now' => [['status' => 'publishing']],
    'next free slot' => [['status' => 'scheduled', 'queue' => 'next']],
    'top of the queue' => [['status' => 'scheduled', 'queue' => 'top']],
    'custom time' => [['status' => 'scheduled', 'scheduled_at' => '2037-01-01T10:00:00Z']],
]);

test('scheduling an existing post from the editor lands on the queue tab', function () {
    $post = draftTabPost($this);

    $this->actingAs($this->user)
        ->from(route('app.posts.index', ['tab' => 'drafts', 'edit' => $post->id]))
        ->put(route('app.posts.update', $post), [
            'status' => 'scheduled',
            'scheduled_at' => '2037-01-01T10:00:00Z',
            'content' => 'Test',
            'content_type' => ContentType::LinkedInPost->value,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('app.posts.index', ['tab' => 'queue']));
});
