<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;

test('post update keeps media alt_text in meta', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, ['role' => Role::Member->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    $response = $this->actingAs($user)->put(route('app.posts.update', $post), [
        'status' => 'draft',
        'content' => 'hi',
        'media' => [[
            'id' => 'm1', 'path' => 'uploads/x.jpg', 'url' => 'https://cdn.test/x.jpg',
            'meta' => ['alt_text' => 'a golden retriever on a beach'],
        ]],
    ]);

    $response->assertSessionDoesntHaveErrors();
    expect($post->fresh()->media[0]['meta']['alt_text'])->toBe('a golden retriever on a beach');
});

test('post update preserves every media meta key, not just alt_text', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, ['role' => Role::Member->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    $response = $this->actingAs($user)->put(route('app.posts.update', $post), [
        'status' => 'draft',
        'content' => 'hi',
        'media' => [[
            'id' => 'm1', 'path' => 'uploads/x.jpg', 'url' => 'https://cdn.test/x.jpg', 'type' => 'image',
            'meta' => [
                'width' => 1080,
                'height' => 1350,
                'duration' => 12,
                'slide_title' => 'Intro slide',
                'alt_text' => 'a golden retriever on a beach',
            ],
        ]],
    ]);

    $response->assertSessionDoesntHaveErrors();

    $meta = $post->fresh()->media[0]['meta'];

    expect($meta['width'])->toBe(1080)
        ->and($meta['height'])->toBe(1350)
        ->and($meta['duration'])->toBe(12)
        ->and($meta['slide_title'])->toBe('Intro slide')
        ->and($meta['alt_text'])->toBe('a golden retriever on a beach');
});

test('media alt_text over 2000 chars is rejected', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, ['role' => Role::Member->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    $response = $this->actingAs($user)->put(route('app.posts.update', $post), [
        'status' => 'draft',
        'content' => 'hi',
        'media' => [[
            'id' => 'm1', 'path' => 'uploads/x.jpg', 'url' => 'https://cdn.test/x.jpg',
            'meta' => ['alt_text' => str_repeat('a', 2001)],
        ]],
    ]);

    $response->assertSessionHasErrors('media.0.meta');
});

test('media alt_text at exactly 2000 chars is accepted', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, ['role' => Role::Member->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    $altText = str_repeat('a', 2000);

    $response = $this->actingAs($user)->put(route('app.posts.update', $post), [
        'status' => 'draft',
        'content' => 'hi',
        'media' => [[
            'id' => 'm1', 'path' => 'uploads/x.jpg', 'url' => 'https://cdn.test/x.jpg',
            'meta' => ['alt_text' => $altText],
        ]],
    ]);

    $response->assertSessionDoesntHaveErrors();
    expect($post->fresh()->media[0]['meta']['alt_text'])->toBe($altText);
});

test('non-string media alt_text is rejected', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, ['role' => Role::Member->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    $response = $this->actingAs($user)->put(route('app.posts.update', $post), [
        'status' => 'draft',
        'content' => 'hi',
        'media' => [[
            'id' => 'm1', 'path' => 'uploads/x.jpg', 'url' => 'https://cdn.test/x.jpg',
            'meta' => ['alt_text' => ['not', 'a', 'string']],
        ]],
    ]);

    $response->assertSessionHasErrors('media.0.meta');
});
