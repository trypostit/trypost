<?php

declare(strict_types=1);

use App\Actions\Post\DeleteChannelPosts;
use App\Enums\Post\Status;
use App\Enums\SocialAccount\Platform;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\CreatePostsTool;
use App\Mcp\Tools\Post\CreatePostTool;
use App\Mcp\Tools\Post\DeletePostTool;
use App\Mcp\Tools\Post\GetPostTool;
use App\Mcp\Tools\Post\ListPostsTool;
use App\Mcp\Tools\Post\PreviewPostTool;
use App\Mcp\Tools\Post\UpdatePostTool;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\WorkspaceLabel;
use App\Support\Requests\Post\PostRequestRules;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use Illuminate\Testing\Fluent\AssertableJson;
use Inertia\Testing\AssertableInertia;

function postsParitySnapshot(Post $post): array
{
    $post->loadMissing('labels');

    return [
        'status' => $post->status,
        'content' => $post->content,
        'schedule_mode' => $post->schedule_mode,
        'scheduled' => $post->scheduled_at !== null,
        'channels' => [$post->social_account_id],
        'content_types' => [$post->content_type],
        'labels' => $post->labels->pluck('id')->sort()->values()->all(),
    ];
}

beforeEach(function () {
    ['user' => $this->user, 'workspace' => $this->workspace, 'token' => $this->token] = parityContext();
    $this->account = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn]);
    $this->second = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::X]);
});

test('a draft created through the api and through mcp is stored the same way', function () {
    $this->withHeaders(parityApi($this->token))
        ->postJson(route('api.posts.store'), [
            'content' => 'Parity draft',
            'status' => 'draft',
            'social_account_id' => $this->account->id,
            'content_type' => 'linkedin_post',
        ])->assertCreated();

    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, [
        'content' => 'Parity draft',
        'social_account_id' => $this->account->id,
        'content_type' => 'linkedin_post',
    ])->assertOk();

    $posts = Post::query()->where('workspace_id', $this->workspace->id)->get();

    expect($posts)->toHaveCount(2)
        ->and(postsParitySnapshot($posts[0]))->toEqual(postsParitySnapshot($posts[1]))
        ->and($posts[0]->status)->toBe(Status::Draft);
});

test('a batch created through the api and through mcp stores one post per destination the same way', function () {
    $payload = [
        'status' => 'draft',
        'content' => 'Batch parity',
        'destinations' => [
            ['social_account_id' => $this->account->id, 'content_type' => 'linkedin_post'],
            ['social_account_id' => $this->second->id, 'content_type' => 'x_post'],
        ],
    ];

    $this->withHeaders(parityApi($this->token))
        ->postJson(route('api.posts.batch.store'), $payload)
        ->assertCreated()
        ->assertJsonCount(2, 'posts');

    TryPostServer::actingAs($this->user)->tool(CreatePostsTool::class, $payload)->assertOk();

    $snapshots = Post::query()->where('workspace_id', $this->workspace->id)->get()
        ->groupBy('post_group_id')
        ->map(fn ($group) => $group->map(fn (Post $post) => postsParitySnapshot($post))->sortBy('channels')->values()->all())
        ->values();

    expect($snapshots)->toHaveCount(2)
        ->and($snapshots[0])->toEqual($snapshots[1]);
});

test('a scheduled post created through the api and through the mcp batch tool is stored the same way', function () {
    $payload = [
        'status' => 'scheduled',
        'content' => 'Scheduled parity',
        'scheduled_at' => now()->addDays(2)->toIso8601String(),
    ];

    $this->withHeaders(parityApi($this->token))
        ->postJson(route('api.posts.store'), [
            ...$payload,
            'social_account_id' => $this->account->id,
            'content_type' => 'linkedin_post',
        ])->assertCreated();

    TryPostServer::actingAs($this->user)->tool(CreatePostsTool::class, [
        ...$payload,
        'destinations' => [['social_account_id' => $this->account->id, 'content_type' => 'linkedin_post']],
    ])->assertOk();

    $posts = Post::query()->where('workspace_id', $this->workspace->id)->get();

    expect($posts)->toHaveCount(2)
        ->and(postsParitySnapshot($posts[0]))->toEqual(postsParitySnapshot($posts[1]))
        ->and($posts[0]->status)->toBe(Status::Scheduled);
});

test('updating the content of a draft through the api and through mcp gives the same result', function () {
    $apiPost = Post::factory()->draft()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id, 'content' => 'Old']);
    $mcpPost = Post::factory()->draft()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id, 'content' => 'Old']);

    $this->withHeaders(parityApi($this->token))
        ->putJson(route('api.posts.update', $apiPost), ['status' => 'draft', 'content' => 'New'])
        ->assertOk();

    TryPostServer::actingAs($this->user)->tool(UpdatePostTool::class, ['post_id' => $mcpPost->id, 'content' => 'New'])->assertOk();

    expect($apiPost->refresh()->content)->toBe('New')
        ->and($mcpPost->refresh()->content)->toBe('New')
        ->and($apiPost->status)->toBe($mcpPost->status);
});

test('moving a scheduled post back to draft through the api and through mcp gives the same result', function () {
    $apiPost = Post::factory()->forAccount($this->account)->scheduled()->create(['user_id' => $this->user->id]);
    $mcpPost = Post::factory()->forAccount($this->account)->scheduled()->create(['user_id' => $this->user->id]);

    $this->withHeaders(parityApi($this->token))
        ->putJson(route('api.posts.update', $apiPost), ['status' => 'draft'])
        ->assertOk();

    TryPostServer::actingAs($this->user)->tool(UpdatePostTool::class, ['post_id' => $mcpPost->id, 'status' => 'draft'])->assertOk();

    expect($apiPost->refresh()->status)->toBe(Status::Draft)
        ->and($mcpPost->refresh()->status)->toBe(Status::Draft);
});

test('deleting a draft through the api and through mcp removes it', function () {
    $apiPost = Post::factory()->draft()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);
    $mcpPost = Post::factory()->draft()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);

    $this->withHeaders(parityApi($this->token))
        ->deleteJson(route('api.posts.destroy', $apiPost))
        ->assertNoContent();

    TryPostServer::actingAs($this->user)->tool(DeletePostTool::class, ['post_id' => $mcpPost->id])->assertOk();

    $this->assertModelMissing($apiPost);
    $this->assertModelMissing($mcpPost);
});

test('showing a post through the api and through mcp returns the same fields', function () {
    $post = Post::factory()->draft()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);

    $api = $this->withHeaders(parityApi($this->token))
        ->getJson(route('api.posts.show', $post))
        ->assertOk()
        ->json();

    TryPostServer::actingAs($this->user)->tool(GetPostTool::class, ['post_id' => $post->id])
        ->assertOk()
        ->assertStructuredContent($api);
});

test('a post from another workspace is not shown through the api nor through mcp', function () {
    $foreign = Post::factory()->draft()->create();

    $this->withHeaders(parityApi($this->token))
        ->getJson(route('api.posts.show', $foreign))
        ->assertNotFound();

    TryPostServer::actingAs($this->user)->tool(GetPostTool::class, ['post_id' => $foreign->id])->assertHasErrors();
});

test('previewing a post returns the same payload through the api and through mcp', function () {
    $post = Post::factory()->forAccount($this->account)->draft()->create(['user_id' => $this->user->id, 'content' => 'Preview parity']);

    $api = $this->withHeaders(parityApi($this->token))
        ->getJson(route('api.posts.preview', $post))
        ->assertOk()
        ->json();

    TryPostServer::actingAs($this->user)->tool(PreviewPostTool::class, ['post_id' => $post->id])
        ->assertOk()
        ->assertStructuredContent($api);

    expect($api)->toHaveKey('platform', Platform::LinkedIn->value)
        ->toHaveKey('content_type', 'linkedin_post')
        ->not->toHaveKey('platforms');
});

test('labels set through the api and through mcp end up on the post', function () {
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    $apiPost = Post::factory()->draft()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);
    $mcpPost = Post::factory()->draft()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);

    $this->withHeaders(parityApi($this->token))
        ->putJson(route('api.posts.update', $apiPost), ['status' => 'draft', 'label_ids' => [$label->id]])
        ->assertOk();

    TryPostServer::actingAs($this->user)->tool(UpdatePostTool::class, ['post_id' => $mcpPost->id, 'label_ids' => [$label->id]])->assertOk();

    expect($apiPost->labels()->pluck('workspace_labels.id')->all())->toBe([$label->id])
        ->and($mcpPost->labels()->pluck('workspace_labels.id')->all())->toBe([$label->id]);
});

test('listing posts: the api has no status filter while mcp filters by status', function () {
    $draft = Post::factory()->draft()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);
    $published = Post::factory()->published()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);

    $this->withHeaders(parityApi($this->token))
        ->getJson(route('api.posts.index', ['status' => 'draft']))
        ->assertOk()
        ->assertJsonCount(2, 'data');

    TryPostServer::actingAs($this->user)->tool(ListPostsTool::class, ['status' => 'draft'])
        ->assertOk()
        ->assertSee($draft->id)
        ->assertDontSee($published->id);
});

test('deleting a publishing, published or failed post is refused with the web message on the api and mcp', function (Status $status) {
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id, 'status' => $status]);
    $message = __('posts.flash.cannot_delete_published');

    $this->withHeaders(parityApi($this->token))
        ->deleteJson(route('api.posts.destroy', $post))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('post')
        ->assertJsonPath('errors.post.0', $message);

    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)->tool(DeletePostTool::class, ['post_id' => $post->id])
        ->assertHasErrors([$message]);

    expect(Post::query()->whereKey($post->id)->exists())->toBeTrue();
})->with([Status::Publishing, Status::Published, Status::Failed]);

test('a channel disconnect still deletes published posts', function () {
    $post = Post::factory()->forAccount($this->account)->published()->create(['user_id' => $this->user->id]);

    DeleteChannelPosts::forAccount($this->account);

    expect(Post::query()->whereKey($post->id)->exists())->toBeFalse();
});

test('a member who requires approval scheduling through the single-post mcp create tool ends pending approval', function () {
    Queue::fake();
    $member = workspaceMember($this->workspace, 'approval');

    TryPostServer::actingAs($member)->tool(CreatePostTool::class, [
        'content' => 'Member schedule',
        'status' => 'scheduled',
        'scheduled_at' => now()->addDays(2)->toIso8601String(),
        'social_account_id' => $this->account->id,
        'content_type' => 'linkedin_post',
    ])->assertOk();

    $post = Post::query()->where('content', 'Member schedule')->sole();

    expect($post->status)->toBe(Status::PendingApproval)
        ->and($post->approval_requested_by)->toBe($member->id);
});

test('a scheduled post over the account limit is refused with the same message by the api and the single-post mcp create tool', function () {
    $payload = [
        'content' => str_repeat('a', 281),
        'status' => 'scheduled',
        'scheduled_at' => now()->addDays(2)->toIso8601String(),
        'social_account_id' => $this->second->id,
        'content_type' => 'x_post',
    ];

    $response = $this->withHeaders(parityApi($this->token))->postJson(route('api.posts.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['content']);

    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, $payload)
        ->assertHasErrors([$response->json('errors.content.0')]);

    expect(Post::query()->where('workspace_id', $this->workspace->id)->count())->toBe(0);
});

test('the shared post rules validate without a request or an authenticated user', function () {
    auth()->forgetGuards();
    $foreign = SocialAccount::factory()->create(['platform' => Platform::LinkedIn]);
    $input = fn (SocialAccount $account): array => [
        'content' => 'Rules only',
        'status' => 'scheduled',
        'queue' => 'next',
        'social_account_id' => $account->id,
        'content_type' => 'linkedin_post',
    ];
    $validate = fn (array $data) => Validator::make($data, PostRequestRules::store($this->workspace, $data), PostRequestRules::messages(), PostRequestRules::attributes());

    expect(auth()->check())->toBeFalse()
        ->and($validate($input($this->account))->passes())->toBeTrue()
        ->and($validate($input($foreign))->errors()->keys())->toBe(['social_account_id']);
});

test('publishing now through the api update and the mcp update tool gives the same result', function () {
    Queue::fake();
    $apiPost = Post::factory()->forAccount($this->account)->draft()->create(['user_id' => $this->user->id, 'content' => 'Now']);
    $mcpPost = Post::factory()->forAccount($this->account)->draft()->create(['user_id' => $this->user->id, 'content' => 'Now']);

    $this->withHeaders(parityApi($this->token))->putJson(route('api.posts.update', $apiPost), ['status' => 'publishing'])->assertOk();

    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)->tool(UpdatePostTool::class, ['post_id' => $mcpPost->id, 'status' => 'publishing'])->assertOk();

    expect(postsParitySnapshot($apiPost->fresh()))->toEqual(postsParitySnapshot($mcpPost->fresh()))
        ->and($mcpPost->fresh()->status)->toBe(Status::Publishing);
});

test('an update sending the removed platforms input is refused with the same message by the api and the mcp update tool', function () {
    $post = Post::factory()->forAccount($this->account)->draft()->create(['user_id' => $this->user->id, 'content' => 'Kept']);
    $payload = ['status' => 'draft', 'content' => 'Changed', 'platforms' => [['social_account_id' => $this->second->id, 'content_type' => 'x_post']]];

    $response = $this->withHeaders(parityApi($this->token))->putJson(route('api.posts.update', $post), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['platforms']);

    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)->tool(UpdatePostTool::class, ['post_id' => $post->id, ...$payload])
        ->assertHasErrors([$response->json('errors.platforms.0')]);

    expect($post->fresh()->content)->toBe('Kept')
        ->and($post->fresh()->social_account_id)->toBe($this->account->id);
});

test('a deleted label is refused by the api create and the single-post mcp create tool', function () {
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    $label->delete();
    $payload = [
        'content' => 'Labelled',
        'label_ids' => [$label->id],
        'social_account_id' => $this->account->id,
        'content_type' => 'linkedin_post',
    ];

    $this->withHeaders(parityApi($this->token))->postJson(route('api.posts.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['label_ids.0']);

    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, $payload)->assertHasErrors();

    expect(Post::query()->where('workspace_id', $this->workspace->id)->count())->toBe(0);
});

test('filtering posts by channel, label and untagged returns the same posts on the web publish list, the api and mcp', function () {
    $draft = function (?SocialAccount $account, array $labels = []): Post {
        $factory = $account === null ? Post::factory()->state(['workspace_id' => $this->workspace->id]) : Post::factory()->forAccount($account);
        $post = $factory->draft()->create(['user_id' => $this->user->id]);
        $post->labels()->attach($labels);

        return $post;
    };
    $launch = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    $promo = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    $foreignAccount = SocialAccount::factory()->create();
    $foreignLabel = WorkspaceLabel::factory()->create();

    $first = $draft($this->account, [$launch->id]);
    $second = $draft($this->account);
    $third = $draft($this->second, [$promo->id]);
    $fourth = $draft($this->second, [$launch->id, $promo->id]);
    $channelless = $draft(null);

    $web = function (array $filters): array {
        $ids = [];

        $this->actingAs($this->user)
            ->get(route('app.posts.index', ['tab' => 'drafts', ...$filters]))
            ->assertInertia(function (AssertableInertia $page) use (&$ids): void {
                $page->loadDeferredProps(function (AssertableInertia $reload) use (&$ids): void {
                    $ids = collect($reload->toArray()['props']['posts']['data'])->pluck('id')->sort()->values()->all();
                });
            });

        return $ids;
    };

    $cases = [
        'no filter' => [[], [$first, $second, $third, $fourth, $channelless]],
        'one channel' => [['channels' => [$this->account->id]], [$first, $second]],
        'two channels' => [['channels' => [$this->account->id, $this->second->id]], [$first, $second, $third, $fourth]],
        'a foreign channel' => [['channels' => [$foreignAccount->id]], []],
        'a channel id that is not a uuid' => [['channels' => ['not-a-uuid']], [$first, $second, $third, $fourth, $channelless]],
        'one label' => [['labels' => [$launch->id]], [$first, $fourth]],
        'two labels' => [['labels' => [$launch->id, $promo->id]], [$first, $third, $fourth]],
        'untagged' => [['untagged' => '1'], [$second, $channelless]],
        'label or untagged' => [['labels' => [$promo->id], 'untagged' => '1'], [$second, $third, $fourth, $channelless]],
        'a foreign label' => [['labels' => [$foreignLabel->id]], []],
        'channel and label' => [['channels' => [$this->second->id], 'labels' => [$launch->id]], [$fourth]],
    ];

    foreach ($cases as $name => [$filters, $posts]) {
        $expected = collect($posts)->pluck('id')->sort()->values()->all();

        $webIds = $web($filters);
        auth()->forgetGuards();

        $apiIds = collect($this->withHeaders(parityApi($this->token))->getJson(route('api.posts.index', $filters))->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        $mcpIds = null;
        TryPostServer::actingAs($this->user)
            ->tool(ListPostsTool::class, [...$filters, ...(array_key_exists('untagged', $filters) ? ['untagged' => true] : [])])
            ->assertOk()
            ->assertStructuredContent(function (AssertableJson $json) use (&$mcpIds) {
                $mcpIds = collect($json->toArray()['posts'])->pluck('id')->sort()->values()->all();

                return $json->etc();
            });

        expect($webIds)->toBe($expected, "web: {$name}")
            ->and($apiIds)->toBe($expected, "api: {$name}")
            ->and($mcpIds)->toBe($expected, "mcp: {$name}");
    }
});

test('the api and mcp post payload carries the author, the group and the approval state with only id and name', function () {
    $requester = workspaceMember($this->workspace, 'approval');
    $approver = workspaceMember($this->workspace, 'admin');
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $requester->id,
        'status' => Status::Scheduled,
        'scheduled_at' => now()->addDay()->startOfSecond(),
        'approval_requested_by' => $requester->id,
        'approval_requested_at' => now()->subHour()->startOfSecond(),
        'approved_by' => $approver->id,
        'approved_at' => now()->subMinutes(5)->startOfSecond(),
    ]);
    $pending = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $requester->id,
        'status' => Status::PendingApproval,
        'approval_requested_by' => null,
    ]);

    $api = $this->withHeaders(parityApi($this->token))->getJson(route('api.posts.show', $post))->assertOk()
        ->assertJsonPath('post_group_id', $post->post_group_id)
        ->assertJsonPath('author', ['id' => $requester->id, 'name' => $requester->name])
        ->assertJsonPath('approval_requested_by', ['id' => $requester->id, 'name' => $requester->name])
        ->assertJsonPath('approval_requested_at', $post->approval_requested_at->format('Y-m-d H:i:s'))
        ->assertJsonPath('approved_by', ['id' => $approver->id, 'name' => $approver->name])
        ->assertJsonPath('approved_at', $post->approved_at->format('Y-m-d H:i:s'))
        ->assertDontSee($requester->email)
        ->assertDontSee($approver->email)
        ->json();
    $apiPending = $this->withHeaders(parityApi($this->token))->getJson(route('api.posts.show', $pending))->assertOk()
        ->assertJsonPath('approval_requested_by', ['id' => $requester->id, 'name' => $requester->name])
        ->assertJsonPath('approved_by', null)
        ->json();
    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)->tool(GetPostTool::class, ['post_id' => $post->id])->assertOk()->assertStructuredContent($api);
    TryPostServer::actingAs($this->user)->tool(GetPostTool::class, ['post_id' => $pending->id])->assertOk()->assertStructuredContent($apiPending);
});

test('the api and mcp post lists page by the configured size in the same order', function () {
    config()->set('app.pagination.default', 2);
    $account = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Bluesky]);

    foreach (range(0, 2) as $minutes) {
        Post::factory()->forAccount($account)->create(['user_id' => $this->user->id, 'status' => Status::Draft, 'scheduled_at' => now()->addDays(10)->subMinutes($minutes)]);
    }

    $first = $this->withHeaders(parityApi($this->token))->getJson(route('api.posts.index', ['per_page' => 50]))->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.per_page', 2)->assertJsonPath('meta.total', 3);
    $second = $this->withHeaders(parityApi($this->token))->getJson(route('api.posts.index', ['page' => 2]))->assertOk()->assertJsonCount(1, 'data');
    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)->tool(ListPostsTool::class)->assertOk()->assertStructuredContent(parityMcpPage('posts', $first));
    TryPostServer::actingAs($this->user)->tool(ListPostsTool::class, ['page' => 2])->assertOk()->assertStructuredContent(parityMcpPage('posts', $second));
});
