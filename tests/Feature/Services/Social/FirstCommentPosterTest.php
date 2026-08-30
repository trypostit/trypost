<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\FirstCommentPoster;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'content' => 'Post body',
    ]);
    $this->poster = new FirstCommentPoster;
});

function makePlatformRow(Workspace $workspace, Post $post, SocialAccount $account, ContentType $contentType, ?array $meta): PostPlatform
{
    return PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
        'content_type' => $contentType,
        'meta' => $meta,
    ]);
}

test('posts a youtube first comment via commentThreads.insert', function () {
    $account = SocialAccount::factory()->youtube()->create(['workspace_id' => $this->workspace->id]);
    $row = makePlatformRow($this->workspace, $this->post, $account, ContentType::YouTubeShort, [
        'first_comment' => 'Link: https://example.com',
    ]);

    Http::fake(['*' => Http::response(['id' => 'thread-1'], 200)]);

    $this->poster->post($row, 'video-123');

    $api = rtrim((string) config('trypost.platforms.youtube.data_api'), '/');

    Http::assertSent(function ($request) use ($api) {
        return str_starts_with($request->url(), "{$api}/commentThreads")
            && data_get($request->data(), 'snippet.videoId') === 'video-123'
            && data_get($request->data(), 'snippet.topLevelComment.snippet.textOriginal') === 'Link: https://example.com';
    });
});

test('posts an instagram first comment on the published media', function () {
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    $row = makePlatformRow($this->workspace, $this->post, $account, ContentType::InstagramFeed, [
        'first_comment' => 'More: https://example.com',
    ]);

    Http::fake(['*' => Http::response(['id' => 'comment-1'], 200)]);

    $this->poster->post($row, 'media-456');

    $base = $account->platform->instagramGraphBaseUrl();

    Http::assertSent(function ($request) use ($base) {
        return str_starts_with($request->url(), "{$base}/media-456/comments")
            && $request['message'] === 'More: https://example.com';
    });
});

test('does nothing when first_comment meta is empty', function () {
    $account = SocialAccount::factory()->youtube()->create(['workspace_id' => $this->workspace->id]);
    $row = makePlatformRow($this->workspace, $this->post, $account, ContentType::YouTubeShort, ['first_comment' => '   ']);

    Http::fake();

    $this->poster->post($row, 'video-123');

    Http::assertNothingSent();
});

test('a failed first comment never throws', function () {
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    $row = makePlatformRow($this->workspace, $this->post, $account, ContentType::InstagramFeed, [
        'first_comment' => 'More: https://example.com',
    ]);

    Http::fake(['*' => Http::response(['error' => ['message' => 'Missing permission']], 403)]);

    $this->poster->post($row, 'media-456');

    expect(true)->toBeTrue();
});

test('unsupported platforms are skipped without requests', function () {
    $account = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id]);
    $row = makePlatformRow($this->workspace, $this->post, $account, ContentType::XPost, [
        'first_comment' => 'ignored',
    ]);

    Http::fake();

    $this->poster->post($row, 'tweet-1');

    Http::assertNothingSent();
});
