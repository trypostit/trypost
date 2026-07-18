<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Jobs\Ai\StreamPostContent;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AiPromptRules;
use Illuminate\Support\Facades\Bus;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
});

test('endpoint requires authentication', function () {
    $this->postJson(route('app.posts.ai.generate', $this->post), ['prompt' => 'hi'])
        ->assertStatus(Response::HTTP_UNAUTHORIZED);
});

test('endpoint validates prompt is required', function () {
    Bus::fake();
    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.generate', $this->post), [])
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonValidationErrors(['prompt']);
});

test('endpoint rejects a prompt longer than the maximum length', function () {
    Bus::fake();

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.generate', $this->post), [
            'prompt' => str_repeat('a', AiPromptRules::PROMPT_MAX_LENGTH + 1),
        ])
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonValidationErrors(['prompt']);

    Bus::assertNotDispatched(StreamPostContent::class);
});

test('endpoint blocks access to other workspace posts', function () {
    Bus::fake();
    $otherWorkspace = Workspace::factory()->create();
    $foreignPost = Post::factory()->create(['workspace_id' => $otherWorkspace->id]);

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.generate', $foreignPost), ['prompt' => 'hi'])
        ->assertStatus(Response::HTTP_NOT_FOUND);
});

test('endpoint dispatches StreamPostContent and returns generation id and channel', function () {
    Bus::fake();

    $response = $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.generate', $this->post), [
            'prompt' => 'Write a post about Mondays',
            'current_content' => 'Old content',
        ])
        ->assertStatus(Response::HTTP_ACCEPTED);

    $generationId = $response->json('generation_id');
    expect($generationId)->toBeString()->not->toBeEmpty();
    expect($response->json('channel'))->toBe("user.{$this->user->id}.ai-gen.{$generationId}");

    Bus::assertDispatched(StreamPostContent::class, function ($job) use ($generationId) {
        return $job->workspaceId === $this->workspace->id
            && $job->userId === $this->user->id
            && $job->generationId === $generationId
            && $job->prompt === 'Write a post about Mondays'
            && $job->currentContent === 'Old content';
    });
});
