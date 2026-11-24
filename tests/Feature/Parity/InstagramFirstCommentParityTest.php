<?php

declare(strict_types=1);

use App\Actions\Post\AppendPostMedia;
use App\Actions\Post\Queue\ReorderChannelQueue;
use App\Dto\MediaItem;
use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\ApprovePostTool;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake();
    ['user' => $this->user, 'workspace' => $this->workspace, 'token' => $this->token] = parityContext();
});

test('instagram first comments can be saved as a draft before a reconnect and then scheduled', function (string $surface, Platform $platform, array $publishScopes, string $commentScope, ContentType $type) {
    config(["trypost.platforms.{$platform->value}.scopes" => [...$publishScopes, $commentScope]]);
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => $platform,
        'scopes' => $publishScopes,
    ]);
    $post = Post::factory()->forAccount($account, $type)->draft()->create(['user_id' => $this->user->id]);
    $comment = ['text' => 'More details in the bio', 'media' => []];

    $response = pollParityUpdate($surface, $post, ['meta' => ['thread_replies' => [$comment]]]);
    match ($surface) {
        'mcp' => $response->assertOk(),
        'web', 'api' => $response->assertSessionHasNoErrors(),
    };
    expect(data_get($post->fresh()->meta, 'thread_replies'))->toBe([$comment]);

    $upload = Media::factory()->temporaryUpload($this->workspace)->stored();
    if ($type === ContentType::InstagramReel) {
        $upload = $upload->video()->state(['meta' => ['width' => 1080, 'height' => 1920, 'duration' => 20]]);
    }
    $upload = $upload->create();
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

    expect($post->fresh())->status->toBe(Status::Scheduled)->content_type->toBe($type)
        ->and(data_get($post->fresh()->meta, 'thread_replies'))->toBe([$comment]);
})->with(['web', 'api', 'mcp'])->with([
    'instagram login' => [Platform::Instagram, ['instagram_business_basic', 'instagram_business_content_publish'], 'instagram_business_manage_comments'],
    'facebook login' => [Platform::InstagramFacebook, ['instagram_basic', 'pages_read_engagement', 'instagram_content_publish'], 'instagram_manage_comments'],
])->with([
    'feed' => [ContentType::InstagramFeed],
    'reel' => [ContentType::InstagramReel],
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

test('changing a queued instagram story reports its comment format restriction before a missing grant', function (string $action, string $errorField) {
    $scopes = ['instagram_business_basic', 'instagram_business_content_publish'];
    config(['trypost.platforms.instagram.scopes' => [...$scopes, 'instagram_business_manage_comments']]);
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id, 'scopes' => $scopes]);
    $first = Post::factory()->forAccount($account)->scheduled()->create([
        'user_id' => $this->user->id,
        'schedule_mode' => ScheduleMode::Queue,
    ]);
    $story = Post::factory()->forAccount($account, ContentType::InstagramStory)->scheduled()->create([
        'user_id' => $this->user->id,
        'schedule_mode' => ScheduleMode::Queue,
        'scheduled_at' => $first->scheduled_at->copy()->addHour(),
        'meta' => ['thread_replies' => ['A saved first comment']],
    ]);

    $change = match ($action) {
        'attach' => fn () => AppendPostMedia::ensureAcceptsMedia($story, $this->user),
        'reorder' => fn () => ReorderChannelQueue::handle($account, [$story->id, $first->id], $this->user),
    };

    expect($change)->toThrow(fn (ValidationException $exception) => expect($exception->errors())
        ->toBe([$errorField => [__('posts.form.thread.instagram_story')]]));
    expect($story->fresh()->scheduled_at->equalTo($story->scheduled_at))->toBeTrue()
        ->and($first->fresh()->scheduled_at->equalTo($first->scheduled_at))->toBeTrue();
})->with([
    'attach media' => ['attach', 'meta.thread_replies'],
    'reorder queue' => ['reorder', 'post_ids'],
]);

test('an instagram story with a comment reports the format restriction before the missing comment grant', function (string $surface, Platform $platform, array $publishScopes, string $commentScope) {
    config(["trypost.platforms.{$platform->value}.scopes" => [...$publishScopes, $commentScope]]);
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => $platform,
        'scopes' => $publishScopes,
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
})->with(['web', 'api', 'mcp'])->with([
    'instagram login' => [Platform::Instagram, ['instagram_business_basic', 'instagram_business_content_publish'], 'instagram_business_manage_comments'],
    'facebook login' => [Platform::InstagramFacebook, ['instagram_basic', 'pages_read_engagement', 'instagram_content_publish'], 'instagram_manage_comments'],
]);

test('every surface refuses a second instagram comment, a comment with media and a comment over 2,200 characters', function (string $surface, string $case) {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'scopes' => ['instagram_business_basic', 'instagram_business_content_publish', 'instagram_business_manage_comments'],
    ]);
    $post = Post::factory()->forAccount($account)->draft()->create(['user_id' => $this->user->id]);
    $photo = fn (): Media => Media::factory()->temporaryUpload($this->workspace)->stored()->create();
    $mediaItem = fn (Media $upload): array => $surface === 'web' ? MediaItem::fromMedia($upload)->toArray() : ['upload_token' => $upload->upload_token];

    [$comments, $field, $message] = match ($case) {
        'second comment' => [
            [['text' => 'First', 'media' => []], ['text' => 'Second', 'media' => []]],
            'meta.thread_replies',
            trans_choice('posts.form.thread.too_many', 1, ['max' => 1]),
        ],
        'media' => [
            [['text' => 'With a photo', 'media' => [$mediaItem($photo())]]],
            'meta.thread_replies.0.media',
            __('posts.form.warnings.max_files_exceeded', ['max' => 0, 'current' => 1]),
        ],
        'too long' => [
            [['text' => Str::repeat('a', 2201), 'media' => []]],
            'meta.thread_replies.0',
            __('posts.form.thread.comment_too_long', ['limit' => 2200, 'over' => 1]),
        ],
    };

    $response = pollParityUpdate($surface, $post, [
        'status' => Status::Scheduled->value,
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'media' => [$mediaItem($photo())],
        'meta' => ['thread_replies' => $comments],
    ]);

    match ($surface) {
        'mcp' => $response->assertHasErrors([$message]),
        'web', 'api' => $response->assertUnprocessable()->assertJsonValidationErrors([$field => $message]),
    };
    expect($post->fresh()->status)->toBe(Status::Draft);
})->with(['web', 'api', 'mcp'])->with(['second comment', 'media', 'too long']);

test('no surface stores a post as an instagram comment', function (string $surface, Platform $platform) {
    $account = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => $platform]);
    $post = Post::factory()->forAccount($account, ContentType::InstagramFeed)->draft()->create(['user_id' => $this->user->id]);

    $response = pollParityUpdate($surface, $post, ['content_type' => ContentType::InstagramComment->value]);

    match ($surface) {
        'mcp' => $response->assertHasErrors(),
        'web', 'api' => $response->assertUnprocessable()->assertJsonValidationErrors(['content_type']),
    };
    expect($post->fresh()->content_type)->toBe(ContentType::InstagramFeed);
})->with(['web', 'api', 'mcp'])->with([Platform::Instagram, Platform::InstagramFacebook]);
