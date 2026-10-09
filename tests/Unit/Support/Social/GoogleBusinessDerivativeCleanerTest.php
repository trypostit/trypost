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

test('it no-ops when the derivative is already gone', function () {
    Storage::fake();

    app(GoogleBusinessDerivativeCleaner::class)->cleanup(Post::factory()->make(['id' => '123e4567-e89b-12d3-a456-426614174000']));

    expect(Storage::allFiles())->toBe([]);
});
