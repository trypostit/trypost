<?php

declare(strict_types=1);

use App\Dto\MediaItem;
use App\Enums\Post\Status;
use App\Enums\PostPlatform\ContentType;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\UpdatePostTool;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(null, ['url' => 'https://cdn.example.com']);
    Queue::fake();

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

function videoRatioAsset(Workspace $workspace, int $width, int $height): Media
{
    return Media::factory()->video()->temporaryUpload($workspace)->create([
        'size' => 5_000_000,
        'meta' => ['width' => $width, 'height' => $height, 'duration' => 20],
    ]);
}

function videoRatioDraft(Workspace $workspace, User $user, SocialAccount $account, Media $video, ContentType $contentType): Post
{
    return Post::factory()->forAccount($account, $contentType)->create([
        'user_id' => $user->id,
        'status' => Status::Draft,
        'scheduled_at' => null,
        'content' => 'Video caption',
        'media' => [MediaItem::fromMedia($video)->toArray()],
        'meta' => $contentType === ContentType::YouTubeShort ? ['title' => 'Video title'] : [],
    ]);
}

test('a video ratio the network accepts now schedules on web, api and mcp', function (string $accountState, ContentType $contentType, int $width, int $height) {
    $account = SocialAccount::factory()->{$accountState}()->create(['workspace_id' => $this->workspace->id]);
    $video = videoRatioAsset($this->workspace, $width, $height);
    $scheduledAt = now()->addDay()->toIso8601String();

    $webPost = videoRatioDraft($this->workspace, $this->user, $account, $video, $contentType);

    $this->actingAs($this->user)->put(route('app.posts.update', $webPost), [
        'status' => Status::Scheduled->value,
        'scheduled_at' => $scheduledAt,
        'media' => [MediaItem::fromMedia($video)->toArray()],
        'content_type' => $contentType->value,
        'meta' => $webPost->meta,
    ])->assertSessionHasNoErrors();

    expect($webPost->fresh()->status)->toBe(Status::Scheduled);

    $this->withHeaders(['Authorization' => 'Bearer '.createApiTestToken(['workspace' => $this->workspace])['plain_token']])
        ->postJson(route('api.posts.store'), [
            'content' => 'Video caption',
            'status' => 'scheduled',
            'scheduled_at' => $scheduledAt,
            'media' => [['id' => videoRatioAsset($this->workspace, $width, $height)->id]],
            'social_account_id' => $account->id,
            'content_type' => $contentType->value,
            'meta' => $webPost->meta,
        ])->assertCreated();

    $mcpPost = videoRatioDraft($this->workspace, $this->user, $account, videoRatioAsset($this->workspace, $width, $height), $contentType);

    TryPostServer::actingAs($this->user)->tool(UpdatePostTool::class, [
        'post_id' => $mcpPost->id,
        'status' => 'scheduled',
        'scheduled_at' => $scheduledAt,
    ])->assertOk();

    expect($mcpPost->fresh()->status)->toBe(Status::Scheduled);
})->with([
    'instagram reel 16:9' => ['instagram', ContentType::InstagramReel, 1920, 1080],
    'instagram reel 1:1' => ['instagram', ContentType::InstagramReel, 1080, 1080],
    'instagram feed 9:16 video' => ['instagram', ContentType::InstagramFeed, 1080, 1920],
    'instagram feed 16:9 video' => ['instagram', ContentType::InstagramFeed, 1920, 1080],
    'instagram story 1:1 video' => ['instagram', ContentType::InstagramStory, 1080, 1080],
    'instagram story 16:9 video' => ['instagram', ContentType::InstagramStory, 1920, 1080],
    'youtube short 16:9' => ['youtube', ContentType::YouTubeShort, 1920, 1080],
    'youtube short 1:1' => ['youtube', ContentType::YouTubeShort, 1080, 1080],
]);

test('a video ratio outside the documented range is still rejected with the per-network message', function (string $accountState, ContentType $contentType, int $width, int $height, string $key, array $bound) {
    $account = SocialAccount::factory()->{$accountState}()->create(['workspace_id' => $this->workspace->id]);
    $video = videoRatioAsset($this->workspace, $width, $height);
    $message = trans("posts.form.warnings.{$key}", [
        'destination' => $contentType->destinationLabel(),
        'current' => number_format($width / $height, 2, '.', ''),
        ...$bound,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.createApiTestToken(['workspace' => $this->workspace])['plain_token']])
        ->postJson(route('api.posts.store'), [
            'content' => 'Video caption',
            'status' => 'scheduled',
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'media' => [['id' => $video->id]],
            'social_account_id' => $account->id,
            'content_type' => $contentType->value,
        ])->assertUnprocessable()->assertJsonValidationErrors(['content_type' => $message]);

    $post = videoRatioDraft($this->workspace, $this->user, $account, $video, $contentType);

    TryPostServer::actingAs($this->user)->tool(UpdatePostTool::class, [
        'post_id' => $post->id,
        'status' => 'scheduled',
        'scheduled_at' => now()->addDay()->toIso8601String(),
    ])->assertHasErrors([$message]);

    expect($post->fresh()->status)->toBe(Status::Draft);
})->with([
    'instagram reel 11:1' => ['instagram', ContentType::InstagramReel, 2200, 200, 'aspect_ratio_too_wide', ['max' => '10.00']],
    'instagram feed 11:1 video' => ['instagram', ContentType::InstagramFeed, 2200, 200, 'aspect_ratio_too_wide', ['max' => '10.00']],
    'instagram story 1:20 video' => ['instagram', ContentType::InstagramStory, 100, 2000, 'aspect_ratio_too_narrow', ['min' => '0.10']],
    'facebook reel 16:9' => ['facebook', ContentType::FacebookReel, 1920, 1080, 'aspect_ratio_too_wide', ['max' => '0.60']],
    'facebook story 1:1 video' => ['facebook', ContentType::FacebookStory, 1080, 1080, 'aspect_ratio_too_wide', ['max' => '0.60']],
]);
