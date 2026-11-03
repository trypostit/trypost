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
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Server\Testing\TestResponse as McpTestResponse;

beforeEach(function () {
    ['user' => $this->user, 'workspace' => $this->workspace, 'token' => $this->token] = parityContext();
});

function xPollParityPost(ContentType $type = ContentType::XPoll, array $meta = []): Post
{
    $account = SocialAccount::factory()->x()->create(['workspace_id' => test()->workspace->id]);

    return Post::factory()->forAccount($account, $type)->create([
        'user_id' => test()->user->id,
        'content' => 'Tabs or spaces?',
        'status' => Status::Draft,
        'media' => [],
        'meta' => $meta,
    ]);
}

/**
 * Sends the same post update through one surface: the web composer, the REST
 * API or the MCP tool.
 *
 * @param  array<string, mixed>  $changes
 */
function xPollParityUpdate(string $surface, Post $post, array $changes): TestResponse|McpTestResponse
{
    $payload = [
        'content' => $post->content,
        'content_type' => $post->content_type->value,
        'status' => Status::Draft->value,
        ...$changes,
    ];

    return match ($surface) {
        'web' => test()->actingAs(test()->user)->putJson(route('app.posts.update', $post), ['media' => [], ...$payload]),
        'api' => test()->withHeaders(parityApi(test()->token))->putJson(route('api.posts.update', $post), $payload),
        'mcp' => TryPostServer::actingAs(test()->user)->tool(UpdatePostTool::class, ['post_id' => $post->id, ...$payload]),
    };
}

test('a valid x poll is stored normalized on the web, api and mcp', function (string $surface) {
    $post = xPollParityPost();

    $response = xPollParityUpdate($surface, $post, ['meta' => ['poll' => ['options' => [' Yes ', '', 'No'], 'duration_minutes' => 60]]]);

    $surface === 'mcp' ? $response->assertOk() : $response->assertSessionHasNoErrors();
    expect($post->fresh()->meta['poll'])->toEqual(['options' => ['Yes', 'No'], 'duration_minutes' => 60]);
})->with(['web', 'api', 'mcp']);

test('x poll choices that repeat or number five are refused on the web, api and mcp', function (string $surface, array $options) {
    $post = xPollParityPost();

    $response = xPollParityUpdate($surface, $post, ['meta' => ['poll' => ['options' => $options, 'duration_minutes' => 60]]]);

    $surface === 'mcp'
        ? $response->assertHasErrors()
        : $response->assertUnprocessable()->assertJsonValidationErrors('meta.poll.options');
    expect($post->fresh()->meta)->toBe([]);
})->with(['web', 'api', 'mcp'])->with([
    'repeated option' => [['Yes', ' yes']],
    'five options' => [['a', 'b', 'c', 'd', 'e']],
]);

test('scheduling an x poll without options is refused on the web, api and mcp', function (string $surface) {
    $post = xPollParityPost();

    $response = xPollParityUpdate($surface, $post, [
        'status' => Status::Scheduled->value,
        'scheduled_at' => now()->addDay()->toIso8601String(),
    ]);

    $surface === 'mcp'
        ? $response->assertHasErrors([__('posts.form.x.poll.options_required')])
        : $response->assertUnprocessable()->assertJsonValidationErrors(['meta.poll.options' => __('posts.form.x.poll.options_required')]);
    expect($post->fresh()->status)->toBe(Status::Draft);
})->with(['web', 'api', 'mcp']);

test('switching an x poll to an x post drops the poll on the web, api and mcp', function (string $surface) {
    $post = xPollParityPost(meta: ['poll' => ['options' => ['Yes', 'No'], 'duration_minutes' => 60]]);

    $response = xPollParityUpdate($surface, $post, ['content_type' => ContentType::XPost->value, 'meta' => []]);

    $surface === 'mcp' ? $response->assertOk() : $response->assertSessionHasNoErrors();
    expect($post->fresh()->meta)->not->toHaveKey('poll');
})->with(['web', 'api', 'mcp']);

test('an x poll update keeps the parts it does not send on the web, api and mcp', function (string $surface, array $poll, array $expected) {
    $post = xPollParityPost(meta: ['poll' => ['options' => ['Yes', 'No'], 'duration_minutes' => 4320]]);

    $response = xPollParityUpdate($surface, $post, ['meta' => ['poll' => $poll]]);

    $surface === 'mcp' ? $response->assertOk() : $response->assertSessionHasNoErrors();
    expect($post->fresh()->meta['poll'])->toEqual($expected);
})->with(['web', 'api', 'mcp'])->with([
    'options only' => [['options' => ['Tabs', 'Spaces', 'Both']], ['options' => ['Tabs', 'Spaces', 'Both'], 'duration_minutes' => 4320]],
    'length only' => [['duration_minutes' => 60], ['options' => ['Yes', 'No'], 'duration_minutes' => 60]],
    'choices that only look alike as numbers' => [['options' => ['1', '01']], ['options' => ['1', '01'], 'duration_minutes' => 4320]],
]);

test('scheduling an x poll with media is refused on the web, api and mcp', function (string $surface) {
    $post = xPollParityPost(meta: ['poll' => ['options' => ['Yes', 'No'], 'duration_minutes' => 60]]);
    $upload = Media::factory()->temporaryUpload(test()->workspace)->stored()->create();
    Http::fake();

    $response = xPollParityUpdate($surface, $post, [
        'status' => Status::Scheduled->value,
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'media' => [$surface === 'web' ? MediaItem::fromMedia($upload)->toArray() : ['upload_token' => $upload->upload_token]],
    ]);

    $surface === 'mcp'
        ? $response->assertHasErrors([__('posts.form.warnings.text_only')])
        : $response->assertUnprocessable()->assertJsonValidationErrors(['content_type' => __('posts.form.warnings.text_only')]);
    expect($post->fresh())->status->toBe(Status::Draft)->media->toBeEmpty();
})->with(['web', 'api', 'mcp']);

test('an x poll draft with media is refused on the web, api and mcp', function (string $surface) {
    $post = xPollParityPost(meta: ['poll' => ['options' => ['Yes', 'No'], 'duration_minutes' => 60]]);
    $upload = Media::factory()->temporaryUpload(test()->workspace)->stored()->create();
    Http::fake();

    $response = xPollParityUpdate($surface, $post, [
        'status' => Status::Draft->value,
        'media' => [$surface === 'web' ? MediaItem::fromMedia($upload)->toArray() : ['upload_token' => $upload->upload_token]],
    ]);

    $surface === 'mcp'
        ? $response->assertHasErrors([__('posts.errors.media_not_accepted')])
        : $response->assertUnprocessable()->assertJsonValidationErrors(['media' => __('posts.errors.media_not_accepted')]);
    expect($post->fresh()->media)->toBeEmpty();
})->with(['web', 'api', 'mcp']);

test('scheduling an x poll without a question asks only for the question on the web, api and mcp', function (string $surface) {
    $post = xPollParityPost(meta: ['poll' => ['options' => ['Yes', 'No'], 'duration_minutes' => 60]]);

    $response = xPollParityUpdate($surface, $post, [
        'content' => '',
        'status' => Status::Scheduled->value,
        'scheduled_at' => now()->addDay()->toIso8601String(),
    ]);

    if ($surface === 'mcp') {
        $response->assertHasErrors([__('posts.form.x.poll.question_required')])
            ->assertDontSee(__('posts.edit.compliance.requires_content_or_media'));
    } else {
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['content' => __('posts.form.x.poll.question_required')])
            ->assertDontSee(__('posts.edit.compliance.requires_content_or_media'));
    }
    expect($post->fresh()->status)->toBe(Status::Draft);
})->with(['web', 'api', 'mcp']);
