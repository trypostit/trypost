<?php

declare(strict_types=1);

use App\Actions\Post\CreatePosts;
use App\Enums\Post\CreatedVia;
use App\Enums\Post\Status;
use App\Enums\PostPlatform\ContentType;
use App\Events\PostCreated;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Event;

/** @param array<string, mixed> $data */
function createSinglePostForActionTest(Workspace $workspace, User $user, array $data): Post
{
    $account = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);

    return CreatePosts::execute($workspace, $user, [
        ...$data,
        'status' => Status::Draft->value,
        'destinations' => [[
            'social_account_id' => $account->id,
            'content_type' => ContentType::LinkedInPost->value,
        ]],
    ])->sole();
}

test('execute relies on the observer to dispatch PostCreated', function () {
    Event::fake([PostCreated::class]);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);

    $post = createSinglePostForActionTest($workspace, $user, [
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

    $post = createSinglePostForActionTest($workspace, $user, [
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

    $post = createSinglePostForActionTest($workspace, $user, [
        'content' => 'Hello world',
    ]);

    expect($post->fresh()->created_via)->toBeNull();
});
