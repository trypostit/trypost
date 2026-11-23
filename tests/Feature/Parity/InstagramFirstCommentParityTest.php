<?php

declare(strict_types=1);

use App\Dto\MediaItem;
use App\Enums\Post\Status;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\ApprovePostTool;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
    ['user' => $this->user, 'workspace' => $this->workspace, 'token' => $this->token] = parityContext();
});

test('instagram first comments can be saved as a draft before a reconnect and then scheduled', function (string $surface, Platform $platform, array $publishScopes, string $commentScope) {
    config(["trypost.platforms.{$platform->value}.scopes" => [...$publishScopes, $commentScope]]);
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => $platform,
        'scopes' => $publishScopes,
    ]);
    $post = Post::factory()->forAccount($account)->draft()->create(['user_id' => $this->user->id]);
    $comment = ['text' => 'More details in the bio', 'media' => []];

    $response = pollParityUpdate($surface, $post, ['meta' => ['thread_replies' => [$comment]]]);
    match ($surface) {
        'mcp' => $response->assertOk(),
        'web', 'api' => $response->assertSessionHasNoErrors(),
    };
    expect(data_get($post->fresh()->meta, 'thread_replies'))->toBe([$comment]);

    $upload = Media::factory()->temporaryUpload($this->workspace)->stored()->create();
    $schedule = [
        'status' => Status::Scheduled->value,
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'media' => [$surface === 'web' ? MediaItem::fromMedia($upload)->toArray() : ['upload_token' => $upload->upload_token]],
        'meta' => ['thread_replies' => [$comment]],
    ];

    $response = pollParityUpdate($surface, $post, $schedule);
    $message = __('channels.permissions.thread_replies.message', ['network' => $platform->label()]);
    match ($surface) {
        'mcp' => $response->assertHasErrors([$message]),
        'web', 'api' => $response->assertUnprocessable()->assertJsonValidationErrors(['meta.thread_replies' => $message]),
    };
    expect($post->fresh()->status)->toBe(Status::Draft);

    $account->update(['scopes' => [...$publishScopes, $commentScope]]);
    $response = pollParityUpdate($surface, $post, $schedule);
    match ($surface) {
        'mcp' => $response->assertOk(),
        'web', 'api' => $response->assertSessionHasNoErrors(),
    };

    expect($post->fresh())->status->toBe(Status::Scheduled)->content_type->toBe(ContentType::InstagramFeed)
        ->and(data_get($post->fresh()->meta, 'thread_replies'))->toBe([$comment]);
})->with(['web', 'api', 'mcp'])->with([
    'instagram login' => [Platform::Instagram, ['instagram_business_basic', 'instagram_business_content_publish'], 'instagram_business_manage_comments'],
    'facebook login' => [Platform::InstagramFacebook, ['instagram_basic', 'pages_read_engagement', 'instagram_content_publish'], 'instagram_manage_comments'],
]);

test('approval rechecks an instagram comment grant that was revoked while awaiting approval', function (string $surface) {
    $channel = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    $requester = workspaceMember($this->workspace, 'approval');
    $post = Post::factory()->forAccount($channel)->pendingApproval()->create([
        'user_id' => $requester->id,
        'scheduled_at' => now()->addDay(),
        'meta' => ['thread_replies' => [['text' => 'A saved first comment', 'media' => []]]],
    ]);
    $photo = Media::factory()->ownedByPost($post)->stored()->create();
    $post->update(['media' => [MediaItem::fromMedia($photo)->toArray()]]);

    $message = __('channels.permissions.thread_replies.message', ['network' => 'Instagram']);
    match ($surface) {
        'web' => $this->actingAs($this->user)->putJson(route('app.posts.approve', $post))->assertUnprocessable()
            ->assertJsonValidationErrors(['meta.thread_replies' => $message]),
        'api' => $this->withHeaders(parityApi($this->token))->postJson(route('api.posts.approve', $post))->assertUnprocessable()
            ->assertJsonValidationErrors(['meta.thread_replies' => $message]),
        'mcp' => TryPostServer::actingAs($this->user)->tool(ApprovePostTool::class, ['post_id' => $post->id])->assertHasErrors([$message]),
    };

    expect($post->fresh()->status)->toBe(Status::PendingApproval);
})->with(['web', 'api', 'mcp']);

test('an instagram story with a comment reports the format restriction before the missing comment grant', function (string $surface) {
    $account = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $this->workspace->id,
        'scopes' => ['instagram_business_basic', 'instagram_business_content_publish'],
    ]);
    $post = Post::factory()->forAccount($account, ContentType::InstagramFeed)->draft()->create(['user_id' => $this->user->id]);
    $upload = Media::factory()->temporaryUpload($this->workspace)->stored()->create(['meta' => ['width' => 1080, 'height' => 1920]]);

    $response = pollParityUpdate($surface, $post, [
        'content_type' => ContentType::InstagramStory->value,
        'status' => Status::Scheduled->value,
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'media' => [$surface === 'web' ? MediaItem::fromMedia($upload)->toArray() : ['upload_token' => $upload->upload_token]],
        'meta' => ['thread_replies' => [['text' => 'A first comment']]],
    ]);

    $message = __('posts.form.thread.instagram_story');
    match ($surface) {
        'mcp' => $response->assertHasErrors([$message]),
        'web', 'api' => $response->assertUnprocessable()->assertJsonValidationErrors(['meta.thread_replies' => $message]),
    };
})->with(['web', 'api', 'mcp']);
