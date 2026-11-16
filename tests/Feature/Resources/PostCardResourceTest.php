<?php

declare(strict_types=1);

use App\Actions\Post\BuildPublishPageProps;
use App\Http\Resources\App\PostCardResource;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->user->account_id, 'user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->channel = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);
    $this->actingAs($this->user);
});

/**
 * @return array<string, mixed>
 */
function postCardAsBefore(Post $post): array
{
    $card = [
        ...$post->toArray(),
        'failure' => $post->error_context === null ? null : [
            'category' => data_get($post->error_context, 'category'),
            'failed_at' => data_get($post->error_context, 'failed_at'),
        ],
    ];

    $card['user'] = $post->user === null ? null : [
        'id' => $post->user->id,
        'name' => $post->user->name,
        'photo_url' => $post->user->photo_url,
    ];

    return $card;
}

test('a card keeps the shape of the post with its channel, labels and a short author', function () {
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    $first = Post::factory()->forAccount($this->channel)->scheduled()->create(['user_id' => $this->user->id]);
    $second = Post::factory()->forAccount($this->channel)->failed()->create([
        'user_id' => $this->user->id,
        'error_context' => ['category' => 'auth', 'failed_at' => now()->toIso8601String()],
    ]);
    $first->labels()->attach($label);

    $posts = Post::query()->whereKey([$first->id, $second->id])
        ->with(['socialAccount', 'user.avatarMedia', 'labels'])
        ->withCount(['notes', 'groupPosts'])
        ->orderBy('id')
        ->get();
    BuildPublishPageProps::decorate($posts);

    $cards = PostCardResource::cards($posts);

    expect($cards)->toEqual($posts->map(fn (Post $post): array => postCardAsBefore($post))->all())
        ->and(data_get($cards, '0.social_account.id'))->toBe($this->channel->id)
        ->and(data_get($cards, '1.social_account'))->toEqual(data_get($cards, '0.social_account'))
        ->and(data_get($cards, '0.user'))->toBe(['id' => $this->user->id, 'name' => $this->user->name, 'photo_url' => $this->user->photo_url])
        ->and(data_get($cards, '1.failure.category'))->toBe('auth')
        ->and($posts->first()->getHidden())->not->toContain('user', 'socialAccount');
});

test('a card without its relations loaded leaves them out', function () {
    $post = Post::factory()->forAccount($this->channel)->draft()->create(['user_id' => $this->user->id]);

    $card = PostCardResource::make($post->fresh())->resolve();

    expect($card)->not->toHaveKeys(['social_account', 'user', 'labels'])
        ->and($card)->toEqual([...$post->fresh()->toArray(), 'failure' => null]);
});
