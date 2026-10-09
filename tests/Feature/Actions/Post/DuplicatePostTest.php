<?php

declare(strict_types=1);

use App\Actions\Post\DuplicatePost;
use App\Enums\Post\CreatedVia;
use App\Enums\Post\PublishStatus;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Events\PostCreated;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

test('execute clones the post as a draft created via web', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $account = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $original = Post::factory()->forAccount($account)->published()->create([
        'user_id' => $user->id,
        'content' => 'Original content',
        'created_via' => CreatedVia::Api,
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
    $account = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $original = Post::factory()->forAccount($account)->create([
        'user_id' => $user->id,
    ]);

    Event::fake([PostCreated::class]);

    $copy = DuplicatePost::execute($original, $user);

    Event::assertDispatched(
        PostCreated::class,
        fn (PostCreated $event) => $event->post->id === $copy->id,
    );
});

test('execute copies the channel as a pending destination', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $account = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::X,
        'display_name' => 'Live Account',
        'username' => 'live_user',
    ]);

    $original = Post::factory()->forAccount($account)->x()->published()->create([
        'user_id' => $user->id,
        'platform_name' => 'Live Account',
        'platform_username' => 'live_user',
        'platform_avatar' => 'avatars/live.jpg',
    ]);

    $copy = DuplicatePost::execute($original, $user)->fresh();

    expect($copy->social_account_id)->toBe($account->id)
        ->and($copy->platform)->toBe(Platform::X)
        ->and($copy->content_type)->toBe(ContentType::XPost)
        ->and($copy->platform_name)->toBe('Live Account')
        ->and($copy->publish_status)->toBe(PublishStatus::Pending)
        ->and($copy->platform_post_id)->toBeNull();
});

test('execute refuses a duplicate when the channel was removed', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $account = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);

    $original = Post::factory()->forAccount($account)->published()->create([
        'user_id' => $user->id,
        'platform_name' => $account->display_name,
        'platform_username' => $account->username,
    ]);

    Post::query()->whereKey($original->id)->update(['social_account_id' => null]);

    expect(fn () => DuplicatePost::execute($original->fresh(), $user))
        ->toThrow(ValidationException::class);
});
