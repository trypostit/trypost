<?php

declare(strict_types=1);

use App\Actions\Post\CreatePosts;
use App\Actions\Post\UpdatePost;
use App\Dto\MediaItem;
use App\Enums\Post\Action as PostAction;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Models\Media;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake();
});

test('editing one Instagram post changes its type, media and caption without changing its sibling', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $accounts = SocialAccount::factory()->instagram()->count(2)->create(['workspace_id' => $workspace->id]);
    $video = Media::factory()->stored()->video()->temporaryUpload($workspace)->create();
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
        ->and($posts[0]->fresh()->content_type)->toBe(ContentType::InstagramReel)
        ->and($posts[0]->fresh()->social_account_id)->toBe($accounts[0]->id)
        ->and($posts[1]->fresh()->content)->toBe('Texto inicial')
        ->and($posts[1]->fresh()->content_type)->toBe(ContentType::InstagramFeed);
});

test('an incompatible type and media leaves the edited post unchanged', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);
    $image = Media::factory()->stored()->temporaryUpload($workspace)->create();
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
        ->and($post->fresh()->content_type)->toBe(ContentType::InstagramFeed);
});

test('the selected social account cannot change during edit', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $accounts = SocialAccount::factory()->instagram()->count(2)->create(['workspace_id' => $workspace->id]);
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

    expect($post->fresh()->social_account_id)->toBe($accounts[0]->id);
});

test('a settled post cannot use the independent editor', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);
    $post = CreatePosts::execute($workspace, $user, [
        'status' => 'draft',
        'destinations' => [['social_account_id' => $instagram->id, 'content_type' => ContentType::InstagramFeed->value]],
    ])->sole();

    $post->update(['status' => PostStatus::Published]);

    expect(UpdatePost::execute($workspace, $post, [
        'status' => PostStatus::Draft->value,
        'content_type' => ContentType::InstagramStory->value,
    ])['action'])->toBe(PostAction::Finalized)
        ->and($post->fresh()->content_type)->toBe(ContentType::InstagramFeed);
});

test('an edit stores only known meta keys and keeps the system-written ones', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $account = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $post = CreatePosts::execute($workspace, $user, [
        'status' => 'draft',
        'content' => 'Caption',
        'destinations' => [['social_account_id' => $account->id]],
    ])->sole();
    $post->forceFill(['meta' => ['reactions' => [['type' => '👍', 'count' => 3]], 'aspect_ratio' => '4:5']])->save();

    UpdatePost::execute($workspace, $post, [
        'status' => 'draft',
        'meta' => ['bogus_key' => 'zzz', 'aspect_ratio' => '1:1', 'is_ai_generated' => true, 'reactions' => []],
    ]);

    expect($post->fresh()->meta)->toEqual([
        'is_ai_generated' => true,
        'reactions' => [['type' => '👍', 'count' => 3]],
    ]);
});
