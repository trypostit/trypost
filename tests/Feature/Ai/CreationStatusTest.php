<?php

declare(strict_types=1);

use App\Enums\Ai\DraftStatus;
use App\Enums\UserWorkspace\Role;
use App\Models\AiPostDraft;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

function makeDraft(Workspace $workspace, User $user, DraftStatus $status, ?string $postId = null): AiPostDraft
{
    return AiPostDraft::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'format' => 'instagram_carousel',
        'template' => 'tweet_mock_light',
        'image_count' => 5,
        'prompt' => 'teste',
        'status' => $status,
        'post_id' => $postId,
        'error' => $status === DraftStatus::Failed ? 'Algo deu errado.' : null,
    ]);
}

test('status returns done with post id for a completed draft', function () {
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);
    $draft = makeDraft($this->workspace, $this->user, DraftStatus::Completed, $post->id);

    $this->actingAs($this->user)
        ->getJson(route('app.posts.ai.creation-status', $draft->id))
        ->assertOk()
        ->assertJson(['status' => 'done', 'post_id' => $post->id]);
});

test('status returns pending while generating and error when failed', function () {
    $generating = makeDraft($this->workspace, $this->user, DraftStatus::Generating);
    $failed = makeDraft($this->workspace, $this->user, DraftStatus::Failed);

    $this->actingAs($this->user)
        ->getJson(route('app.posts.ai.creation-status', $generating->id))
        ->assertJson(['status' => 'pending']);

    $this->actingAs($this->user)
        ->getJson(route('app.posts.ai.creation-status', $failed->id))
        ->assertJson(['status' => 'error', 'error' => 'Algo deu errado.']);
});

test('status is unknown for missing ids and drafts of other workspaces', function () {
    $foreign = makeDraft(Workspace::factory()->create(), User::factory()->create(), DraftStatus::Completed);

    $this->actingAs($this->user)
        ->getJson(route('app.posts.ai.creation-status', (string) Str::uuid()))
        ->assertJson(['status' => 'unknown']);

    $this->actingAs($this->user)
        ->getJson(route('app.posts.ai.creation-status', $foreign->id))
        ->assertJson(['status' => 'unknown']);
});
