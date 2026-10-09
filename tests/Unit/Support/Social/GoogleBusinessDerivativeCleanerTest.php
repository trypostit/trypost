<?php

declare(strict_types=1);

use App\Models\Post;
use App\Support\Social\GoogleBusinessDerivativeCleaner;
use Illuminate\Support\Facades\Storage;

test('it deletes the managed google business jpeg for a post', function () {
    Storage::fake();
    $post = Post::factory()->make(['id' => '123e4567-e89b-12d3-a456-426614174000']);
    $path = GoogleBusinessDerivativeCleaner::pathFor($post);
    Storage::put($path, 'image');
    Storage::put('uploads/keep.jpg', 'keep');

    app(GoogleBusinessDerivativeCleaner::class)->cleanup($post);

    expect($path)->toEndWith('/123e4567-e89b-12d3-a456-426614174000.jpg');
    Storage::assertMissing($path);
    Storage::assertExists('uploads/keep.jpg');
});

test('a post merged from a legacy destination keeps the destination file name', function () {
    $post = Post::factory()->make(['id' => '123e4567-e89b-12d3-a456-426614174000']);
    $post->forceFill(['legacy_target_id' => '00000000-0000-0000-0000-000000000001']);

    expect(GoogleBusinessDerivativeCleaner::pathFor($post))->toEndWith('/00000000-0000-0000-0000-000000000001.jpg');
});

test('it no-ops when the derivative is already gone', function () {
    Storage::fake();

    app(GoogleBusinessDerivativeCleaner::class)->cleanup(Post::factory()->make(['id' => '123e4567-e89b-12d3-a456-426614174000']));

    expect(Storage::allFiles())->toBe([]);
});
