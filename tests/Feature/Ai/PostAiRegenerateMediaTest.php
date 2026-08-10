<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Enums\UserWorkspace\Role;
use App\Jobs\Ai\RegeneratePostMediaImage;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    config(['trypost.self_hosted' => true]);

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->user->account_id,
    ]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Admin->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $this->socialAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
    ]);

    $this->post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'media' => [[
            'id' => 'media-ai-1',
            'path' => 'ai-images/old.webp',
            'url' => 'https://example.com/old.webp',
            'type' => 'image',
            'mime_type' => 'image/webp',
            'source' => 'ai',
            'source_meta' => [
                'title' => 'Old image headline',
                'body' => 'Old image body',
                'keywords' => ['marketing', 'automation'],
                'width' => 1080,
                'height' => 1350,
            ],
        ]],
    ]);

    PostPlatform::factory()->instagram()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->socialAccount->id,
        'enabled' => true,
    ]);
});

test('regenerate media dispatches async job using the client-supplied regeneration id', function () {
    Bus::fake();

    $regenerationId = Str::uuid()->toString();

    $response = $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.regenerate-media', [
            'post' => $this->post->id,
            'mediaId' => 'media-ai-1',
        ]), [
            'instruction' => 'Replace ECP with ICP and keep the same visual style.',
            'regeneration_id' => $regenerationId,
        ])
        ->assertStatus(Response::HTTP_ACCEPTED);

    expect($response->json('regeneration_id'))->toBe($regenerationId);
    expect($response->json('channel'))->toBe("user.{$this->user->id}.ai-media.{$regenerationId}");

    Bus::assertDispatched(RegeneratePostMediaImage::class, function (RegeneratePostMediaImage $job) use ($regenerationId) {
        return $job->workspaceId === $this->workspace->id
            && $job->postId === $this->post->id
            && $job->mediaId === 'media-ai-1'
            && $job->instruction === 'Replace ECP with ICP and keep the same visual style.'
            && $job->regenerationId === $regenerationId;
    });
});

test('regenerate media requires a valid regeneration_id', function (mixed $regenerationId) {
    Bus::fake();

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.regenerate-media', [
            'post' => $this->post->id,
            'mediaId' => 'media-ai-1',
        ]), [
            'instruction' => 'Fix typo',
            'regeneration_id' => $regenerationId,
        ])
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonValidationErrors(['regeneration_id']);

    Bus::assertNotDispatched(RegeneratePostMediaImage::class);
})->with([
    'missing' => [null],
    'not a uuid' => ['not-a-uuid'],
]);

test('regenerate media rejects non ai media items', function () {
    Bus::fake();

    $this->post->update([
        'media' => [[
            'id' => 'media-static-1',
            'path' => 'uploads/static.png',
            'url' => 'https://example.com/static.png',
            'type' => 'image',
            'mime_type' => 'image/png',
            'source' => 'unsplash',
        ]],
    ]);

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.regenerate-media', [
            'post' => $this->post->id,
            'mediaId' => 'media-static-1',
        ]), [
            'instruction' => 'Change the title text',
            'regeneration_id' => Str::uuid()->toString(),
        ])
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonStructure(['errors' => ['instruction']]);

    Bus::assertNotDispatched(RegeneratePostMediaImage::class);
});

test('regenerate media returns not found when media id is missing from post', function () {
    Bus::fake();

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.regenerate-media', [
            'post' => $this->post->id,
            'mediaId' => 'missing-media-id',
        ]), [
            'instruction' => 'Fix typo',
            'regeneration_id' => Str::uuid()->toString(),
        ])
        ->assertStatus(Response::HTTP_NOT_FOUND);

    Bus::assertNotDispatched(RegeneratePostMediaImage::class);
});

test('regenerate media rejects finalized posts', function () {
    Bus::fake();

    $this->post->update(['status' => PostStatus::Published->value]);

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.regenerate-media', [
            'post' => $this->post->id,
            'mediaId' => 'media-ai-1',
        ]), [
            'instruction' => 'Fix typo',
            'regeneration_id' => Str::uuid()->toString(),
        ])
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonStructure(['errors' => ['instruction']]);

    Bus::assertNotDispatched(RegeneratePostMediaImage::class);
});

test('regenerate media validates instruction is required', function () {
    Bus::fake();

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.regenerate-media', [
            'post' => $this->post->id,
            'mediaId' => 'media-ai-1',
        ]), [
            'instruction' => '   ',
            'regeneration_id' => Str::uuid()->toString(),
        ])
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);

    Bus::assertNotDispatched(RegeneratePostMediaImage::class);
});

test('regenerate media denies access when post is from another workspace', function () {
    Bus::fake();

    $otherUser = User::factory()->create();
    $otherWorkspace = Workspace::factory()->create([
        'user_id' => $otherUser->id,
        'account_id' => $otherUser->account_id,
    ]);

    $otherPost = Post::factory()->create([
        'workspace_id' => $otherWorkspace->id,
        'user_id' => $otherUser->id,
        'media' => [[
            'id' => 'other-ai-media',
            'path' => 'ai-images/other.webp',
            'url' => 'https://example.com/other.webp',
            'type' => 'image',
            'mime_type' => 'image/webp',
            'source' => 'ai',
        ]],
    ]);

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.regenerate-media', [
            'post' => $otherPost->id,
            'mediaId' => 'other-ai-media',
        ]), [
            'instruction' => 'Fix typo',
            'regeneration_id' => Str::uuid()->toString(),
        ])
        ->assertNotFound();

    Bus::assertNotDispatched(RegeneratePostMediaImage::class);
});
