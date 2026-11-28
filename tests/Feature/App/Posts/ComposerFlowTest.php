<?php

declare(strict_types=1);

use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Jobs\PublishPost;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00', 'UTC'));

    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);

    $this->linkedin = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
    ]);
});

/**
 * @param  array<string, mixed>  $meta
 * @return array<string, mixed>
 */
function composerFlowDestination(SocialAccount $account, ContentType $contentType, array $meta = [], ?string $content = null): array
{
    return array_filter([
        'social_account_id' => $account->id,
        'content_type' => $contentType->value,
        'meta' => $meta,
        'content' => $content,
    ], fn (mixed $value): bool => $value !== null);
}

/**
 * @param  list<array<string, mixed>>  $destinations
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function composerFlowPayload(array $destinations, array $overrides = []): array
{
    return [
        'status' => 'scheduled',
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'content' => 'Composer caption',
        'media' => [],
        'destinations' => $destinations,
        ...$overrides,
    ];
}

function composerFlowPost(Workspace $workspace, User $author, SocialAccount $account, array $attributes = []): Post
{
    return Post::factory()->forAccount($account, ContentType::LinkedInPost)->create([
        'user_id' => $author->id,
        'content' => 'Existing caption',
        'status' => PostStatus::Scheduled,
        'schedule_mode' => ScheduleMode::Custom,
        'scheduled_at' => now()->addDay(),
        ...$attributes,
    ]);
}

test('a user outside the workspace cannot create, edit, duplicate or delete a post', function () {
    $outsider = workspaceOutsider($this->workspace);
    $post = composerFlowPost($this->workspace, $this->owner, $this->linkedin);

    $this->actingAs($outsider)
        ->post(route('app.posts.store'), composerFlowPayload([composerFlowDestination($this->linkedin, ContentType::LinkedInPost)]))
        ->assertForbidden();
    $this->actingAs($outsider)
        ->put(route('app.posts.update', $post), [
            'status' => 'draft',
            'content' => 'Hijacked',
            'content_type' => ContentType::LinkedInPost->value,
        ])
        ->assertForbidden();
    $this->actingAs($outsider)->post(route('app.posts.duplicate', $post))->assertForbidden();
    $this->actingAs($outsider)->delete(route('app.posts.destroy', $post))->assertForbidden();

    expect(Post::query()->where('workspace_id', $this->workspace->id)->count())->toBe(1)
        ->and($post->fresh()->content)->toBe('Existing caption')
        ->and($post->fresh()->status)->toBe(PostStatus::Scheduled);
});

test('a post of another workspace cannot be edited, duplicated or deleted from the composer', function () {
    $foreignOwner = User::factory()->create();
    $foreignWorkspace = Workspace::factory()->create(['account_id' => $foreignOwner->account_id, 'user_id' => $foreignOwner->id]);
    $foreignAccount = SocialAccount::factory()->create(['workspace_id' => $foreignWorkspace->id, 'platform' => Platform::LinkedIn]);
    $post = composerFlowPost($foreignWorkspace, $foreignOwner, $foreignAccount);

    $this->actingAs($this->owner)
        ->put(route('app.posts.update', $post), [
            'status' => 'draft',
            'content' => 'Hijacked',
            'content_type' => ContentType::LinkedInPost->value,
        ])
        ->assertNotFound();
    $this->actingAs($this->owner)->post(route('app.posts.duplicate', $post))->assertNotFound();
    $this->actingAs($this->owner)->delete(route('app.posts.destroy', $post))->assertNotFound();

    expect(Post::query()->where('workspace_id', $this->workspace->id)->count())->toBe(0)
        ->and($post->fresh()->content)->toBe('Existing caption');
});

test('a channel of another workspace is refused and nothing is stored', function () {
    $foreignAccount = SocialAccount::factory()->create(['workspace_id' => Workspace::factory()->create()->id, 'platform' => Platform::LinkedIn]);

    $this->actingAs($this->owner)
        ->post(route('app.posts.store'), composerFlowPayload([
            composerFlowDestination($this->linkedin, ContentType::LinkedInPost),
            composerFlowDestination($foreignAccount, ContentType::LinkedInPost),
        ]))
        ->assertSessionHasErrors(['destinations.1.social_account_id']);

    expect(Post::query()->count())->toBe(0);
});

test('a custom time already in the past is refused when scheduling a new post', function () {
    $this->actingAs($this->owner)
        ->post(route('app.posts.store'), composerFlowPayload(
            [composerFlowDestination($this->linkedin, ContentType::LinkedInPost)],
            ['scheduled_at' => now()->subMinute()->toIso8601String()],
        ))
        ->assertSessionHasErrors(['scheduled_at']);

    expect(Post::query()->count())->toBe(0);
});

test('a custom time past the 2038 ceiling is refused when scheduling a new post', function () {
    $this->actingAs($this->owner)
        ->post(route('app.posts.store'), composerFlowPayload(
            [composerFlowDestination($this->linkedin, ContentType::LinkedInPost)],
            ['scheduled_at' => '2038-02-01T10:00:00Z'],
        ))
        ->assertSessionHasErrors(['scheduled_at']);

    expect(Post::query()->count())->toBe(0);
});

test('a label of another workspace or a deleted label is refused when creating a post', function (Closure $label) {
    $this->actingAs($this->owner)
        ->post(route('app.posts.store'), composerFlowPayload(
            [composerFlowDestination($this->linkedin, ContentType::LinkedInPost)],
            ['status' => 'draft', 'scheduled_at' => null, 'label_ids' => [$label($this->workspace)->id]],
        ))
        ->assertSessionHasErrors(['label_ids.0']);

    expect(Post::query()->count())->toBe(0);
})->with([
    'another workspace label' => [fn (Workspace $workspace): WorkspaceLabel => WorkspaceLabel::factory()->create(['workspace_id' => Workspace::factory()->create()->id])],
    'a deleted label' => [function (Workspace $workspace): WorkspaceLabel {
        $label = WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id]);
        $label->delete();

        return $label;
    }],
]);

test('labels picked in the composer land on every post created', function () {
    $other = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn]);
    $labels = WorkspaceLabel::factory()->count(2)->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->owner)
        ->post(route('app.posts.store'), composerFlowPayload(
            [composerFlowDestination($this->linkedin, ContentType::LinkedInPost), composerFlowDestination($other, ContentType::LinkedInPost)],
            ['label_ids' => $labels->pluck('id')->all()],
        ))
        ->assertSessionHasNoErrors();

    $posts = Post::query()->where('workspace_id', $this->workspace->id)->with('labels')->get();

    expect($posts)->toHaveCount(2);
    foreach ($posts as $post) {
        expect($post->labels->pluck('id')->all())->toEqualCanonicalizing($labels->pluck('id')->all());
    }
});

test('each network keeps its own text and settings when the post is customized per network', function () {
    $x = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->owner)
        ->post(route('app.posts.store'), composerFlowPayload([
            composerFlowDestination($this->linkedin, ContentType::LinkedInPost, ['link_preview' => false], 'Long form for LinkedIn'),
            composerFlowDestination($x, ContentType::XPost, [], 'Short for X'),
        ]))
        ->assertSessionHasNoErrors();

    $linkedinPost = Post::query()->where('social_account_id', $this->linkedin->id)->sole();
    $xPost = Post::query()->where('social_account_id', $x->id)->sole();

    expect($linkedinPost->content)->toBe('Long form for LinkedIn')
        ->and($linkedinPost->meta)->toEqual(['link_preview' => false])
        ->and($xPost->content)->toBe('Short for X')
        ->and($xPost->post_group_id)->toBe($linkedinPost->post_group_id)
        ->and($xPost->scheduled_at->equalTo($linkedinPost->scheduled_at))->toBeTrue();
});

test('publishing now from the composer queues one publish job per channel', function () {
    $other = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn]);

    $this->actingAs($this->owner)
        ->post(route('app.posts.store'), composerFlowPayload(
            [composerFlowDestination($this->linkedin, ContentType::LinkedInPost), composerFlowDestination($other, ContentType::LinkedInPost)],
            ['status' => 'publishing', 'scheduled_at' => null],
        ))
        ->assertSessionHasNoErrors();

    $posts = Post::query()->where('workspace_id', $this->workspace->id)->get();

    expect($posts)->toHaveCount(2)
        ->and($posts->pluck('status')->unique()->all())->toBe([PostStatus::Publishing]);
    Queue::assertPushed(PublishPost::class, 2);
});

test('instagram takes five hashtags when scheduling, refuses six, and a draft keeps six', function () {
    $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    $five = 'Caption #one #two #three #four #five';
    $six = "{$five} #six";
    $message = __('posts.form.hashtags_exceed_platform', ['platform' => Platform::Instagram->label(), 'limit' => 5]);
    $destination = [composerFlowDestination($instagram, ContentType::InstagramFeed)];

    $this->actingAs($this->owner)
        ->post(route('app.posts.store'), composerFlowPayload($destination, ['content' => $six]))
        ->assertSessionHasErrors(['destinations.0.content' => $message]);

    $this->actingAs($this->owner)
        ->post(route('app.posts.store'), composerFlowPayload($destination, ['content' => $five]))
        ->assertSessionDoesntHaveErrors(['destinations.0.content']);

    $this->actingAs($this->owner)
        ->post(route('app.posts.store'), composerFlowPayload($destination, ['status' => 'draft', 'scheduled_at' => null, 'content' => $six]))
        ->assertSessionHasNoErrors();

    expect(Post::query()->where('workspace_id', $this->workspace->id)->sole()->content)->toBe($six);
});

test('an x account is measured against its own tier when scheduling from the composer', function (array $meta, bool $accepted) {
    $x = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id, 'meta' => $meta]);

    $response = $this->actingAs($this->owner)
        ->post(route('app.posts.store'), composerFlowPayload(
            [composerFlowDestination($x, ContentType::XPost)],
            ['content' => str_repeat('a', 281)],
        ));

    $accepted
        ? $response->assertSessionHasNoErrors()
        : $response->assertSessionHasErrors(['destinations.0.content' => __('posts.form.content_exceeds_platform', ['platform' => Platform::X->label(), 'limit' => 280, 'over' => 1])]);
    expect(Post::query()->where('workspace_id', $this->workspace->id)->count())->toBe($accepted ? 1 : 0);
})->with([
    'free account' => [[], false],
    'premium account' => [['x_subscription_type' => 'Premium'], true],
]);

test('the mastodon content warning counts against the 500 characters in the composer', function () {
    $mastodon = SocialAccount::factory()->mastodon()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->owner)
        ->post(route('app.posts.store'), composerFlowPayload(
            [composerFlowDestination($mastodon, ContentType::MastodonPost, ['spoiler_text' => 'cw'])],
            ['content' => str_repeat('a', 499)],
        ))
        ->assertSessionHasErrors(['destinations.0.content']);

    $this->actingAs($this->owner)
        ->post(route('app.posts.store'), composerFlowPayload(
            [composerFlowDestination($mastodon, ContentType::MastodonPost, ['spoiler_text' => 'cw'])],
            ['content' => str_repeat('a', 498)],
        ))
        ->assertSessionHasNoErrors();

    expect(Post::query()->where('workspace_id', $this->workspace->id)->sole()->meta['spoiler_text'])->toBe('cw');
});

test('an x thread from the composer stores its replies as text objects and refuses a reply over the account limit', function () {
    $x = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->owner)
        ->post(route('app.posts.store'), composerFlowPayload(
            [composerFlowDestination($x, ContentType::XPost, ['thread_replies' => ['Second', str_repeat('a', 281)]])],
            ['content' => 'Root'],
        ))
        ->assertSessionHasErrors();

    expect(Post::query()->count())->toBe(0);

    $this->actingAs($this->owner)
        ->post(route('app.posts.store'), composerFlowPayload(
            [composerFlowDestination($x, ContentType::XPost, ['thread_replies' => ['Second', ['text' => 'Third', 'media' => []]]])],
            ['content' => 'Root'],
        ))
        ->assertSessionHasNoErrors();

    expect(Post::query()->sole()->meta['thread_replies'])->toEqual([
        ['text' => 'Second', 'media' => []],
        ['text' => 'Third', 'media' => []],
    ]);
});

test('editing an x thread from the composer refuses a reply over the account limit and keeps the stored thread', function () {
    $x = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->forAccount($x, ContentType::XPost)->create([
        'user_id' => $this->owner->id,
        'content' => 'Root',
        'status' => PostStatus::Scheduled,
        'schedule_mode' => ScheduleMode::Custom,
        'scheduled_at' => now()->addDay(),
        'meta' => ['thread_replies' => [['text' => 'Second', 'media' => []]]],
    ]);

    $this->actingAs($this->owner)
        ->put(route('app.posts.update', $post), [
            'status' => 'scheduled',
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'content' => 'Root',
            'content_type' => ContentType::XPost->value,
            'meta' => ['thread_replies' => ['Second', str_repeat('a', 281)]],
        ])
        ->assertSessionHasErrors();

    expect($post->fresh()->meta['thread_replies'])->toEqual([['text' => 'Second', 'media' => []]]);
});

test('a member who needs approval duplicating a scheduled post gets a draft, never a request', function () {
    $requester = workspaceMember($this->workspace, 'approval');
    $post = composerFlowPost($this->workspace, $this->owner, $this->linkedin);

    $this->actingAs($requester)->post(route('app.posts.duplicate', $post))->assertRedirect();

    $copy = Post::query()->whereKeyNot($post->id)->sole();

    expect($copy->status)->toBe(PostStatus::Draft)
        ->and($copy->user_id)->toBe($requester->id)
        ->and($copy->scheduled_at)->toBeNull()
        ->and($copy->approval_requested_at)->toBeNull()
        ->and($copy->content)->toBe('Existing caption');
});

test('a member who needs approval cannot duplicate another member pending request', function () {
    $requester = workspaceMember($this->workspace, 'approval');
    $otherRequester = workspaceMember($this->workspace, 'approval');
    $pending = composerFlowPost($this->workspace, $otherRequester, $this->linkedin, [
        'status' => PostStatus::PendingApproval,
        'approval_requested_at' => now(),
        'approval_requested_by' => $otherRequester->id,
    ]);

    $this->actingAs($requester)->post(route('app.posts.duplicate', $pending))->assertNotFound();

    expect(Post::query()->count())->toBe(1);
});

test('a network setting a channel needs to publish is required when scheduling from the composer and optional on a draft', function (string $factoryState, ContentType $contentType, string $field) {
    $account = SocialAccount::factory()->{$factoryState}()->create(['workspace_id' => $this->workspace->id]);
    $destination = [composerFlowDestination($account, $contentType)];

    $this->actingAs($this->owner)
        ->post(route('app.posts.store'), composerFlowPayload($destination))
        ->assertSessionHasErrors(["destinations.0.meta.{$field}"]);

    $this->actingAs($this->owner)
        ->post(route('app.posts.store'), composerFlowPayload($destination, ['status' => 'draft', 'scheduled_at' => null]))
        ->assertSessionHasNoErrors();

    expect(Post::query()->where('workspace_id', $this->workspace->id)->sole()->status)->toBe(PostStatus::Draft);
})->with([
    'pinterest board' => ['pinterest', ContentType::PinterestPin, 'board_id'],
    'discord channel' => ['discord', ContentType::DiscordMessage, 'channel_id'],
    'tiktok privacy level' => ['tiktok', ContentType::TikTokVideo, 'privacy_level'],
]);

test('a youtube short with neither text nor a title is refused when scheduling from the composer', function () {
    $youtube = SocialAccount::factory()->youtube()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->owner)
        ->post(route('app.posts.store'), composerFlowPayload([composerFlowDestination($youtube, ContentType::YouTubeShort)], ['content' => '']))
        ->assertSessionHasErrors(['destinations.0.meta.title']);

    $this->actingAs($this->owner)
        ->post(route('app.posts.store'), composerFlowPayload([composerFlowDestination($youtube, ContentType::YouTubeShort, ['title' => 'My short'])], ['content' => '']))
        ->assertSessionDoesntHaveErrors(['destinations.0.meta.title']);
});
