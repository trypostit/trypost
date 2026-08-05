<?php

declare(strict_types=1);

use App\Actions\Post\DuplicatePost;
use App\Enums\Post\CreatedVia;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\Status as PostPlatformStatus;
use App\Enums\SocialAccount\Platform;
use App\Events\PostCreated;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Event;

test('execute clones the post as a draft created via web', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $original = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'content' => 'Original content',
        'created_via' => CreatedVia::Api,
        'status' => PostStatus::Published,
    ]);

    $copy = DuplicatePost::execute($original, $user);

    expect($copy->id)->not->toBe($original->id)
        ->and($copy->content)->toBe('Original content')
        ->and($copy->status)->toBe(PostStatus::Draft)
        ->and($copy->created_via)->toBe(CreatedVia::Web)
        ->and($copy->scheduled_at)->toBeNull()
        ->and($copy->published_at)->toBeNull();
});

test('execute relies on the observer to dispatch PostCreated for the duplicate', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $original = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    Event::fake([PostCreated::class]);

    $copy = DuplicatePost::execute($original, $user);

    Event::assertDispatched(
        PostCreated::class,
        fn (PostCreated $event) => $event->post->id === $copy->id,
    );
});

test('execute skips platform rows whose social account was removed', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $liveAccount = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::X,
        'display_name' => 'Live Account',
        'username' => 'live_user',
    ]);

    $original = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'status' => PostStatus::Published,
    ]);

    PostPlatform::factory()->x()->published()->create([
        'post_id' => $original->id,
        'social_account_id' => $liveAccount->id,
        'platform_name' => 'Live Account',
        'platform_username' => 'live_user',
        'platform_avatar' => 'avatars/live.jpg',
        'enabled' => true,
    ]);

    // History row left after disconnect: FK nullOnDelete + snapshot fields.
    PostPlatform::factory()->tiktok()->published()->create([
        'post_id' => $original->id,
        'social_account_id' => null,
        'platform_name' => 'Removed Account',
        'platform_username' => 'gone_user',
        'platform_avatar' => 'avatars/orphan.jpg',
        'enabled' => true,
    ]);

    $copy = DuplicatePost::execute($original->load(['postPlatforms', 'labels']), $user);

    $copiedPlatforms = $copy->postPlatforms()->get();

    expect($copiedPlatforms)->toHaveCount(1)
        ->and($copiedPlatforms->first()->social_account_id)->toBe($liveAccount->id)
        ->and($copiedPlatforms->first()->platform)->toBe(Platform::X)
        ->and($copiedPlatforms->first()->platform_name)->toBe('Live Account')
        ->and($copiedPlatforms->first()->status)->toBe(PostPlatformStatus::Pending)
        ->and($copiedPlatforms->first()->enabled)->toBeTrue();
});

test('execute copies only platforms that still have a social account relation', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id]);

    $original = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'status' => PostStatus::Published,
    ]);

    $platform = PostPlatform::factory()->published()->create([
        'post_id' => $original->id,
        'social_account_id' => $account->id,
        'platform_name' => $account->display_name,
        'platform_username' => $account->username,
        'enabled' => true,
    ]);

    $account->delete();
    $platform->refresh();

    expect($platform->social_account_id)->toBeNull();

    $copy = DuplicatePost::execute($original->fresh(['postPlatforms', 'labels']), $user);

    expect($copy->postPlatforms)->toHaveCount(0);
});
