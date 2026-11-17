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
use App\Support\Social\MastodonPoll;
use App\Support\Social\Poll;
use App\Support\Social\ThreadsPoll;
use App\Support\Social\XPoll;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Server\Testing\TestResponse as McpTestResponse;

beforeEach(function () {
    ['user' => $this->user, 'workspace' => $this->workspace, 'token' => $this->token] = parityContext();
});

function networkPollParityPost(string $network, ContentType $type, array $meta = []): Post
{
    $account = SocialAccount::factory()->{$network}()->create(['workspace_id' => test()->workspace->id]);

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
function networkPollParityUpdate(string $surface, Post $post, array $changes): TestResponse|McpTestResponse
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

function assertPollParityPassed(string $surface, TestResponse|McpTestResponse $response): void
{
    $surface === 'mcp' ? $response->assertOk() : $response->assertSessionHasNoErrors();
}

/**
 * @param  array<string, string>  $errors
 */
function assertPollParityRefused(string $surface, TestResponse|McpTestResponse $response, array $errors): void
{
    $surface === 'mcp'
        ? $response->assertHasErrors(array_values($errors))
        : $response->assertUnprocessable()->assertJsonValidationErrors($errors);
}

test('a mastodon poll keeps its length, multiple choice and hidden totals on the web, api and mcp', function (string $surface) {
    $post = networkPollParityPost('mastodon', ContentType::MastodonPoll);

    $response = networkPollParityUpdate($surface, $post, ['meta' => ['poll' => [
        'options' => [' Yes ', '', 'No'],
        'duration_minutes' => MastodonPoll::MAX_DURATION,
        'multiple' => true,
        'hide_totals' => true,
    ]]]);

    assertPollParityPassed($surface, $response);
    expect($post->fresh()->meta['poll'])->toEqual(['options' => ['Yes', 'No'], 'duration_minutes' => MastodonPoll::MAX_DURATION, 'multiple' => true, 'hide_totals' => true]);
})->with(['web', 'api', 'mcp']);

test('a threads poll keeps only its choices on the web, api and mcp', function (string $surface) {
    $post = networkPollParityPost('threads', ContentType::ThreadsPoll);

    $response = networkPollParityUpdate($surface, $post, ['meta' => ['poll' => [
        'options' => ['Yes', 'No'],
        'duration_minutes' => 60,
        'multiple' => true,
        'hide_totals' => true,
    ]]]);

    assertPollParityPassed($surface, $response);
    expect($post->fresh()->meta['poll'])->toEqual(['options' => ['Yes', 'No']]);
})->with(['web', 'api', 'mcp']);

test('an x poll drops the switches only mastodon has on the web, api and mcp', function (string $surface) {
    $post = networkPollParityPost('x', ContentType::XPoll);

    $response = networkPollParityUpdate($surface, $post, ['meta' => ['poll' => ['options' => ['Yes', 'No'], 'duration_minutes' => 60, 'multiple' => true, 'hide_totals' => true]]]);

    assertPollParityPassed($surface, $response);
    expect($post->fresh()->meta['poll'])->toEqual(['options' => ['Yes', 'No'], 'duration_minutes' => 60]);
})->with(['web', 'api', 'mcp']);

test('each network takes the choice length it allows on the web, api and mcp', function (string $surface, string $network, ContentType $type, int $maxLength) {
    $post = networkPollParityPost($network, $type);
    $message = __('posts.form.poll.choice_max', ['max' => $maxLength]);

    $refused = networkPollParityUpdate($surface, $post, ['meta' => ['poll' => ['options' => ['Yes', Str::repeat('a', $maxLength + 1)], 'duration_minutes' => 60]]]);

    assertPollParityRefused($surface, $refused, ['meta.poll.options.1' => $message]);

    $accepted = networkPollParityUpdate($surface, $post, ['meta' => ['poll' => ['options' => ['Yes', Str::repeat('a', $maxLength)], 'duration_minutes' => 60]]]);

    assertPollParityPassed($surface, $accepted);
    expect($post->fresh()->meta['poll']['options'])->toBe(['Yes', Str::repeat('a', $maxLength)]);
})->with(['web', 'api', 'mcp'])->with([
    'x' => ['x', ContentType::XPoll, XPoll::OPTION_MAX_LENGTH],
    'mastodon' => ['mastodon', ContentType::MastodonPoll, MastodonPoll::OPTION_MAX_LENGTH],
    'threads' => ['threads', ContentType::ThreadsPoll, ThreadsPoll::OPTION_MAX_LENGTH],
]);

test('each network takes the poll length it allows on the web, api and mcp', function (string $surface, string $network, ContentType $type, string $poll) {
    $post = networkPollParityPost($network, $type);

    $refused = networkPollParityUpdate($surface, $post, ['meta' => ['poll' => ['options' => ['Yes', 'No'], 'duration_minutes' => $poll::MAX_DURATION + 1]]]);

    assertPollParityRefused($surface, $refused, ['meta.poll.duration_minutes' => $poll::durationRangeMessage()]);

    $accepted = networkPollParityUpdate($surface, $post, ['meta' => ['poll' => ['options' => ['Yes', 'No'], 'duration_minutes' => $poll::MAX_DURATION]]]);

    assertPollParityPassed($surface, $accepted);
    expect($post->fresh()->meta['poll']['duration_minutes'])->toBe($poll::MAX_DURATION);
})->with(['web', 'api', 'mcp'])->with([
    'x' => ['x', ContentType::XPoll, XPoll::class],
    'mastodon' => ['mastodon', ContentType::MastodonPoll, MastodonPoll::class],
]);

test('scheduling a poll needs at least two choices on the web, api and mcp', function (string $surface, string $network, ContentType $type) {
    $post = networkPollParityPost($network, $type, ['poll' => ['options' => ['Yes']]]);

    $response = networkPollParityUpdate($surface, $post, [
        'status' => Status::Scheduled->value,
        'scheduled_at' => now()->addDay()->toIso8601String(),
    ]);

    assertPollParityRefused($surface, $response, ['meta.poll.options' => Poll::optionsRequiredMessage()]);
    expect($post->fresh()->status)->toBe(Status::Draft);
})->with(['web', 'api', 'mcp'])->with([
    'mastodon' => ['mastodon', ContentType::MastodonPoll],
    'threads' => ['threads', ContentType::ThreadsPoll],
]);

test('a threads poll schedules without a length on the web, api and mcp', function (string $surface) {
    $post = networkPollParityPost('threads', ContentType::ThreadsPoll, ['poll' => ['options' => ['Yes', 'No']]]);

    $response = networkPollParityUpdate($surface, $post, [
        'status' => Status::Scheduled->value,
        'scheduled_at' => now()->addDay()->toIso8601String(),
    ]);

    assertPollParityPassed($surface, $response);
    expect($post->fresh()->status)->toBe(Status::Scheduled);
})->with(['web', 'api', 'mcp']);

test('a mastodon reply can be a poll under mastodon rules on the web, api and mcp', function (string $surface) {
    $post = networkPollParityPost('mastodon', ContentType::MastodonPost);
    $poll = ['options' => ['Yes', Str::repeat('a', MastodonPoll::OPTION_MAX_LENGTH)], 'duration_minutes' => MastodonPoll::MAX_DURATION, 'multiple' => true];

    $response = networkPollParityUpdate($surface, $post, ['meta' => ['thread_replies' => [['text' => 'Which one?', 'poll' => $poll]]]]);

    assertPollParityPassed($surface, $response);
    expect($post->fresh()->meta['thread_replies'][0]['poll'])->toEqual($poll);
})->with(['web', 'api', 'mcp']);

test('a bluesky reply drops a poll on the web, api and mcp', function (string $surface) {
    $post = networkPollParityPost('bluesky', ContentType::BlueskyPost);

    $response = networkPollParityUpdate($surface, $post, ['meta' => ['thread_replies' => [['text' => 'Which one?', 'poll' => ['options' => ['Yes', 'No']]]]]]);

    assertPollParityPassed($surface, $response);
    expect($post->fresh()->meta['thread_replies'][0])->not->toHaveKey('poll');
})->with(['web', 'api', 'mcp']);

test('a mastodon or threads poll refuses media even as a draft on the web, api and mcp', function (string $surface, string $network, ContentType $type) {
    $post = networkPollParityPost($network, $type, ['poll' => ['options' => ['Yes', 'No']]]);
    $upload = Media::factory()->temporaryUpload(test()->workspace)->stored()->create();
    Http::fake();

    $response = networkPollParityUpdate($surface, $post, [
        'media' => [$surface === 'web' ? MediaItem::fromMedia($upload)->toArray() : ['upload_token' => $upload->upload_token]],
    ]);

    assertPollParityRefused($surface, $response, ['media' => __('posts.errors.media_not_accepted')]);
    expect($post->fresh()->media)->toBeEmpty();
})->with(['web', 'api', 'mcp'])->with([
    'mastodon' => ['mastodon', ContentType::MastodonPoll],
    'threads' => ['threads', ContentType::ThreadsPoll],
]);

test('a mastodon reply poll beyond mastodon limits is refused even as a draft on the web, api and mcp', function (string $surface, array $poll, string $field) {
    $post = networkPollParityPost('mastodon', ContentType::MastodonPost);
    $messages = [
        'meta.thread_replies.0.poll.options.1' => __('posts.form.poll.choice_max', ['max' => MastodonPoll::OPTION_MAX_LENGTH]),
        'meta.thread_replies.0.poll.duration_minutes' => MastodonPoll::durationRangeMessage(),
    ];

    $response = networkPollParityUpdate($surface, $post, ['meta' => ['thread_replies' => [['text' => 'Which one?', 'poll' => $poll]]]]);

    assertPollParityRefused($surface, $response, [$field => $messages[$field]]);
    expect($post->fresh()->meta)->not->toHaveKey('thread_replies');
})->with(['web', 'api', 'mcp'])->with([
    'a choice too long' => [['options' => ['Yes', Str::repeat('a', MastodonPoll::OPTION_MAX_LENGTH + 1)], 'duration_minutes' => 60], 'meta.thread_replies.0.poll.options.1'],
    'a length too long' => [['options' => ['Yes', 'No'], 'duration_minutes' => MastodonPoll::MAX_DURATION + 1], 'meta.thread_replies.0.poll.duration_minutes'],
]);

test('a choice too long is reported on the field it was sent in, blank choices before it included, on the web, api and mcp', function (string $surface, string $prefix, array $meta) {
    $post = networkPollParityPost('mastodon', ContentType::MastodonPoll, ['poll' => ['options' => ['Yes', 'No'], 'duration_minutes' => 60]]);
    $message = __('posts.form.poll.choice_max', ['max' => MastodonPoll::OPTION_MAX_LENGTH]);

    $response = networkPollParityUpdate($surface, $post, ['meta' => $meta]);

    assertPollParityRefused($surface, $response, ["meta.{$prefix}poll.options.2" => $message]);
})->with(['web', 'api', 'mcp'])->with([
    'the post poll' => ['', ['poll' => ['options' => ['Yes', '', Str::repeat('a', MastodonPoll::OPTION_MAX_LENGTH + 1)]]]],
    'a reply poll' => ['thread_replies.0.', ['thread_replies' => [['text' => 'Which one?', 'poll' => ['options' => ['Yes', '', Str::repeat('a', MastodonPoll::OPTION_MAX_LENGTH + 1)], 'duration_minutes' => 60]]]]],
]);
