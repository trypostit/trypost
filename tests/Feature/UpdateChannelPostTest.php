<?php

declare(strict_types=1);

use App\Actions\Post\CreatePosts;
use App\Actions\Post\UpdatePost;
use App\Dto\MediaItem;
use App\Enums\Post\Action as PostAction;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Models\Media;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Validation\ValidationException;

test('editing one Instagram post changes its type, media and caption without changing its sibling', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $accounts = SocialAccount::factory()->instagram()->count(2)->create(['workspace_id' => $workspace->id, 'is_active' => true]);
    $video = Media::factory()->assets()->video()->for($workspace, 'mediable')->create();
    $posts = CreatePosts::execute($workspace, $user, [
        'status' => 'draft',
        'content' => 'Texto inicial',
        'destinations' => $accounts->map(fn (SocialAccount $account): array => [
            'social_account_id' => $account->id,
            'content_type' => ContentType::InstagramFeed->value,
        ])->all(),
    ]);

    UpdatePost::execute($workspace, $posts[0], [
        'status' => PostStatus::Scheduled->value,
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'content' => 'Legenda do Reel',
        'media' => [MediaItem::fromMedia($video)->toArray()],
        'content_type' => ContentType::InstagramReel->value,
    ]);

    expect($posts[0]->fresh()->content)->toBe('Legenda do Reel')
        ->and($posts[0]->fresh()->status)->toBe(PostStatus::Scheduled)
        ->and($posts[0]->postPlatforms()->sole()->content_type)->toBe(ContentType::InstagramReel)
        ->and($posts[0]->postPlatforms()->sole()->social_account_id)->toBe($accounts[0]->id)
        ->and($posts[1]->fresh()->content)->toBe('Texto inicial')
        ->and($posts[1]->postPlatforms()->sole()->content_type)->toBe(ContentType::InstagramFeed);
});

test('an incompatible type and media leaves the edited post unchanged', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id, 'is_active' => true]);
    $image = Media::factory()->assets()->for($workspace, 'mediable')->create();
    $post = CreatePosts::execute($workspace, $user, [
        'status' => 'draft',
        'content' => 'Antes',
        'destinations' => [['social_account_id' => $account->id, 'content_type' => ContentType::InstagramFeed->value]],
    ])->sole();

    expect(fn () => UpdatePost::execute($workspace, $post, [
        'status' => PostStatus::Scheduled->value,
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'content' => 'Depois',
        'media' => [MediaItem::fromMedia($image)->toArray()],
        'content_type' => ContentType::InstagramReel->value,
    ]))->toThrow(ValidationException::class);

    expect($post->fresh()->content)->toBe('Antes')
        ->and($post->fresh()->status)->toBe(PostStatus::Draft)
        ->and($post->postPlatforms()->sole()->content_type)->toBe(ContentType::InstagramFeed);
});

test('the selected social account cannot change during edit', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $accounts = SocialAccount::factory()->instagram()->count(2)->create(['workspace_id' => $workspace->id, 'is_active' => true]);
    $post = CreatePosts::execute($workspace, $user, [
        'status' => 'draft',
        'destinations' => [['social_account_id' => $accounts[0]->id, 'content_type' => ContentType::InstagramFeed->value]],
    ])->sole();

    expect(fn () => UpdatePost::execute($workspace, $post, [
        'status' => PostStatus::Draft->value,
        'social_account_id' => $accounts[1]->id,
    ]))->toThrow(ValidationException::class);

    expect(fn () => UpdatePost::execute($workspace, $post, [
        'status' => PostStatus::Draft->value,
        'platforms' => [['social_account_id' => $accounts[1]->id]],
    ]))->toThrow(ValidationException::class);

    expect($post->postPlatforms()->sole()->social_account_id)->toBe($accounts[0]->id);
});

test('settled posts and legacy multi-target posts cannot use the independent editor', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id, 'is_active' => true]);
    $xAccount = SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id, 'is_active' => true]);
    $post = CreatePosts::execute($workspace, $user, [
        'status' => 'draft',
        'destinations' => [['social_account_id' => $instagram->id, 'content_type' => ContentType::InstagramFeed->value]],
    ])->sole();

    PostPlatform::factory()->x()->create(['post_id' => $post->id, 'social_account_id' => $xAccount->id]);

    expect(fn () => UpdatePost::execute($workspace, $post, [
        'status' => PostStatus::Draft->value,
        'content_type' => ContentType::InstagramStory->value,
    ]))->toThrow(ValidationException::class);

    $post->postPlatforms()->where('social_account_id', $xAccount->id)->delete();
    $post->update(['status' => PostStatus::Published]);

    expect(UpdatePost::execute($workspace, $post, [
        'status' => PostStatus::Draft->value,
        'content_type' => ContentType::InstagramStory->value,
    ])['action'])->toBe(PostAction::Finalized);
});
