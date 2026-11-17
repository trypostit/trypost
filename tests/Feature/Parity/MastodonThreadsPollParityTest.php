<?php

declare(strict_types=1);

use App\Dto\MediaItem;
use App\Enums\Post\Status;
use App\Enums\PostPlatform\ContentType;
use App\Models\Media;
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
    $post = pollParityPost(ContentType::MastodonPoll);

    $response = pollParityUpdate($surface, $post, ['meta' => ['poll' => [
        'options' => [' Yes ', '', 'No'],
        'duration_minutes' => MastodonPoll::MAX_DURATION,
        'multiple' => true,
        'hide_totals' => true,
    ]]]);

    assertPollParityPassed($surface, $response);
    expect($post->fresh()->meta['poll'])->toEqual(['options' => ['Yes', 'No'], 'duration_minutes' => MastodonPoll::MAX_DURATION, 'multiple' => true, 'hide_totals' => true]);
})->with(['web', 'api', 'mcp']);

test('a threads poll keeps only its choices on the web, api and mcp', function (string $surface) {
    $post = pollParityPost(ContentType::ThreadsPoll);

    $response = pollParityUpdate($surface, $post, ['meta' => ['poll' => [
        'options' => ['Yes', 'No'],
        'duration_minutes' => 60,
        'multiple' => true,
        'hide_totals' => true,
    ]]]);

    assertPollParityPassed($surface, $response);
    expect($post->fresh()->meta['poll'])->toEqual(['options' => ['Yes', 'No']]);
})->with(['web', 'api', 'mcp']);

test('an x poll drops the switches only mastodon has on the web, api and mcp', function (string $surface) {
    $post = pollParityPost(ContentType::XPoll);

    $response = pollParityUpdate($surface, $post, ['meta' => ['poll' => ['options' => ['Yes', 'No'], 'duration_minutes' => 60, 'multiple' => true, 'hide_totals' => true]]]);

    assertPollParityPassed($surface, $response);
    expect($post->fresh()->meta['poll'])->toEqual(['options' => ['Yes', 'No'], 'duration_minutes' => 60]);
})->with(['web', 'api', 'mcp']);

test('each network takes the choice length it allows on the web, api and mcp', function (string $surface, ContentType $type, int $maxLength) {
    $post = pollParityPost($type);
    $message = __('posts.form.poll.choice_max', ['max' => $maxLength]);

    $refused = pollParityUpdate($surface, $post, ['meta' => ['poll' => ['options' => ['Yes', Str::repeat('a', $maxLength + 1)], 'duration_minutes' => 60]]]);

    assertPollParityRefused($surface, $refused, ['meta.poll.options.1' => $message]);

    $accepted = pollParityUpdate($surface, $post, ['meta' => ['poll' => ['options' => ['Yes', Str::repeat('a', $maxLength)], 'duration_minutes' => 60]]]);

    assertPollParityPassed($surface, $accepted);
    expect($post->fresh()->meta['poll']['options'])->toBe(['Yes', Str::repeat('a', $maxLength)]);
})->with(['web', 'api', 'mcp'])->with([
    'x' => [ContentType::XPoll, XPoll::OPTION_MAX_LENGTH],
    'mastodon' => [ContentType::MastodonPoll, MastodonPoll::OPTION_MAX_LENGTH],
    'threads' => [ContentType::ThreadsPoll, ThreadsPoll::OPTION_MAX_LENGTH],
]);

test('each network takes the poll length it allows on the web, api and mcp', function (string $surface, ContentType $type, string $poll) {
    $post = pollParityPost($type);

    $refused = pollParityUpdate($surface, $post, ['meta' => ['poll' => ['options' => ['Yes', 'No'], 'duration_minutes' => $poll::MAX_DURATION + 1]]]);

    assertPollParityRefused($surface, $refused, ['meta.poll.duration_minutes' => $poll::durationRangeMessage()]);

    $accepted = pollParityUpdate($surface, $post, ['meta' => ['poll' => ['options' => ['Yes', 'No'], 'duration_minutes' => $poll::MAX_DURATION]]]);

    assertPollParityPassed($surface, $accepted);
    expect($post->fresh()->meta['poll']['duration_minutes'])->toBe($poll::MAX_DURATION);
})->with(['web', 'api', 'mcp'])->with([
    'x' => [ContentType::XPoll, XPoll::class],
    'mastodon' => [ContentType::MastodonPoll, MastodonPoll::class],
]);

test('scheduling a poll needs at least two choices on the web, api and mcp', function (string $surface, ContentType $type) {
    $post = pollParityPost($type, ['poll' => ['options' => ['Yes']]]);

    $response = pollParityUpdate($surface, $post, [
        'status' => Status::Scheduled->value,
        'scheduled_at' => now()->addDay()->toIso8601String(),
    ]);

    assertPollParityRefused($surface, $response, ['meta.poll.options' => Poll::optionsRequiredMessage()]);
    expect($post->fresh()->status)->toBe(Status::Draft);
})->with(['web', 'api', 'mcp'])->with([
    'mastodon' => [ContentType::MastodonPoll],
    'threads' => [ContentType::ThreadsPoll],
]);

test('a threads poll schedules without a length on the web, api and mcp', function (string $surface) {
    $post = pollParityPost(ContentType::ThreadsPoll, ['poll' => ['options' => ['Yes', 'No']]]);

    $response = pollParityUpdate($surface, $post, [
        'status' => Status::Scheduled->value,
        'scheduled_at' => now()->addDay()->toIso8601String(),
    ]);

    assertPollParityPassed($surface, $response);
    expect($post->fresh()->status)->toBe(Status::Scheduled);
})->with(['web', 'api', 'mcp']);

test('a mastodon reply can be a poll under mastodon rules on the web, api and mcp', function (string $surface) {
    $post = pollParityPost(ContentType::MastodonPost);
    $poll = ['options' => ['Yes', Str::repeat('a', MastodonPoll::OPTION_MAX_LENGTH)], 'duration_minutes' => MastodonPoll::MAX_DURATION, 'multiple' => true];

    $response = pollParityUpdate($surface, $post, ['meta' => ['thread_replies' => [['text' => 'Which one?', 'poll' => $poll]]]]);

    assertPollParityPassed($surface, $response);
    expect($post->fresh()->meta['thread_replies'][0]['poll'])->toEqual($poll);
})->with(['web', 'api', 'mcp']);

test('a bluesky reply drops a poll on the web, api and mcp', function (string $surface) {
    $post = pollParityPost(ContentType::BlueskyPost);

    $response = pollParityUpdate($surface, $post, ['meta' => ['thread_replies' => [['text' => 'Which one?', 'poll' => ['options' => ['Yes', 'No']]]]]]);

    assertPollParityPassed($surface, $response);
    expect($post->fresh()->meta['thread_replies'][0])->not->toHaveKey('poll');
})->with(['web', 'api', 'mcp']);

test('a mastodon or threads poll refuses media even as a draft on the web, api and mcp', function (string $surface, ContentType $type) {
    $post = pollParityPost($type, ['poll' => ['options' => ['Yes', 'No']]]);
    $upload = Media::factory()->temporaryUpload(test()->workspace)->stored()->create();
    Http::fake();

    $response = pollParityUpdate($surface, $post, [
        'media' => [$surface === 'web' ? MediaItem::fromMedia($upload)->toArray() : ['upload_token' => $upload->upload_token]],
    ]);

    assertPollParityRefused($surface, $response, ['media' => __('posts.errors.media_not_accepted')]);
    expect($post->fresh()->media)->toBeEmpty();
})->with(['web', 'api', 'mcp'])->with([
    'mastodon' => [ContentType::MastodonPoll],
    'threads' => [ContentType::ThreadsPoll],
]);

test('a mastodon reply poll beyond mastodon limits is refused even as a draft on the web, api and mcp', function (string $surface, array $poll, string $field) {
    $post = pollParityPost(ContentType::MastodonPost);
    $messages = [
        'meta.thread_replies.0.poll.options.1' => __('posts.form.poll.choice_max', ['max' => MastodonPoll::OPTION_MAX_LENGTH]),
        'meta.thread_replies.0.poll.duration_minutes' => MastodonPoll::durationRangeMessage(),
    ];

    $response = pollParityUpdate($surface, $post, ['meta' => ['thread_replies' => [['text' => 'Which one?', 'poll' => $poll]]]]);

    assertPollParityRefused($surface, $response, [$field => $messages[$field]]);
    expect($post->fresh()->meta)->not->toHaveKey('thread_replies');
})->with(['web', 'api', 'mcp'])->with([
    'a choice too long' => [['options' => ['Yes', Str::repeat('a', MastodonPoll::OPTION_MAX_LENGTH + 1)], 'duration_minutes' => 60], 'meta.thread_replies.0.poll.options.1'],
    'a length too long' => [['options' => ['Yes', 'No'], 'duration_minutes' => MastodonPoll::MAX_DURATION + 1], 'meta.thread_replies.0.poll.duration_minutes'],
]);

test('a choice too long is reported on the field it was sent in, blank choices before it included, on the web, api and mcp', function (string $surface, string $prefix, array $meta) {
    $post = pollParityPost(ContentType::MastodonPoll, ['poll' => ['options' => ['Yes', 'No'], 'duration_minutes' => 60]]);
    $message = __('posts.form.poll.choice_max', ['max' => MastodonPoll::OPTION_MAX_LENGTH]);

    $response = pollParityUpdate($surface, $post, ['meta' => $meta]);

    assertPollParityRefused($surface, $response, ["meta.{$prefix}poll.options.2" => $message]);
})->with(['web', 'api', 'mcp'])->with([
    'the post poll' => ['', ['poll' => ['options' => ['Yes', '', Str::repeat('a', MastodonPoll::OPTION_MAX_LENGTH + 1)]]]],
    'a reply poll' => ['thread_replies.0.', ['thread_replies' => [['text' => 'Which one?', 'poll' => ['options' => ['Yes', '', Str::repeat('a', MastodonPoll::OPTION_MAX_LENGTH + 1)], 'duration_minutes' => 60]]]]],
]);
