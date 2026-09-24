<?php

declare(strict_types=1);

use App\Actions\Post\UpdatePost;
use App\Enums\Post\Status;
use App\Events\PostCreated;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

test('split preserves IDs and clones labels, media and nested comments once', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'status' => Status::Scheduled,
        'scheduled_at' => now()->addDay(),
        'content' => 'Legacy shared caption',
        'media' => [['id' => 'media-id', 'path' => 'posts/photo.jpg']],
    ]);
    $accounts = SocialAccount::factory()->count(3)->create(['workspace_id' => $workspace->id]);
    $first = PostPlatform::factory()->create(['post_id' => $post->id, 'social_account_id' => $accounts[0]->id]);
    $second = PostPlatform::factory()->create(['post_id' => $post->id, 'social_account_id' => $accounts[1]->id]);
    $disabled = PostPlatform::factory()->disabled()->create(['post_id' => $post->id, 'social_account_id' => $accounts[2]->id]);
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id]);
    $post->labels()->attach($label);
    $comment = PostComment::factory()->create(['post_id' => $post->id, 'user_id' => $user->id]);
    $reply = PostComment::factory()->reply($comment)->create([
        'user_id' => $user->id,
        'updated_at' => now()->subDays(3),
    ]);

    Event::fake([PostCreated::class]);
    $this->artisan('posts:split-legacy-active')->assertSuccessful();
    $this->artisan('posts:split-legacy-active')->assertSuccessful();

    $clone = Post::whereKeyNot($post->id)->sole();
    expect(Post::count())->toBe(2)
        ->and($first->fresh()->post_id)->toBe($post->id)
        ->and($second->fresh()->post_id)->toBe($clone->id)
        ->and($disabled->fresh()->post_id)->toBe($post->id)
        ->and($clone->content)->toBe($post->content)
        ->and($clone->media)->toEqual($post->media)
        ->and($clone->scheduled_at->equalTo($post->scheduled_at))->toBeTrue()
        ->and($clone->labels()->pluck('workspace_labels.id')->all())->toBe([$label->id])
        ->and($clone->comments()->count())->toBe(2)
        ->and($clone->comments()->where('body', $reply->body)->sole()->parent_id)
        ->toBe($clone->comments()->where('body', $comment->body)->sole()->id)
        ->and($clone->comments()->where('body', $reply->body)->sole()->updated_at->equalTo($reply->updated_at))->toBeTrue();
    Event::assertNotDispatched(PostCreated::class);
});

test('split leaves settled and in-flight aggregate rows untouched', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $accounts = SocialAccount::factory()->count(2)->create(['workspace_id' => $workspace->id]);
    foreach ([Status::Published, Status::Publishing] as $status) {
        $post = Post::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'status' => $status]);
        foreach ($accounts as $account) {
            PostPlatform::factory()->create(['post_id' => $post->id, 'social_account_id' => $account->id]);
        }
    }

    $this->artisan('posts:split-legacy-active')->assertSuccessful();

    expect(Post::count())->toBe(2)
        ->and(PostPlatform::count())->toBe(4);
});

test('the original post remains editable after a split with a disabled target', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $accounts = SocialAccount::factory()->linkedin()->count(3)->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'status' => Status::Draft,
        'content' => 'Original caption',
        'media' => [],
    ]);
    $first = PostPlatform::factory()->create(['post_id' => $post->id, 'social_account_id' => $accounts[0]->id]);
    $second = PostPlatform::factory()->create(['post_id' => $post->id, 'social_account_id' => $accounts[1]->id]);
    $disabled = PostPlatform::factory()->disabled()->create(['post_id' => $post->id, 'social_account_id' => $accounts[2]->id]);

    $this->artisan('posts:split-legacy-active')->assertSuccessful();
    UpdatePost::execute($workspace, $post->fresh(), [
        'status' => Status::Draft->value,
        'content' => 'Changed caption',
        'content_type' => $first->content_type->value,
    ]);

    expect($post->fresh()->content)->toBe('Changed caption')
        ->and($second->fresh()->post->content)->toBe('Original caption')
        ->and($disabled->fresh()->enabled)->toBeFalse();

    expect(fn () => UpdatePost::execute($workspace, $post->fresh(), [
        'platforms' => [['id' => $disabled->id]],
    ]))->toThrow(ValidationException::class);
    expect($disabled->fresh()->enabled)->toBeFalse();
});
