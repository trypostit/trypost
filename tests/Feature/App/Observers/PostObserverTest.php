<?php

declare(strict_types=1);

use App\Events\PostCreated;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Event;

test('creating a post dispatches PostCreated via the observer', function () {
    Event::fake([PostCreated::class]);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);

    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    Event::assertDispatched(
        PostCreated::class,
        fn (PostCreated $event) => $event->post->id === $post->id,
    );
});

test('creating a post quietly does not dispatch PostCreated', function () {
    Event::fake([PostCreated::class]);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);

    Post::factory()->createQuietly([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    Event::assertNotDispatched(PostCreated::class);
});
