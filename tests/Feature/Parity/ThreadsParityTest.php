<?php

declare(strict_types=1);

use App\Enums\Post\Status;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\CreatePostsTool;
use App\Mcp\Tools\Post\CreatePostTool;
use App\Mcp\Tools\Post\GetPostTool;
use App\Mcp\Tools\Post\UpdatePostTool;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
    ['user' => $this->user, 'workspace' => $this->workspace, 'token' => $this->token] = parityContext();
});

function threadsParityPlatform(Platform $platform): SocialAccount
{
    return SocialAccount::factory()->create([
        'workspace_id' => test()->workspace->id,
        'platform' => $platform,
    ]);
}

function threadsParityContentType(Platform $platform): string
{
    return match ($platform) {
        Platform::Bluesky => ContentType::BlueskyPost->value,
        Platform::Mastodon => ContentType::MastodonPost->value,
        default => ContentType::XPost->value,
    };
}

dataset('thread networks', [
    'bluesky' => [Platform::Bluesky, 300],
    'mastodon' => [Platform::Mastodon, 500],
    'x' => [Platform::X, 280],
]);

test('thread replies sent as strings are stored as text objects through api and mcp', function () {
    $account = threadsParityPlatform(Platform::Bluesky);

    $payload = [
        'content' => 'First post',
        'status' => 'draft',
        'social_account_id' => $account->id,
        'content_type' => ContentType::BlueskyPost->value,
        'meta' => ['thread_replies' => ['Second post']],
    ];

    $this->withHeaders(parityApi($this->token))->postJson(route('api.posts.store'), $payload)->assertCreated();
    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, $payload)->assertOk();

    $replies = Post::query()->where('social_account_id', $account->id)->get()
        ->map(fn (Post $post) => data_get($post->meta, 'thread_replies'));

    expect($replies)->toHaveCount(2)
        ->and($replies[0])->toEqual($replies[1])
        ->and(data_get($replies[0], '0.text'))->toBe('Second post')
        ->and(data_get($replies[0], '0.media'))->toBe([]);
});

test('a scheduled thread with text replies is stored the same way on every thread network through api and mcp', function (Platform $platform) {
    $account = threadsParityPlatform($platform);
    $contentType = threadsParityContentType($platform);
    $replies = ['Two', ['text' => 'Three']];

    $this->withHeaders(parityApi($this->token))->postJson(route('api.posts.batch.store'), [
        'status' => 'scheduled',
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'content' => 'Root',
        'destinations' => [['social_account_id' => $account->id, 'content_type' => $contentType, 'meta' => ['thread_replies' => $replies]]],
    ])->assertCreated();

    TryPostServer::actingAs($this->user)->tool(CreatePostsTool::class, [
        'status' => 'scheduled',
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'content' => 'Root',
        'destinations' => [['social_account_id' => $account->id, 'content_type' => $contentType, 'meta' => ['thread_replies' => $replies]]],
    ])->assertOk();

    $stored = Post::query()->where('social_account_id', $account->id)->get();

    expect($stored)->toHaveCount(2)
        ->and($stored[0]->status)->toBe(Status::Scheduled)
        ->and($stored[1]->status)->toBe(Status::Scheduled)
        ->and($stored[0]->meta['thread_replies'])->toEqual($stored[1]->meta['thread_replies'])
        ->and($stored[0]->meta['thread_replies'])->toEqual([['text' => 'Two', 'media' => []], ['text' => 'Three', 'media' => []]]);
})->with('thread networks');

test('a reply keeps its own media owned by the post through api and mcp', function () {
    $account = threadsParityPlatform(Platform::X);
    $apiUpload = Media::factory()->stored()->temporaryUpload($this->workspace)->create();
    $mcpUpload = Media::factory()->stored()->temporaryUpload($this->workspace)->create();

    $payload = fn (Media $upload): array => [
        'content' => 'Root',
        'social_account_id' => $account->id,
        'content_type' => ContentType::XPost->value,
        'meta' => ['thread_replies' => [
            ['text' => 'Two', 'media' => [['upload_token' => $upload->upload_token]]],
        ]],
    ];

    $this->withHeaders(parityApi($this->token))->postJson(route('api.posts.store'), $payload($apiUpload))->assertCreated();
    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, $payload($mcpUpload))->assertOk();

    foreach ([$apiUpload, $mcpUpload] as $upload) {
        $post = Post::query()->where('social_account_id', $account->id)->whereHas('ownedMedia', fn ($query) => $query->whereKey($upload->id))->sole();

        expect($post->meta['thread_replies'][0]['media'][0]['id'])->toBe($upload->id)
            ->and($upload->fresh()->post_id)->toBe($post->id)
            ->and($post->media)->toBe([]);
    }
});

test('a scheduled reply over the account limit is rejected without storing a post through api and mcp', function (Platform $platform, int $limit) {
    $account = threadsParityPlatform($platform);
    $destination = [
        'social_account_id' => $account->id,
        'content_type' => threadsParityContentType($platform),
        'meta' => ['thread_replies' => ['ok', str_repeat('a', $limit + 1)]],
    ];
    $message = __('posts.form.thread.reply_too_long', ['limit' => $limit, 'over' => 1]);

    $response = $this->withHeaders(parityApi($this->token))->postJson(route('api.posts.batch.store'), [
        'status' => 'scheduled',
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'content' => 'Root',
        'destinations' => [$destination],
    ])->assertUnprocessable();

    expect(collect($response->json('errors'))->flatten()->implode(' '))->toContain($message);

    TryPostServer::actingAs($this->user)->tool(CreatePostsTool::class, [
        'status' => 'scheduled',
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'content' => 'Root',
        'destinations' => [$destination],
    ])->assertHasErrors([$message]);

    expect(Post::query()->where('workspace_id', $this->workspace->id)->count())->toBe(0);
})->with('thread networks');

test('a scheduled reply with five images is rejected through api and mcp', function () {
    $account = threadsParityPlatform(Platform::X);
    $uploads = Media::factory()->stored()->temporaryUpload($this->workspace)->count(5)->create();
    $destination = [
        'social_account_id' => $account->id,
        'content_type' => ContentType::XPost->value,
        'meta' => ['thread_replies' => [['text' => 'Two', 'media' => $uploads->map(fn (Media $upload): array => ['upload_token' => $upload->upload_token])->all()]]],
    ];
    $message = __('posts.form.warnings.max_files_exceeded', ['max' => 4, 'current' => 5]);

    $response = $this->withHeaders(parityApi($this->token))->postJson(route('api.posts.batch.store'), [
        'status' => 'scheduled',
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'content' => 'Root',
        'destinations' => [$destination],
    ])->assertUnprocessable();

    expect(collect($response->json('errors'))->flatten()->implode(' '))->toContain($message);

    TryPostServer::actingAs($this->user)->tool(CreatePostsTool::class, [
        'status' => 'scheduled',
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'content' => 'Root',
        'destinations' => [$destination],
    ])->assertHasErrors([$message]);

    expect(Post::query()->where('workspace_id', $this->workspace->id)->count())->toBe(0);
});

test('more than 24 replies are rejected on the single create path through api and mcp', function () {
    $account = threadsParityPlatform(Platform::Mastodon);
    $payload = [
        'content' => 'Root',
        'social_account_id' => $account->id,
        'content_type' => ContentType::MastodonPost->value,
        'meta' => ['thread_replies' => array_fill(0, 25, 'Reply')],
    ];

    $response = $this->withHeaders(parityApi($this->token))->postJson(route('api.posts.store'), $payload)
        ->assertUnprocessable()->assertJsonValidationErrors(['meta.thread_replies']);
    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, $payload)->assertHasErrors([$response->json('errors')['meta.thread_replies'][0]]);

    expect(Post::query()->where('workspace_id', $this->workspace->id)->count())->toBe(0);
});

test('thread replies read back as text and media objects through api and mcp', function () {
    $account = threadsParityPlatform(Platform::Bluesky);
    $post = Post::factory()->forAccount($account, ContentType::BlueskyPost)->create([
        'user_id' => $this->user->id,
        'status' => Status::Draft,
        'meta' => ['thread_replies' => [['text' => 'Two', 'media' => []]]],
    ]);

    $api = $this->withHeaders(parityApi($this->token))->getJson(route('api.posts.show', $post))->assertOk()->json('meta.thread_replies');
    TryPostServer::actingAs($this->user)->tool(GetPostTool::class, ['post_id' => $post->id])
        ->assertOk()->assertStructuredContent(fn ($json) => $json->where('meta.thread_replies', $api)->etc());

    expect($api)->toEqual([['text' => 'Two', 'media' => []]]);
});

test('the batch path rejects 25 replies through api and mcp', function () {
    $account = threadsParityPlatform(Platform::Mastodon);
    $payload = [
        'status' => 'draft',
        'content' => 'Root',
        'destinations' => [['social_account_id' => $account->id, 'content_type' => ContentType::MastodonPost->value, 'meta' => ['thread_replies' => array_fill(0, 25, 'Reply')]]],
    ];

    $this->withHeaders(parityApi($this->token))->postJson(route('api.posts.batch.store'), $payload)
        ->assertUnprocessable()->assertJsonValidationErrors(['destinations.0.meta.thread_replies']);
    expect(Post::query()->where('social_account_id', $account->id)->count())->toBe(0);

    TryPostServer::actingAs($this->user)->tool(CreatePostsTool::class, $payload)->assertHasErrors([__('validation.max.array', ['attribute' => 'destinations.0.meta.thread_replies', 'max' => 24])]);

    expect(Post::query()->where('social_account_id', $account->id)->count())->toBe(0);
});

test('scheduling replies on a network that cannot chain is rejected through api and mcp', function () {
    $account = threadsParityPlatform(Platform::LinkedIn);
    $destination = ['social_account_id' => $account->id, 'content_type' => ContentType::LinkedInPost->value, 'meta' => ['thread_replies' => ['Two']]];
    $message = __('posts.form.thread.unsupported');

    $response = $this->withHeaders(parityApi($this->token))->postJson(route('api.posts.batch.store'), [
        'status' => 'scheduled',
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'content' => 'Root',
        'destinations' => [$destination],
    ])->assertUnprocessable();

    expect(collect($response->json('errors'))->flatten()->implode(' '))->toContain($message);

    TryPostServer::actingAs($this->user)->tool(CreatePostsTool::class, [
        'status' => 'scheduled',
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'content' => 'Root',
        'destinations' => [$destination],
    ])->assertHasErrors([$message]);
});

function threadsParityDraft(SocialAccount $account, string $contentType): Post
{
    return Post::factory()->forAccount($account, ContentType::from($contentType))->draft()->create(['user_id' => test()->user->id, 'content' => 'Root', 'meta' => []]);
}

test('updating replies on an existing post stores them the same way through api and mcp', function () {
    $account = threadsParityPlatform(Platform::Bluesky);
    $apiPost = threadsParityDraft($account, ContentType::BlueskyPost->value);
    $mcpPost = threadsParityDraft($account, ContentType::BlueskyPost->value);
    $replies = ['Two', ['text' => 'Three']];

    $this->withHeaders(parityApi($this->token))
        ->putJson(route('api.posts.update', $apiPost), ['status' => 'draft', 'meta' => ['thread_replies' => $replies]])
        ->assertOk();

    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)
        ->tool(UpdatePostTool::class, ['post_id' => $mcpPost->id, 'meta' => ['thread_replies' => $replies]])
        ->assertOk();

    expect($apiPost->fresh()->meta['thread_replies'])
        ->toEqual($mcpPost->fresh()->meta['thread_replies'])
        ->toEqual([['text' => 'Two', 'media' => []], ['text' => 'Three', 'media' => []]]);
});

test('updating a post with more than 24 replies is refused with the same message through api and mcp', function () {
    $account = threadsParityPlatform(Platform::Mastodon);
    $apiPost = threadsParityDraft($account, ContentType::MastodonPost->value);
    $mcpPost = threadsParityDraft($account, ContentType::MastodonPost->value);

    $message = $this->withHeaders(parityApi($this->token))
        ->putJson(route('api.posts.update', $apiPost), ['status' => 'draft', 'meta' => ['thread_replies' => array_fill(0, 25, 'Reply')]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['meta.thread_replies'])
        ->json('errors')['meta.thread_replies'][0];

    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)
        ->tool(UpdatePostTool::class, ['post_id' => $mcpPost->id, 'meta' => ['thread_replies' => array_fill(0, 25, 'Reply')]])
        ->assertHasErrors([$message]);

    expect($apiPost->fresh()->meta)->toBe([])
        ->and($mcpPost->fresh()->meta)->toBe([]);
});
