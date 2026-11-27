<?php

declare(strict_types=1);

use App\Models\Media;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id, 'account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    subscribeAccount($this->user->account);
});

test('the media file is served from the app for the media editor', function () {
    $media = Media::factory()->temporaryUpload($this->workspace)->create(['path' => 'medias/photo.png', 'mime_type' => 'image/png']);
    Storage::put('medias/photo.png', 'png-bytes');

    $response = $this->actingAs($this->user)->get(route('app.media.file', $media));

    $response->assertOk()->assertHeader('Content-Type', 'image/png');
    expect($response->streamedContent())->toBe('png-bytes');
});

test('the media file of another workspace is not found', function () {
    $other = Workspace::factory()->create();
    $media = Media::factory()->temporaryUpload($other)->create(['path' => 'medias/other.png', 'mime_type' => 'image/png']);
    Storage::put('medias/other.png', 'png-bytes');

    $this->actingAs($this->user)->get(route('app.media.file', $media))->assertNotFound();
});

test('a guest cannot read a media file', function () {
    $media = Media::factory()->temporaryUpload($this->workspace)->create(['path' => 'medias/photo.png', 'mime_type' => 'image/png']);

    $this->get(route('app.media.file', $media))->assertRedirect();
});

test('a media file already owned by a post is served too', function () {
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);
    $media = Media::factory()->ownedByPost($post)->create(['path' => 'medias/post.jpg', 'mime_type' => 'image/jpeg']);
    Storage::put('medias/post.jpg', 'jpg-bytes');

    $response = $this->actingAs($this->user)->get(route('app.media.file', $media));

    $response->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    expect($response->streamedContent())->toBe('jpg-bytes');
});

test('a media row whose file is gone is not found', function () {
    $media = Media::factory()->temporaryUpload($this->workspace)->create(['path' => 'medias/missing.png', 'mime_type' => 'image/png']);

    $this->actingAs($this->user)->get(route('app.media.file', $media))->assertNotFound();
});
