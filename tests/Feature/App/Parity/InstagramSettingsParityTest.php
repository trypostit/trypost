<?php

declare(strict_types=1);

use App\Enums\Post\Status;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\PublishPostTool;
use App\Mcp\Tools\Post\UpdatePostTool;
use App\Models\Post;
use App\Models\SocialAccount;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Server\Testing\TestResponse as McpTestResponse;

beforeEach(function () {
    ['user' => $this->user, 'workspace' => $this->workspace, 'token' => $this->token] = parityContext();
});

function instagramSettingsParityReel(Platform $platform): Post
{
    $account = SocialAccount::factory()->create(['workspace_id' => test()->workspace->id, 'platform' => $platform]);

    return Post::factory()->forAccount($account, ContentType::InstagramReel)->create([
        'user_id' => test()->user->id,
        'content' => 'Reel',
        'status' => Status::Draft,
        'media' => [[
            'id' => 'video-1', 'type' => 'video', 'path' => 'medias/clip.mp4', 'url' => 'https://example.com/clip.mp4',
            'mime_type' => 'video/mp4', 'original_filename' => 'clip.mp4',
        ]],
        'meta' => [],
    ]);
}

/**
 * Sends the same post update through one surface: the web composer, the REST
 * API or the MCP tool.
 *
 * @param  array<string, mixed>  $changes
 */
function instagramSettingsParityUpdate(string $surface, Post $post, array $changes): TestResponse|McpTestResponse
{
    $payload = [
        'content' => $post->content,
        'content_type' => $post->content_type->value,
        'status' => Status::Draft->value,
        ...$changes,
    ];

    return match ($surface) {
        'web' => test()->actingAs(test()->user)->putJson(route('app.posts.update', $post), [...$payload, 'media' => $post->media]),
        'api' => test()->withHeaders(parityApi(test()->token))->putJson(route('api.posts.update', $post), $payload),
        'mcp' => TryPostServer::actingAs(test()->user)->tool(UpdatePostTool::class, ['post_id' => $post->id, ...$payload]),
    };
}

test('instagram collaborators, trial reels and music are stored the same on the web, api and mcp', function (string $surface, Platform $platform, array $meta, array $stored) {
    $post = instagramSettingsParityReel($platform);

    $response = instagramSettingsParityUpdate($surface, $post, ['meta' => $meta]);

    $surface === 'mcp' ? $response->assertOk() : $response->assertSessionHasNoErrors();
    expect($post->fresh()->meta)->toEqual($stored);
})->with(['web', 'api', 'mcp'])->with([
    'collaborators' => [Platform::Instagram, ['collaborators' => ['@alice', 'bob']], ['collaborators' => ['alice', 'bob']]],
    'trial reel' => [Platform::Instagram, ['trial_graduation' => 'MANUAL'], ['trial_graduation' => 'MANUAL']],
    'music through facebook' => [
        Platform::InstagramFacebook,
        ['audio' => ['id' => '1234567890', 'title' => 'Song', 'artist' => 'Band', 'audio_volume' => 80, 'video_volume' => 20, 'unknown' => 'dropped']],
        ['audio' => ['id' => '1234567890', 'title' => 'Song', 'artist' => 'Band', 'audio_volume' => 80, 'video_volume' => 20]],
    ],
    'music without a track id on a draft' => [Platform::InstagramFacebook, ['audio' => ['title' => 'Song']], ['audio' => ['title' => 'Song']]],
]);

test('scheduling refuses a trial reel with collaborators and music without facebook on the web, api and mcp', function (string $surface, Platform $platform, array $meta, string $field, string $message) {
    $post = instagramSettingsParityReel($platform);

    $response = instagramSettingsParityUpdate($surface, $post, [
        'meta' => $meta,
        'status' => Status::Scheduled->value,
        'scheduled_at' => now()->addDay()->toIso8601String(),
    ]);

    $surface === 'mcp'
        ? $response->assertHasErrors([__($message)])
        : $response->assertUnprocessable()->assertJsonValidationErrors(["meta.{$field}" => __($message)]);
    expect($post->fresh()->status)->toBe(Status::Draft);
})->with(['web', 'api', 'mcp'])->with([
    'trial reel with collaborators' => [Platform::InstagramFacebook, ['collaborators' => ['alice'], 'trial_graduation' => 'MANUAL'], 'trial_graduation', 'posts.form.instagram.trial_reel.no_collaborators'],
    'music on instagram login' => [Platform::Instagram, ['audio' => ['id' => '1234567890']], 'audio', 'posts.form.instagram.music.requires_facebook'],
]);

test('scheduling refuses music without a track id on the web, api and mcp', function (string $surface) {
    $post = instagramSettingsParityReel(Platform::InstagramFacebook);

    $response = instagramSettingsParityUpdate($surface, $post, [
        'meta' => ['audio' => ['title' => 'Song']],
        'status' => Status::Scheduled->value,
        'scheduled_at' => now()->addDay()->toIso8601String(),
    ]);

    $surface === 'mcp'
        ? $response->assertHasErrors([__('validation.required', ['attribute' => 'audio id'])])
        : $response->assertUnprocessable()->assertJsonValidationErrors(['meta.audio.id' => __('validation.required', ['attribute' => 'audio id'])]);
    expect($post->fresh()->status)->toBe(Status::Draft);
})->with(['web', 'api', 'mcp']);

test('publishing a stored reel with music without a track id is refused on mcp', function () {
    $post = instagramSettingsParityReel(Platform::InstagramFacebook);
    $post->forceFill(['meta' => ['audio' => ['title' => 'Song']]])->save();

    TryPostServer::actingAs($this->user)
        ->tool(PublishPostTool::class, ['post_id' => $post->id])
        ->assertHasErrors([__('validation.required', ['attribute' => 'audio id'])]);

    expect($post->fresh()->status)->toBe(Status::Draft);
});

test('collaborators beyond three and an unknown trial strategy are refused on the web, api and mcp', function (string $surface, array $meta, string $field) {
    $post = instagramSettingsParityReel(Platform::Instagram);

    $response = instagramSettingsParityUpdate($surface, $post, ['meta' => $meta]);

    $surface === 'mcp'
        ? $response->assertHasErrors()
        : $response->assertUnprocessable()->assertJsonValidationErrors("meta.{$field}");
    expect($post->fresh()->meta)->toBe([]);
})->with(['web', 'api', 'mcp'])->with([
    'four collaborators' => [['collaborators' => ['a', 'b', 'c', 'd']], 'collaborators'],
    'unknown trial strategy' => [['trial_graduation' => 'SOMETIMES'], 'trial_graduation'],
]);

test('a content type that is not text is refused with a validation error on the web, api and mcp', function (string $surface) {
    $post = instagramSettingsParityReel(Platform::Instagram);

    $response = instagramSettingsParityUpdate($surface, $post, [
        'content_type' => 5,
        'status' => Status::Scheduled->value,
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'meta' => ['audio' => ['id' => '1234567890']],
    ]);

    $surface === 'mcp'
        ? $response->assertHasErrors(['content type'])
        : $response->assertUnprocessable()->assertJsonValidationErrors('content_type');
    expect($post->fresh()->status)->toBe(Status::Draft);
})->with(['web', 'api', 'mcp']);

test('scheduling without sending the content type checks the reel rules against the stored one on the web, api and mcp', function (string $surface) {
    $post = instagramSettingsParityReel(Platform::Instagram);
    $post->forceFill(['meta' => ['audio' => ['id' => '1234567890']]])->save();
    $payload = ['status' => Status::Scheduled->value, 'scheduled_at' => now()->addDay()->toIso8601String(), 'content' => $post->content];

    $response = match ($surface) {
        'web' => $this->actingAs($this->user)->putJson(route('app.posts.update', $post), [...$payload, 'media' => $post->media]),
        'api' => $this->withHeaders(parityApi($this->token))->putJson(route('api.posts.update', $post), $payload),
        'mcp' => TryPostServer::actingAs($this->user)->tool(UpdatePostTool::class, ['post_id' => $post->id, ...$payload]),
    };

    $surface === 'mcp'
        ? $response->assertHasErrors([__('posts.form.instagram.music.requires_facebook')])
        : $response->assertUnprocessable()->assertJsonValidationErrors(['meta.audio' => __('posts.form.instagram.music.requires_facebook')]);
    expect($post->fresh()->status)->toBe(Status::Draft);
})->with(['web', 'api', 'mcp']);
