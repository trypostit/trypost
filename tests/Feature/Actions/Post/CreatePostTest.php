<?php

declare(strict_types=1);

use App\Actions\Post\CreatePost;
use App\Enums\Post\CreatedVia;
use App\Events\PostCreated;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Event;

test('execute relies on the observer to dispatch PostCreated', function () {
    Event::fake([PostCreated::class]);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);

    $post = CreatePost::execute($workspace, $user, [
        'content' => 'Hello world',
        'created_via' => CreatedVia::Web,
    ]);

    Event::assertDispatched(
        PostCreated::class,
        fn (PostCreated $event) => $event->post->id === $post->id
            && $event->post->workspace_id === $workspace->id,
    );
});

test('execute persists created_via for each entry point', function (CreatedVia $createdVia) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);

    $post = CreatePost::execute($workspace, $user, [
        'content' => 'Hello world',
        'created_via' => $createdVia,
    ]);

    expect($post->fresh()->created_via)->toBe($createdVia);
})->with([
    'web' => CreatedVia::Web,
    'mcp' => CreatedVia::Mcp,
    'api' => CreatedVia::Api,
]);

test('execute leaves created_via null when omitted', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);

    $post = CreatePost::execute($workspace, $user, [
        'content' => 'Hello world',
    ]);

    expect($post->fresh()->created_via)->toBeNull();
});
