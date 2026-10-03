<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;
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
    $post = Post::factory()->create([
        'workspace_id' => $channel->workspace_id,
        'user_id' => $channel->workspace->user_id,
        'status' => $status,
        'content' => 'Caption',
    ]);

    PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $channel->id,
        'platform' => $channel->platform,
    ]);

    return $post;
}

/**
 * @return array<string, mixed>
 */
function publishRedirectUpdatePayload(Post $post, string $status): array
{
    return [
        'status' => $status,
        'content' => 'Edited caption',
        'platforms' => [
            ['id' => $post->postPlatforms->first()->id, 'content_type' => ContentType::LinkedInPost->value],
        ],
        ...($status === 'scheduled' ? ['scheduled_at' => now()->addDay()->toIso8601String()] : []),
    ];
}

test('scheduling an edit from the channel page returns to that page without the edit param', function () {
    $post = publishRedirectPost($this->channel);

    $this->actingAs($this->user)
        ->from(route('app.channels.publish', [$this->channel, 'tab' => 'drafts', 'tz' => 'UTC', 'edit' => $post->id]))
        ->put(route('app.posts.update', $post), publishRedirectUpdatePayload($post, 'scheduled'))
        ->assertRedirect(route('app.channels.publish', [$this->channel, 'tab' => 'drafts', 'tz' => 'UTC']))
        ->assertSessionHas('flash.banner', __('posts.flash.scheduled'));

    expect($post->fresh()->status)->toBe(PostStatus::Scheduled);
});

test('saving a draft from the all channels page keeps the tab and filters but drops the edit param', function () {
    $post = publishRedirectPost($this->channel);

    $this->actingAs($this->user)
        ->from(route('app.posts.index', ['tab' => 'drafts', 'channels' => [$this->channel->id], 'edit' => $post->id]))
        ->put(route('app.posts.update', $post), publishRedirectUpdatePayload($post, 'draft'))
        ->assertRedirect(route('app.posts.index', ['tab' => 'drafts', 'channels' => [$this->channel->id]]));
});

test('scheduling an edit outside the publish page still lands on the post', function () {
    $post = publishRedirectPost($this->channel);

    $this->actingAs($this->user)
        ->from(route('app.calendar'))
        ->put(route('app.posts.update', $post), publishRedirectUpdatePayload($post, 'scheduled'))
        ->assertRedirect(route('app.posts.index', ['post' => $post->id]));
});

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

test('creating from outside the publish page still lands on the all channels page', function () {
    $this->actingAs($this->user)
        ->from(route('app.calendar'))
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
        ->assertRedirect(route('app.posts.index'));
});

test('deleting from the channel page returns to that page', function () {
    $post = publishRedirectPost($this->channel);

    $this->actingAs($this->user)
        ->from(route('app.channels.publish', [$this->channel, 'tab' => 'drafts']))
        ->delete(route('app.posts.destroy', $post))
        ->assertRedirect(route('app.channels.publish', [$this->channel, 'tab' => 'drafts']))
        ->assertSessionHas('flash.banner', __('posts.flash.deleted'));

    expect(Post::find($post->id))->toBeNull();
});

test('deleting from outside the publish page falls back to the all channels page', function () {
    $post = publishRedirectPost($this->channel);

    $this->actingAs($this->user)
        ->from(route('app.settings.preferences'))
        ->delete(route('app.posts.destroy', $post))
        ->assertRedirect(route('app.posts.index'));
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
