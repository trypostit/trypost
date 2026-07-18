<?php

declare(strict_types=1);

use App\Jobs\Ai\RegeneratePostMediaImage;
use App\Models\Media;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Storage;

test('job fallback source context uses post content when source_meta is missing', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);

    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'content' => "Headline with typo ECP\nBody line for fallback context.",
        'media' => [],
    ]);

    $job = new RegeneratePostMediaImage(
        workspaceId: $workspace->id,
        postId: $post->id,
        userId: $user->id,
        mediaId: 'media-ai-1',
        regenerationId: '0196f5ca-bf2e-7d15-9a22-5709ab10d6c9',
        instruction: 'Fix typo from ECP to ICP.',
    );

    $method = new ReflectionMethod(RegeneratePostMediaImage::class, 'buildSourceContext');
    $method->setAccessible(true);

    $context = $method->invoke($job, [], $post, $workspace);

    expect(data_get($context, 'title'))->toBe('Headline with typo ECP');
    expect((string) data_get($context, 'body'))->toContain('Body line for fallback context.');
    expect(data_get($context, 'keywords'))->toBeArray()->not->toBeEmpty();
    expect(data_get($context, 'width'))->toBe(1080);
    expect(data_get($context, 'height'))->toBe(1350);
});

test('merge structured copy keeps text unchanged for image_only mode', function () {
    $job = new RegeneratePostMediaImage(
        workspaceId: 'workspace-id',
        postId: 'post-id',
        userId: 'user-id',
        mediaId: 'media-id',
        regenerationId: 'regen-id',
        instruction: 'change image only',
    );

    $method = new ReflectionMethod(RegeneratePostMediaImage::class, 'mergeStructuredCopy');
    $method->setAccessible(true);

    $baseContext = [
        'title' => 'Keep THIS Text',
        'body' => 'Body should remain exactly the same.',
        'keywords' => ['saas', 'dashboard'],
        'background_path' => 'ai-images/bg.webp',
        'language' => 'en',
        'width' => 1080,
        'height' => 1350,
    ];

    $structured = [
        'change_mode' => 'image_only',
        'title' => 'Model tried to rewrite',
        'body' => 'Model tried to rewrite body',
        'keywords' => ['modern office', 'team'],
    ];

    $copy = $method->invoke($job, $baseContext, $structured);

    expect(data_get($copy, 'title'))->toBe($baseContext['title'])
        ->and(data_get($copy, 'body'))->toBe($baseContext['body'])
        ->and(data_get($copy, 'keywords'))->toBe(['modern office', 'team'])
        ->and(data_get($copy, 'regenerate_image'))->toBeTrue()
        ->and(data_get($copy, 'regenerate_text'))->toBeFalse()
        ->and(data_get($copy, 'change_mode'))->toBe('image_only');
});

test('regenerating an image keeps the current saved alt text, not a pre-render snapshot', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);

    Storage::fake();
    Storage::put('ai-images/regen.webp', 'fake-image-bytes');

    $targetMedia = Media::factory()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $workspace->id,
        'collection' => 'ai-generated',
    ]);

    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'media' => [[
            'id' => $targetMedia->id,
            'type' => 'image',
            'source' => 'ai',
            'path' => 'ai-images/old.webp',
            'url' => 'https://cdn.test/old.webp',
            'meta' => ['alt_text' => 'CURRENT alt saved during regen', 'slide_title' => 'Intro'],
        ]],
    ]);

    $job = new RegeneratePostMediaImage(
        workspaceId: $workspace->id,
        postId: $post->id,
        userId: $user->id,
        mediaId: $targetMedia->id,
        regenerationId: 'regen-id',
        instruction: 'image only tweak',
    );

    // Snapshot captured before the async render carries a now-stale alt.
    $staleTarget = [
        'id' => $targetMedia->id,
        'type' => 'image',
        'source' => 'ai',
        'meta' => ['alt_text' => 'STALE alt from before regen'],
    ];

    $rendered = ['path' => 'ai-images/regen.webp', 'source_meta' => []];

    $method = new ReflectionMethod(RegeneratePostMediaImage::class, 'replaceMediaOnPost');
    $method->setAccessible(true);
    $method->invoke($job, $post, $staleTarget, $workspace, $rendered);

    expect(data_get($post->fresh()->media, '0.meta.alt_text'))->toBe('CURRENT alt saved during regen')
        ->and(data_get($post->fresh()->media, '0.meta.slide_title'))->toBe('Intro');
});

test('merge structured copy keeps keywords unchanged for text_only mode', function () {
    $job = new RegeneratePostMediaImage(
        workspaceId: 'workspace-id',
        postId: 'post-id',
        userId: 'user-id',
        mediaId: 'media-id',
        regenerationId: 'regen-id',
        instruction: 'change text only',
    );

    $method = new ReflectionMethod(RegeneratePostMediaImage::class, 'mergeStructuredCopy');
    $method->setAccessible(true);

    $baseContext = [
        'title' => 'Old title',
        'body' => 'Old body',
        'keywords' => ['saas', 'dashboard'],
        'background_path' => 'ai-images/bg.webp',
        'language' => 'en',
        'width' => 1080,
        'height' => 1350,
    ];

    $structured = [
        'change_mode' => 'text_only',
        'title' => 'New title',
        'body' => 'New body',
        'keywords' => ['ignored', 'for', 'text-only'],
    ];

    $copy = $method->invoke($job, $baseContext, $structured);

    expect(data_get($copy, 'title'))->toBe('New title')
        ->and(data_get($copy, 'body'))->toBe('New body')
        ->and(data_get($copy, 'keywords'))->toBe($baseContext['keywords'])
        ->and(data_get($copy, 'regenerate_image'))->toBeFalse()
        ->and(data_get($copy, 'regenerate_text'))->toBeTrue()
        ->and(data_get($copy, 'change_mode'))->toBe('text_only');
});
