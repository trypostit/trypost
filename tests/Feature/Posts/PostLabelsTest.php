<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->user->account_id, 'user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $this->campaign = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Campaign']);
    $this->launch = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Launch']);
});

function labelledPost(Workspace $workspace, User $user, PostStatus $status = PostStatus::Draft): Post
{
    return Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'status' => $status,
    ]);
}

test('assigns labels to a post', function (PostStatus $status) {
    $post = labelledPost($this->workspace, $this->user, $status);

    $this->actingAs($this->user)
        ->from(route('app.posts.index'))
        ->patch(route('app.posts.labels.update', $post), ['labels' => [$this->campaign->id, $this->launch->id]])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('app.posts.index'));

    expect($post->labels()->pluck('workspace_labels.id')->sort()->values()->all())
        ->toEqual(collect([$this->campaign->id, $this->launch->id])->sort()->values()->all());
})->with([
    'draft' => PostStatus::Draft,
    'scheduled' => PostStatus::Scheduled,
    'published' => PostStatus::Published,
    'failed' => PostStatus::Failed,
]);

test('unassigns a label and clears all labels', function () {
    $post = labelledPost($this->workspace, $this->user);
    $post->labels()->attach([$this->campaign->id, $this->launch->id]);

    $this->actingAs($this->user)
        ->patch(route('app.posts.labels.update', $post), ['labels' => [$this->launch->id]])
        ->assertSessionHasNoErrors();

    expect($post->labels()->pluck('workspace_labels.id')->all())->toBe([$this->launch->id]);

    $this->actingAs($this->user)
        ->patch(route('app.posts.labels.update', $post), ['labels' => []])
        ->assertSessionHasNoErrors();

    expect($post->labels()->count())->toBe(0);
});

test('does not touch the post timestamps or status', function () {
    $post = labelledPost($this->workspace, $this->user, PostStatus::Published);
    $updatedAt = $post->updated_at->toIso8601String();

    $this->travel(5)->minutes();

    $this->actingAs($this->user)
        ->patch(route('app.posts.labels.update', $post), ['labels' => [$this->campaign->id]])
        ->assertSessionHasNoErrors();

    $fresh = $post->fresh();

    expect($fresh->updated_at->toIso8601String())->toBe($updatedAt)
        ->and($fresh->status)->toBe(PostStatus::Published);
});

test('rejects a label from another workspace', function () {
    $post = labelledPost($this->workspace, $this->user);
    $foreign = WorkspaceLabel::factory()->create();

    $this->actingAs($this->user)
        ->patch(route('app.posts.labels.update', $post), ['labels' => [$this->campaign->id, $foreign->id]])
        ->assertSessionHasErrors('labels.1');

    expect($post->labels()->count())->toBe(0);
});

test('rejects a deleted label', function () {
    $post = labelledPost($this->workspace, $this->user);
    $this->campaign->delete();

    $this->actingAs($this->user)
        ->patch(route('app.posts.labels.update', $post), ['labels' => [$this->campaign->id]])
        ->assertSessionHasErrors('labels.0');

    expect($post->labels()->withTrashed()->count())->toBe(0);
});

test('requires the labels field', function () {
    $post = labelledPost($this->workspace, $this->user);

    $this->actingAs($this->user)
        ->patch(route('app.posts.labels.update', $post), [])
        ->assertSessionHasErrors('labels');
});

test('returns 404 for a post in another workspace', function () {
    $otherUser = User::factory()->create();
    $otherWorkspace = Workspace::factory()->create(['account_id' => $otherUser->account_id, 'user_id' => $otherUser->id]);
    $post = labelledPost($otherWorkspace, $otherUser);

    $this->actingAs($this->user)
        ->patch(route('app.posts.labels.update', $post), ['labels' => []])
        ->assertNotFound();
});

test('forbids a user outside the workspace from changing labels', function () {
    $post = labelledPost($this->workspace, $this->user);
    $outsider = workspaceOutsider($this->workspace);

    $this->actingAs($outsider)
        ->patch(route('app.posts.labels.update', $post), ['labels' => [$this->campaign->id]])
        ->assertForbidden();

    expect($post->labels()->count())->toBe(0);
});

test('requires authentication', function () {
    $post = labelledPost($this->workspace, $this->user);

    $this->patch(route('app.posts.labels.update', $post), ['labels' => []])
        ->assertRedirect(route('login'));
});
