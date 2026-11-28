<?php

declare(strict_types=1);

use App\Models\Media;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;

function postMediaAltTextOwnedItem(Post $post, array $meta, array $rowMeta = []): array
{
    $row = Media::factory()->ownedByPost($post)->create(['meta' => $rowMeta ?: null]);

    return ['id' => $row->id, 'path' => $row->path, 'url' => $row->url, 'type' => 'image', 'meta' => $meta];
}

test('post update keeps media alt_text in meta', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('member'));
    $user->update(['current_workspace_id' => $workspace->id]);

    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    $response = $this->actingAs($user)->put(route('app.posts.update', $post), [
        'status' => 'draft',
        'content' => 'hi',
        'media' => [postMediaAltTextOwnedItem($post, ['alt_text' => 'a golden retriever on a beach'])],
    ]);

    $response->assertSessionDoesntHaveErrors();
    expect($post->fresh()->media[0]['meta']['alt_text'])->toBe('a golden retriever on a beach');
});

test('post update keeps the measured media meta and adds the alt_text edit', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('member'));
    $user->update(['current_workspace_id' => $workspace->id]);

    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    $response = $this->actingAs($user)->put(route('app.posts.update', $post), [
        'status' => 'draft',
        'content' => 'hi',
        'media' => [postMediaAltTextOwnedItem(
            $post,
            ['width' => 1, 'height' => 1, 'alt_text' => 'a golden retriever on a beach'],
            ['width' => 1080, 'height' => 1350, 'duration' => 12],
        )],
    ]);

    $response->assertSessionDoesntHaveErrors();

    expect($post->fresh()->media[0]['meta'])->toEqual([
        'width' => 1080,
        'height' => 1350,
        'duration' => 12,
        'alt_text' => 'a golden retriever on a beach',
    ]);
});

test('media alt_text over 2000 chars is rejected', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('member'));
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
    $workspace->members()->attach($user->id, membershipPivot('member'));
    $user->update(['current_workspace_id' => $workspace->id]);

    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    $altText = str_repeat('a', 2000);

    $response = $this->actingAs($user)->put(route('app.posts.update', $post), [
        'status' => 'draft',
        'content' => 'hi',
        'media' => [postMediaAltTextOwnedItem($post, ['alt_text' => $altText])],
    ]);

    $response->assertSessionDoesntHaveErrors();
    expect($post->fresh()->media[0]['meta']['alt_text'])->toBe($altText);
});

test('non-string media alt_text is rejected', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('member'));
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

test('post update keeps Instagram user tags in media meta', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('member'));
    $user->update(['current_workspace_id' => $workspace->id]);
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id]);
    $tags = [['username' => 'paulo.castellano_', 'x' => 0.2, 'y' => 0.8]];

    $this->actingAs($user)->put(route('app.posts.update', $post), [
        'status' => 'draft',
        'content' => 'hi',
        'media' => [postMediaAltTextOwnedItem($post, ['user_tags' => $tags])],
    ])->assertSessionDoesntHaveErrors();

    expect($post->fresh()->media[0]['meta']['user_tags'])->toEqual($tags);
});

test('invalid Instagram user tags are rejected', function (array $tags, string $error) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('member'));
    $user->update(['current_workspace_id' => $workspace->id]);
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id]);

    $this->actingAs($user)->put(route('app.posts.update', $post), [
        'status' => 'draft',
        'content' => 'hi',
        'media' => [[
            'id' => 'm1', 'path' => 'uploads/x.jpg', 'url' => 'https://cdn.test/x.jpg',
            'meta' => ['user_tags' => $tags],
        ]],
    ])->assertSessionHasErrors($error);
})->with([
    'username with spaces' => [[['username' => 'not valid', 'x' => 0.5, 'y' => 0.5]], 'media.0.meta'],
    'x outside the image' => [[['username' => 'trypost', 'x' => 1.5, 'y' => 0.5]], 'media.0.meta'],
    'missing y' => [[['username' => 'trypost', 'x' => 0.5]], 'media.0.meta'],
    'more than twenty tags' => [array_fill(0, 21, ['username' => 'trypost', 'x' => 0.5, 'y' => 0.5]), 'media.0.meta'],
]);
