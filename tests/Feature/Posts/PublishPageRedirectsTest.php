<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostingSchedule;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $this->channel = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id]);
});

function publishRedirectPost(SocialAccount $channel, PostStatus $status = PostStatus::Draft): Post
{
    return Post::factory()->forAccount($channel)->create([
        'user_id' => $channel->workspace->user_id,
        'status' => $status,
        'content' => 'Caption',
    ]);
}

function publishRedirectOrigin(string $origin, SocialAccount $channel): string
{
    return match ($origin) {
        'calendar week' => route('app.calendar', ['view' => 'week', 'week' => '2026-10-25', 'slots' => 1]),
        'calendar month' => route('app.calendar', ['view' => 'month', 'month' => '2026-10-01', 'channels' => $channel->id]),
        'channel calendar' => route('app.channels.calendar', ['account' => $channel, 'view' => 'days', 'day' => '2026-10-27']),
        'channel grid' => route('app.channels.grid', ['account' => $channel]),
        'insights' => route('app.insights', ['range' => '30d']),
        'ideas' => route('app.create.ideas.index'),
    };
}

/**
 * @return array<string, mixed>
 */
function publishRedirectUpdatePayload(Post $post, string $status): array
{
    return [
        'status' => $status,
        'content' => 'Edited caption',
        'content_type' => ContentType::LinkedInPost->value,
        ...($status === 'scheduled' ? ['scheduled_at' => now()->addDay()->toIso8601String()] : []),
    ];
}

test('scheduling an edit from the channel page returns to that page queue tab without the edit param', function () {
    $post = publishRedirectPost($this->channel);

    $this->actingAs($this->user)
        ->from(route('app.channels.publish', [$this->channel, 'tab' => 'drafts', 'tz' => 'UTC', 'edit' => $post->id]))
        ->put(route('app.posts.update', $post), publishRedirectUpdatePayload($post, 'scheduled'))
        ->assertRedirect(route('app.channels.publish', [$this->channel, 'tab' => 'queue', 'tz' => 'UTC']))
        ->assertSessionMissing('flash.banner');

    expect($post->fresh()->status)->toBe(PostStatus::Scheduled);
});

test('saving a draft from the all channels page keeps the tab and filters but drops the edit param', function () {
    $post = publishRedirectPost($this->channel);

    $this->actingAs($this->user)
        ->from(route('app.posts.index', ['tab' => 'drafts', 'channels' => [$this->channel->id], 'edit' => $post->id]))
        ->put(route('app.posts.update', $post), publishRedirectUpdatePayload($post, 'draft'))
        ->assertRedirect(route('app.posts.index', ['tab' => 'drafts', 'channels' => [$this->channel->id]]));
});

test('saving an edit from another page of the app returns to that page as it was', function (string $status, string $origin) {
    $post = publishRedirectPost($this->channel);
    $from = publishRedirectOrigin($origin, $this->channel);

    $this->actingAs($this->user)
        ->from($from)
        ->put(route('app.posts.update', $post), publishRedirectUpdatePayload($post, $status))
        ->assertSessionHasNoErrors()
        ->assertRedirect($from);
})->with(['draft', 'scheduled'])->with(['calendar week', 'insights']);

test('returning to the publish page drops the post details deep link', function () {
    $post = publishRedirectPost($this->channel);

    $this->actingAs($this->user)
        ->from(route('app.posts.index', ['tab' => 'drafts', 'post' => $post->id]))
        ->put(route('app.posts.update', $post), publishRedirectUpdatePayload($post, 'draft'))
        ->assertRedirect(route('app.posts.index', ['tab' => 'drafts']));
});

test('creating from the channel page returns to that page', function () {
    $this->actingAs($this->user)
        ->from(route('app.channels.publish', [$this->channel, 'tab' => 'drafts']))
        ->post(route('app.posts.store'), [
            'status' => 'draft',
            'content' => 'New caption',
            'media' => [],
            'destinations' => [[
                'social_account_id' => $this->channel->id,
                'content_type' => ContentType::LinkedInPost->value,
                'meta' => [],
            ]],
        ])
        ->assertRedirect(route('app.channels.publish', [$this->channel, 'tab' => 'drafts']))
        ->assertSessionHas('created_post_ids');
});

test('a post created from another page of the app returns to that page as it was', function (array $schedule, string $origin) {
    $this->channel->update(['posting_schedule' => PostingSchedule::empty()->withTime(1, '10:00')]);
    $from = publishRedirectOrigin($origin, $this->channel);

    $this->actingAs($this->user)
        ->from($from)
        ->post(route('app.posts.store'), [
            'content' => 'New caption',
            'media' => [],
            ...$schedule,
            'destinations' => [[
                'social_account_id' => $this->channel->id,
                'content_type' => ContentType::LinkedInPost->value,
                'meta' => [],
            ]],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect($from);
})->with([
    'a draft' => [['status' => 'draft']],
    'a scheduled post' => [['status' => 'scheduled', 'scheduled_at' => '2037-01-01T10:00:00Z']],
    'a queued post' => [['status' => 'scheduled', 'queue' => 'next']],
])->with(['calendar week', 'calendar month', 'channel calendar', 'channel grid', 'insights', 'ideas']);

test('a page named by a referer on another host is returned to on this app', function () {
    $this->actingAs($this->user)
        ->withHeader('referer', 'https://evil.example/schedule/calendar/week?week=2026-10-25')
        ->post(route('app.posts.store'), [
            'status' => 'draft',
            'content' => 'New caption',
            'destinations' => [['social_account_id' => $this->channel->id, 'content_type' => ContentType::LinkedInPost->value]],
        ])
        ->assertRedirect(route('app.calendar', ['view' => 'week', 'week' => '2026-10-25']));
});

test('a page with a dotted query key is returned to with that key as it was', function () {
    $post = publishRedirectPost($this->channel);
    $from = route('app.create.ideas.index', ['columns.unassigned_page' => 2]);

    $this->actingAs($this->user)
        ->from($from)
        ->put(route('app.posts.update', $post), publishRedirectUpdatePayload($post, 'draft'))
        ->assertSessionHasNoErrors()
        ->assertRedirect($from);
});

test('on an app served from a subfolder a page is returned to without doubling the base path', function (string $origin, array $originQuery, array $expectedQuery) {
    $post = publishRedirectPost($this->channel);
    $update = route('app.posts.update', $post);
    $root = rtrim(config('app.url'), '/').'/trypost';
    URL::forceRootUrl($root);
    $origin = match ($origin) {
        'calendar' => route('app.calendar', $originQuery),
        'all channels' => route('app.posts.index', $originQuery),
    };

    $this->actingAs($this->user)
        ->withServerVariables(['SCRIPT_NAME' => '/trypost/index.php', 'SCRIPT_FILENAME' => public_path('index.php')])
        ->from($origin)
        ->put($update, publishRedirectUpdatePayload($post, 'draft'))
        ->assertSessionHasNoErrors()
        ->assertRedirect(blank($expectedQuery) ? $origin : route('app.posts.index', $expectedQuery));
})->with([
    'calendar' => ['calendar', ['view' => 'week', 'week' => '2026-10-25'], []],
    'all channels' => ['all channels', ['tab' => 'drafts', 'edit' => 'x'], ['tab' => 'drafts']],
]);

test('deleting from the channel page returns to that page', function () {
    $post = publishRedirectPost($this->channel);

    $this->actingAs($this->user)
        ->from(route('app.channels.publish', [$this->channel, 'tab' => 'drafts']))
        ->delete(route('app.posts.destroy', $post))
        ->assertRedirect(route('app.channels.publish', [$this->channel, 'tab' => 'drafts']))
        ->assertSessionMissing('flash.banner');

    expect(Post::find($post->id))->toBeNull();
});

test('deleting from another page of the app returns to that page', function () {
    $post = publishRedirectPost($this->channel);

    $this->actingAs($this->user)
        ->from(route('app.settings.preferences'))
        ->delete(route('app.posts.destroy', $post))
        ->assertRedirect(route('app.settings.preferences'));
});

test('deleting from a calendar returns to that calendar as it was', function (string $route) {
    $post = publishRedirectPost($this->channel);
    $calendar = $route === 'app.calendar'
        ? route($route, ['view' => 'month', 'month' => '2026-11-01', 'status' => 'scheduled'])
        : route($route, ['account' => $this->channel, 'view' => 'month', 'month' => '2026-11-01']);

    $this->actingAs($this->user)
        ->from($calendar)
        ->delete(route('app.posts.destroy', $post))
        ->assertRedirect($calendar);

    expect(Post::find($post->id))->toBeNull();
})->with(['app.calendar', 'app.channels.calendar']);

test('duplicating from the channel page opens the copy on that page', function () {
    $post = publishRedirectPost($this->channel, PostStatus::Scheduled);

    $response = $this->actingAs($this->user)
        ->from(route('app.channels.publish', [$this->channel, 'tab' => 'queue', 'edit' => $post->id]))
        ->post(route('app.posts.duplicate', $post));

    $copy = Post::query()->whereKeyNot($post->id)->sole();

    $response->assertRedirect(route('app.channels.publish', [$this->channel, 'tab' => 'queue', 'edit' => $copy->id]));
});

test('duplicating outside the publish page opens the copy through its edit route', function () {
    $post = publishRedirectPost($this->channel, PostStatus::Scheduled);

    $response = $this->actingAs($this->user)
        ->from(route('app.calendar'))
        ->post(route('app.posts.duplicate', $post));

    $copy = Post::query()->whereKeyNot($post->id)->sole();

    $response->assertRedirect(route('app.posts.edit', $copy));
});

test('a referer on another host never becomes the redirect target', function () {
    $post = publishRedirectPost($this->channel);

    $this->actingAs($this->user)
        ->withHeader('referer', 'https://evil.example/schedule?tab=drafts')
        ->delete(route('app.posts.destroy', $post))
        ->assertRedirect(route('app.posts.index', ['tab' => 'drafts']));
});

test('a malformed edit id is ignored instead of failing the page', function () {
    $this->actingAs($this->user)
        ->get(route('app.posts.index', ['edit' => 'abc']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('publish/Index')
            ->where('openComposer', false)
            ->where('editPost', null));
});
