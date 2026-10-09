<?php

declare(strict_types=1);

use App\Enums\Post\PublishStatus;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);

    $this->socialAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $this->post = Post::factory()->forAccount($this->socialAccount)->create([
        'user_id' => $this->user->id,
    ]);
});

test('markPublicationPublished clears stale error_message and error_context', function () {
    $this->post->forceFill([
        'publish_status' => PublishStatus::Failed,
        'error_message' => 'The post is empty. Please enter a message to share.',
        'error_context' => ['platform_error_code' => 197, 'content_length' => 0],
    ])->save();

    $this->post->markPublicationPublished('platform_post_abc', 'https://www.facebook.com/platform_post_abc');

    $this->post->refresh();

    expect($this->post->publish_status)->toBe(PublishStatus::Published)
        ->and($this->post->platform_post_id)->toBe('platform_post_abc')
        ->and($this->post->error_message)->toBeNull()
        ->and($this->post->error_context)->toBeNull();
});

test('markPublicationPublished does not move updated_at', function () {
    $this->post->forceFill(['updated_at' => now()->subDay()])->saveQuietly();
    $updatedAt = $this->post->fresh()->updated_at;

    $this->post->markPublicationPublished('platform_post_abc');

    expect($this->post->fresh()->updated_at->equalTo($updatedAt))->toBeTrue()
        ->and($this->post->fresh()->publication_updated_at)->not->toBeNull();
});

test('publicationPublished scope only includes published posts', function () {
    $published = Post::factory()->forAccount($this->socialAccount)->published()->create();
    Post::factory()->forAccount($this->socialAccount)->failed()->create();

    $publishedPosts = Post::query()->publicationPublished()->get();

    expect($publishedPosts)->toHaveCount(1)
        ->and($publishedPosts->first()->is($published))->toBeTrue();
});

test('display_name falls back to the account username when display_name is not set', function () {
    $this->socialAccount->update(['display_name' => null, 'username' => 'acme_handle']);

    expect($this->post->fresh()->display_name)->toBe('acme_handle');
});

test('display_name falls back to the platform label when the live account has neither name set', function () {
    $this->socialAccount->update(['display_name' => null, 'username' => null]);

    expect($this->post->fresh()->display_name)->toBe($this->socialAccount->platform->label());
});

test('display_name falls back to the platform_name snapshot when the account has been deleted', function () {
    $this->post->update(['platform_name' => 'Snapshot Name']);
    $this->socialAccount->delete();

    expect($this->post->fresh()->display_name)->toBe('Snapshot Name');
});

test('display_username falls back to the platform_username snapshot when the account has been deleted', function () {
    $this->post->update(['platform_username' => 'snapshot_handle']);
    $this->socialAccount->delete();

    expect($this->post->fresh()->display_username)->toBe('snapshot_handle');
});

test('notificationLabel prefers username over display name', function () {
    $account = SocialAccount::factory()->facebook()->create([
        'workspace_id' => $this->workspace->id,
        'username' => 'myfbpage',
        'display_name' => 'InboxPlacement.io',
    ]);
    $post = Post::factory()->forAccount($account)->create();

    expect($post->notificationLabel())->toBe('Facebook Page (@myfbpage)');
});

test('notificationLabel falls back to display name when username is empty', function () {
    $account = SocialAccount::factory()->facebook()->create([
        'workspace_id' => $this->workspace->id,
        'username' => null,
        'display_name' => 'InboxPlacement.io',
    ]);
    $post = Post::factory()->forAccount($account)->create();

    expect($post->notificationLabel())->toBe('Facebook Page (@InboxPlacement.io)');
});

test('notificationLabel uses username when display name is empty', function () {
    $account = SocialAccount::factory()->bluesky()->create([
        'workspace_id' => $this->workspace->id,
        'username' => 'inboxplacementio.bsky.social',
        'display_name' => '',
    ]);
    $post = Post::factory()->forAccount($account)->create();

    expect($post->notificationLabel())->toBe('Bluesky (@inboxplacementio.bsky.social)');
});

test('notificationLabel omits empty parentheses when both identifiers are missing', function () {
    $account = SocialAccount::factory()->facebook()->create([
        'workspace_id' => $this->workspace->id,
        'username' => null,
        'display_name' => '',
    ]);
    $post = Post::factory()->forAccount($account)->create();

    expect($post->notificationLabel())->toBe('Facebook Page');
});

test('notificationLabel uses the snapshot when the account has been deleted', function () {
    $account = SocialAccount::factory()->facebook()->create([
        'workspace_id' => $this->workspace->id,
        'username' => null,
        'display_name' => 'InboxPlacement.io',
    ]);
    $post = Post::factory()->forAccount($account)->create([
        'platform_name' => 'InboxPlacement.io',
    ]);

    $account->delete();

    expect($post->fresh()->notificationLabel())->toBe('Facebook Page (@InboxPlacement.io)');
});

test('notificationLabel is empty for a draft without a channel', function () {
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id]);

    expect($post->notificationLabel())->toBe('');
});

test('attachesLinkPreview is false only when the card was dropped', function (?array $meta, bool $expected) {
    $post = Post::factory()->forAccount($this->socialAccount)->make(['meta' => $meta]);

    expect($post->attachesLinkPreview())->toBe($expected);
})->with([
    'no meta' => [null, true],
    'card kept' => [['link_preview' => true], true],
    'card dropped' => [['link_preview' => false], false],
]);

test('markPublicationFailed clears stale platform_post_id and platform_url', function () {
    $this->post->forceFill([
        'publish_status' => PublishStatus::Published,
        'platform_post_id' => 'old_post_id',
        'platform_url' => 'https://www.facebook.com/old_post_id',
        'published_at' => now(),
    ])->save();

    $this->post->markPublicationFailed('Something went wrong.', ['platform_error_code' => 500]);

    $this->post->refresh();

    expect($this->post->publish_status)->toBe(PublishStatus::Failed)
        ->and($this->post->error_message)->toBe('Something went wrong.')
        ->and($this->post->platform_post_id)->toBeNull()
        ->and($this->post->platform_url)->toBeNull();
});
