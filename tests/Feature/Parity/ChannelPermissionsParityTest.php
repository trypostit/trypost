<?php

declare(strict_types=1);

use App\Enums\Post\Status;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\CreatePostsTool;
use App\Mcp\Tools\Post\PublishPostTool;
use App\Models\Post;
use App\Models\SocialAccount;

beforeEach(function () {
    ['user' => $this->user, 'workspace' => $this->workspace, 'token' => $this->token] = parityContext();
});

/** An X draft whose channel was connected without the scope X needs to publish. */
function postOnChannelWithoutPublishScope(): Post
{
    $post = pollParityPost(ContentType::XPost);
    $post->socialAccount->update(['scopes' => ['tweet.read', 'users.read']]);

    return $post;
}

test('a channel without the publish scope cannot schedule on the web, api and mcp, and the refusal names the network', function (string $surface) {
    $post = postOnChannelWithoutPublishScope();
    $message = __('channels.permissions.publish.message', ['network' => Platform::X->label()]);

    $response = pollParityUpdate($surface, $post, [
        'status' => Status::Scheduled->value,
        'scheduled_at' => now()->addDay()->toIso8601String(),
    ]);

    match ($surface) {
        'mcp' => $response->assertHasErrors([$message]),
        default => $response->assertUnprocessable()->assertJsonValidationErrors(['social_account_id' => $message]),
    };
    expect($post->fresh()->status)->toBe(Status::Draft);
})->with(['web', 'api', 'mcp']);

test('a channel without the publish scope still saves a draft on the web, api and mcp', function (string $surface) {
    $post = postOnChannelWithoutPublishScope();

    $response = pollParityUpdate($surface, $post, ['content' => 'Kept as a draft']);

    match ($surface) {
        'mcp' => $response->assertOk(),
        default => $response->assertSessionHasNoErrors(),
    };
    expect($post->fresh()->content)->toContain('Kept as a draft');
})->with(['web', 'api', 'mcp']);

test('publishing a stored post on a channel without the publish scope is refused before anything goes out', function () {
    $post = postOnChannelWithoutPublishScope();

    TryPostServer::actingAs($this->user)
        ->tool(PublishPostTool::class, ['post_id' => $post->id])
        ->assertHasErrors([__('channels.permissions.publish.message', ['network' => Platform::X->label()])]);

    expect($post->fresh()->status)->toBe(Status::Draft);
});

test('scheduling to several channels refuses only the one that lacks the publish scope, naming its destination', function () {
    $granted = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id]);
    $lacking = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id, 'scopes' => ['tweet.read', 'users.read']]);

    TryPostServer::actingAs($this->user)->tool(CreatePostsTool::class, [
        'content' => 'Two channels',
        'status' => Status::Scheduled->value,
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'destinations' => [
            ['social_account_id' => $granted->id, 'content_type' => ContentType::XPost->value],
            ['social_account_id' => $lacking->id, 'content_type' => ContentType::XPost->value],
        ],
    ])->assertHasErrors([__('channels.permissions.publish.message', ['network' => Platform::X->label()])]);

    expect(Post::query()->where('workspace_id', $this->workspace->id)->whereIn('social_account_id', [$granted->id, $lacking->id])->exists())->toBeFalse();
});

test('a channel missing only analytics scopes still schedules on the web, api and mcp', function (string $surface) {
    $post = pollParityPost(ContentType::XPost);
    $post->socialAccount->update(['scopes' => ['tweet.write']]);

    $response = pollParityUpdate($surface, $post, [
        'status' => Status::Scheduled->value,
        'scheduled_at' => now()->addDay()->toIso8601String(),
    ]);

    match ($surface) {
        'mcp' => $response->assertOk(),
        default => $response->assertSessionHasNoErrors(),
    };
    expect($post->fresh()->status)->toBe(Status::Scheduled);
})->with(['web', 'api', 'mcp']);
