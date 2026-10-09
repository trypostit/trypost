<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\CreatePostsTool;
use App\Mcp\Tools\Post\CreatePostTool;
use App\Mcp\Tools\Post\UpdatePostTool;
use App\Models\Post;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
    ['user' => $this->user, 'workspace' => $this->workspace, 'token' => $this->token] = parityContext();
    $this->account = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Bluesky]);

    Http::fake([
        'example.com/missing.png' => fn () => Http::response('', 404),
        'example.com/*' => fn () => Http::response(file_get_contents(base_path('tests/fixtures/1x1.png')), 200, ['Content-Type' => 'image/png']),
    ]);
});

function threadReplyUrlMeta(string $url = 'https://example.com/reply.png'): array
{
    return ['thread_replies' => [['text' => 'Second', 'media' => [['url' => $url]]]]];
}

function threadReplyStoredMedia(Post $post): array
{
    return data_get($post->fresh()->meta, 'thread_replies.0.media', []);
}

test('a reply media url is hosted on create through api and mcp', function () {
    $payload = [
        'content' => 'First',
        'social_account_id' => $this->account->id,
        'content_type' => ContentType::BlueskyPost->value,
        'meta' => threadReplyUrlMeta(),
    ];

    $this->withHeaders(parityApi($this->token))->postJson(route('api.posts.store'), $payload)->assertCreated();
    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, $payload)->assertOk();

    $posts = Post::query()->where('social_account_id', $this->account->id)->get();

    expect($posts)->toHaveCount(2);

    foreach ($posts as $post) {
        $media = threadReplyStoredMedia($post);

        expect($media)->toHaveCount(1)
            ->and(data_get($media, '0.type'))->toBe('image')
            ->and($post->ownedMedia()->count())->toBe(1);
    }
});

test('a reply media url is hosted in a batch through api and mcp', function () {
    $payload = [
        'status' => 'draft',
        'content' => 'First',
        'destinations' => [['social_account_id' => $this->account->id, 'content_type' => ContentType::BlueskyPost->value, 'meta' => threadReplyUrlMeta()]],
    ];

    $this->withHeaders(parityApi($this->token))->postJson(route('api.posts.batch.store'), $payload)->assertCreated();
    TryPostServer::actingAs($this->user)->tool(CreatePostsTool::class, $payload)->assertOk();

    $targets = Post::query()->where('social_account_id', $this->account->id)->get();

    expect($targets)->toHaveCount(2)
        ->and(threadReplyStoredMedia($targets[0]))->toHaveCount(1)
        ->and(threadReplyStoredMedia($targets[1]))->toHaveCount(1);
});

test('a reply media url is hosted on update through api and mcp', function () {
    $posts = collect([0, 1])->map(function (): Post {
        return Post::factory()->forAccount($this->account, ContentType::BlueskyPost)->create(['user_id' => $this->user->id]);
    });

    $this->withHeaders(parityApi($this->token))->putJson(route('api.posts.update', $posts[0]), ['meta' => threadReplyUrlMeta()])->assertOk();
    TryPostServer::actingAs($this->user)->tool(UpdatePostTool::class, ['post_id' => $posts[1]->id, 'meta' => threadReplyUrlMeta()])->assertOk();

    foreach ($posts as $post) {
        expect(threadReplyStoredMedia($post))->toHaveCount(1);
    }
});

test('an unreachable reply media url is refused instead of dropped through api and mcp', function () {
    $payload = [
        'content' => 'First',
        'social_account_id' => $this->account->id,
        'content_type' => ContentType::BlueskyPost->value,
        'meta' => threadReplyUrlMeta('https://example.com/missing.png'),
    ];

    $this->withHeaders(parityApi($this->token))->postJson(route('api.posts.store'), $payload)
        ->assertJsonValidationErrors(['meta.thread_replies.0.media.0.url']);
    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, $payload)->assertHasErrors();

    expect(Post::query()->where('workspace_id', $this->workspace->id)->count())->toBe(0);
});
