<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAltTextGenerator;
use App\Enums\User\Locale;
use App\Models\Media;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Prompts\AgentPrompt;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->asset = Media::factory()->temporaryUpload($this->workspace)->create();
});

test('web composer suggests alt text for a workspace image', function () {
    MediaAltTextGenerator::fake(['  A dashboard showing weekly post reach.  ']);

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.alt-text'), ['media_id' => $this->asset->id])
        ->assertOk()
        ->assertJsonPath('alt_text', 'A dashboard showing weekly post reach.');

    MediaAltTextGenerator::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->attachments->count() === 1
        && $prompt->attachments->first() instanceof StoredImage);
    expect($this->asset->fresh()->meta)->toEqual($this->asset->meta);
});

test('web composer suggests alt text for an image the post already owns', function () {
    MediaAltTextGenerator::fake(['A chart.']);
    $owned = Media::factory()->ownedByPost(Post::factory()->create(['workspace_id' => $this->workspace->id]))->create();

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.alt-text'), ['media_id' => $owned->id])
        ->assertOk()
        ->assertJsonPath('alt_text', 'A chart.');
});

test('alt text generation cannot read a library row', function () {
    MediaAltTextGenerator::fake(['Should not run']);
    $library = Media::factory()->libraryAsset($this->workspace)->create();

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.alt-text'), ['media_id' => $library->id])
        ->assertNotFound();

    MediaAltTextGenerator::assertNeverPrompted();
});

test('alt text generation requires a media id', function () {
    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.alt-text'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['media_id']);
});

test('alt text generation cannot read another workspace asset', function () {
    MediaAltTextGenerator::fake(['Should not run']);
    $foreign = Media::factory()->temporaryUpload(Workspace::factory()->create())->create();

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.alt-text'), ['media_id' => $foreign->id])
        ->assertNotFound();

    MediaAltTextGenerator::assertNeverPrompted();
});

test('alt text generation is only offered for images', function () {
    MediaAltTextGenerator::fake(['Should not run']);
    $video = Media::factory()->video()->temporaryUpload($this->workspace)->create();

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.alt-text'), ['media_id' => $video->id])
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);

    MediaAltTextGenerator::assertNeverPrompted();
});

test('alt text is written in the requesting user locale', function () {
    MediaAltTextGenerator::fake(['Ein Dashboard mit der wöchentlichen Reichweite.']);
    $this->user->update(['locale' => Locale::German]);

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.alt-text'), ['media_id' => $this->asset->id])
        ->assertOk();

    MediaAltTextGenerator::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->agent->instructions(), 'German (de)'));
});

test('a failing provider returns a translated bad gateway', function () {
    MediaAltTextGenerator::fake([fn () => throw new ProviderOverloadedException('overloaded')]);

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.alt-text'), ['media_id' => $this->asset->id])
        ->assertStatus(Response::HTTP_BAD_GATEWAY)
        ->assertJsonPath('message', __('posts.composer.media_editor.alt_generate_error'));
});
