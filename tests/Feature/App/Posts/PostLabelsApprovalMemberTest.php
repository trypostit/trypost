<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Campaign']);
});

test('a member who needs approval can edit the labels of a post without changing its status', function () {
    $member = workspaceMember($this->workspace, 'approval');
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->owner->id,
        'status' => PostStatus::Scheduled,
    ]);

    $this->actingAs($member)
        ->from(route('app.posts.index'))
        ->patch(route('app.posts.labels.update', $post), ['labels' => [$this->label->id]])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('app.posts.index'));

    expect($post->labels()->pluck('workspace_labels.id')->all())->toBe([$this->label->id])
        ->and($post->fresh()->status)->toBe(PostStatus::Scheduled);
});
