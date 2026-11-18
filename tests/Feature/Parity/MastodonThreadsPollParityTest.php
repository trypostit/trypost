<?php

declare(strict_types=1);

use App\Dto\MediaItem;
use App\Enums\Post\Status;
use App\Enums\PostPlatform\ContentType;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\CreatePostsTool;
use App\Mcp\Tools\Post\CreatePostTool;
use App\Mcp\Tools\Post\PublishPostTool;
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

/**
 * Creates one draft through one surface: the web composer, the REST API or
 * the MCP tool, and returns that surface's own response.
 *
 * @param  array<string, mixed>  $meta
 */
function pollParityCreate(string $surface, SocialAccount $account, ContentType $type, array $meta): TestResponse|McpTestResponse
{
    $destination = ['social_account_id' => $account->id, 'content_type' => $type->value, 'meta' => $meta];

    return match ($surface) {
        'web' => test()->actingAs(test()->user)->postJson(route('app.posts.store'), [
            'status' => Status::Draft->value,
            'content' => 'Tabs or spaces?',
            'media' => [],
            'destinations' => [$destination],
        ]),
        'api' => test()->withHeaders(parityApi(test()->token))->postJson(route('api.posts.store'), [
            'status' => Status::Draft->value,
            'content' => 'Tabs or spaces?',
            ...$destination,
        ]),
        'mcp' => TryPostServer::actingAs(test()->user)->tool(CreatePostTool::class, [
            'content' => 'Tabs or spaces?',
            ...$destination,
        ]),
    };
}

test('a new poll stores only what its network takes on the web, api and mcp', function (string $surface, ContentType $type, array $expected) {
    $account = SocialAccount::factory()->{$type->platform()->value}()->create(['workspace_id' => test()->workspace->id]);

    $response = pollParityCreate($surface, $account, $type, ['poll' => [
        'options' => [' Yes ', '', 'No'],
        'duration_minutes' => 60,
        'multiple' => true,
        'hide_totals' => true,
    ]]);

    assertPollParityPassed($surface, $response);
    expect(Post::where('social_account_id', $account->id)->sole()->meta['poll'])->toEqual($expected);
})->with(['web', 'api', 'mcp'])->with([
    'x' => [ContentType::XPoll, ['options' => ['Yes', 'No'], 'duration_minutes' => 60]],
    'mastodon' => [ContentType::MastodonPoll, ['options' => ['Yes', 'No'], 'duration_minutes' => 60, 'multiple' => true, 'hide_totals' => true]],
    'threads' => [ContentType::ThreadsPoll, ['options' => ['Yes', 'No']]],
]);

test('a new mastodon post keeps a reply poll on the web, api and mcp', function (string $surface) {
    $account = SocialAccount::factory()->mastodon()->create(['workspace_id' => test()->workspace->id]);
    $poll = ['options' => ['Yes', 'No'], 'duration_minutes' => 60, 'hide_totals' => true];

    $response = pollParityCreate($surface, $account, ContentType::MastodonPost, ['thread_replies' => [['text' => 'Which one?', 'poll' => $poll]]]);

    assertPollParityPassed($surface, $response);
    expect(Post::where('social_account_id', $account->id)->sole()->meta['thread_replies'][0]['poll'])->toEqual($poll);
})->with(['web', 'api', 'mcp']);

test('a batch reports a poll choice too long on its own destination on the api', function () {
    $mastodon = SocialAccount::factory()->mastodon()->create(['workspace_id' => test()->workspace->id]);
    $threads = SocialAccount::factory()->threads()->create(['workspace_id' => test()->workspace->id]);

    test()->withHeaders(parityApi(test()->token))->postJson(route('api.posts.batch.store'), [
        'status' => Status::Draft->value,
        'content' => 'Tabs or spaces?',
        'destinations' => [
            ['social_account_id' => $mastodon->id, 'content_type' => ContentType::MastodonPoll->value, 'meta' => ['poll' => ['options' => ['Yes', 'No'], 'duration_minutes' => 60]]],
            ['social_account_id' => $threads->id, 'content_type' => ContentType::ThreadsPoll->value, 'meta' => ['poll' => ['options' => ['Yes', Str::repeat('a', ThreadsPoll::OPTION_MAX_LENGTH + 1)]]]],
        ],
    ])->assertUnprocessable()->assertJsonValidationErrors([
        'destinations.1.meta.poll.options.1' => __('posts.form.poll.choice_max', ['max' => ThreadsPoll::OPTION_MAX_LENGTH]),
    ])->assertJsonMissingValidationErrors('destinations.0.meta.poll.options.1');

    expect(Post::where('workspace_id', test()->workspace->id)->exists())->toBeFalse();
});

test('a batch refuses a poll choice too long on its own destination on mcp', function () {
    $mastodon = SocialAccount::factory()->mastodon()->create(['workspace_id' => test()->workspace->id]);
    $threads = SocialAccount::factory()->threads()->create(['workspace_id' => test()->workspace->id]);

    TryPostServer::actingAs(test()->user)->tool(CreatePostsTool::class, [
        'status' => Status::Draft->value,
        'content' => 'Tabs or spaces?',
        'destinations' => [
            ['social_account_id' => $mastodon->id, 'content_type' => ContentType::MastodonPoll->value, 'meta' => ['poll' => ['options' => ['Yes', 'No'], 'duration_minutes' => 60]]],
            ['social_account_id' => $threads->id, 'content_type' => ContentType::ThreadsPoll->value, 'meta' => ['poll' => ['options' => ['Yes', Str::repeat('a', ThreadsPoll::OPTION_MAX_LENGTH + 1)]]]],
        ],
    ])->assertHasErrors([__('posts.form.poll.choice_max', ['max' => ThreadsPoll::OPTION_MAX_LENGTH])]);

    expect(Post::where('workspace_id', test()->workspace->id)->exists())->toBeFalse();
});

test('a mastodon poll shorter than mastodon allows is refused on the post and on a reply on the web, api and mcp', function (string $surface, ContentType $type, string $prefix, array $meta) {
    $post = pollParityPost($type);

    $response = pollParityUpdate($surface, $post, ['meta' => $meta]);

    assertPollParityRefused($surface, $response, ["meta.{$prefix}poll.duration_minutes" => MastodonPoll::durationRangeMessage()]);
})->with(['web', 'api', 'mcp'])->with([
    'the post poll' => [ContentType::MastodonPoll, '', ['poll' => ['options' => ['Yes', 'No'], 'duration_minutes' => MastodonPoll::MIN_DURATION - 1]]],
    'a reply poll' => [ContentType::MastodonPost, 'thread_replies.0.', ['thread_replies' => [['text' => 'Which one?', 'poll' => ['options' => ['Yes', 'No'], 'duration_minutes' => MastodonPoll::MIN_DURATION - 1]]]]],
]);

test('a threads poll takes and drops a length threads does not use on the web, api and mcp', function (string $surface) {
    $post = pollParityPost(ContentType::ThreadsPoll);

    $response = pollParityUpdate($surface, $post, ['meta' => ['poll' => ['options' => ['Yes', 'No'], 'duration_minutes' => 99999]]]);

    assertPollParityPassed($surface, $response);
    expect($post->fresh()->meta['poll'])->toEqual(['options' => ['Yes', 'No']]);
})->with(['web', 'api', 'mcp']);

test('scheduling a mastodon or threads poll without a question asks for the question on the web, api and mcp', function (string $surface, ContentType $type, string $content) {
    $post = pollParityPost($type, ['poll' => ['options' => ['Yes', 'No'], 'duration_minutes' => 60]]);

    $response = pollParityUpdate($surface, $post, [
        'content' => $content,
        'status' => Status::Scheduled->value,
        'scheduled_at' => now()->addDay()->toIso8601String(),
    ]);

    assertPollParityRefused($surface, $response, ['content' => __('posts.form.poll.question_required')]);
    expect($post->fresh()->status)->toBe(Status::Draft);
})->with(['web', 'api', 'mcp'])->with([
    'mastodon' => [ContentType::MastodonPoll],
    'threads' => [ContentType::ThreadsPoll],
])->with([
    'no text' => [''],
    'editor markup without words' => ['<p></p>'],
]);

test('publishing a stored mastodon or threads poll without a question is refused on mcp', function (ContentType $type) {
    $post = pollParityPost($type, ['poll' => ['options' => ['Yes', 'No'], 'duration_minutes' => 60]]);
    $post->update(['content' => '<p></p>']);

    TryPostServer::actingAs(test()->user)
        ->tool(PublishPostTool::class, ['post_id' => $post->id])
        ->assertHasErrors([__('posts.form.poll.question_required')]);

    expect($post->fresh()->status)->toBe(Status::Draft);
})->with([
    'mastodon' => [ContentType::MastodonPoll],
    'threads' => [ContentType::ThreadsPoll],
]);

test('scheduling a mastodon poll without a length is refused on the post and on a reply on the web, api and mcp', function (string $surface, ContentType $type, string $prefix, array $meta) {
    $post = pollParityPost($type);

    $response = pollParityUpdate($surface, $post, [
        'status' => Status::Scheduled->value,
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'meta' => $meta,
    ]);

    assertPollParityRefused($surface, $response, ["meta.{$prefix}poll.duration_minutes" => __('posts.form.poll.duration_required')]);
    expect($post->fresh()->status)->toBe(Status::Draft);
})->with(['web', 'api', 'mcp'])->with([
    'the post poll' => [ContentType::MastodonPoll, '', ['poll' => ['options' => ['Yes', 'No']]]],
    'a reply poll' => [ContentType::MastodonPost, 'thread_replies.0.', ['thread_replies' => [['text' => 'Which one?', 'poll' => ['options' => ['Yes', 'No']]]]]],
]);

test('a mastodon reply poll with media is refused even as a draft on the web, api and mcp', function (string $surface) {
    $post = pollParityPost(ContentType::MastodonPost);
    $upload = Media::factory()->temporaryUpload(test()->workspace)->stored()->create();
    Http::fake();

    $response = pollParityUpdate($surface, $post, ['meta' => ['thread_replies' => [
        [
            'text' => 'Tabs or spaces?',
            'poll' => ['options' => ['Tabs', 'Spaces'], 'duration_minutes' => 60],
            'media' => [$surface === 'web' ? MediaItem::fromMedia($upload)->toArray() : ['upload_token' => $upload->upload_token]],
        ],
    ]]]);

    assertPollParityRefused($surface, $response, ['meta.thread_replies.0.media' => __('posts.errors.media_not_accepted')]);
    expect($post->fresh()->meta)->toBe([]);
})->with(['web', 'api', 'mcp']);

test('scheduling a reply poll with too many files gives it one media error on the web and api', function (string $surface) {
    $post = pollParityPost(ContentType::MastodonPost);
    $uploads = Media::factory()->count(5)->temporaryUpload(test()->workspace)->stored()->create();
    Http::fake();

    $response = pollParityUpdate($surface, $post, [
        'status' => Status::Scheduled->value,
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'meta' => ['thread_replies' => [
            [
                'text' => 'Tabs or spaces?',
                'poll' => ['options' => ['Tabs', 'Spaces'], 'duration_minutes' => 60],
                'media' => $uploads->map(fn (Media $upload): array => $surface === 'web'
                    ? MediaItem::fromMedia($upload)->toArray()
                    : ['upload_token' => $upload->upload_token])->all(),
            ],
        ]],
    ]);

    expect($response->assertUnprocessable()->json('errors'))
        ->toBe(['meta.thread_replies.0.media' => [__('posts.errors.media_not_accepted')]]);
    expect($post->fresh()->status)->toBe(Status::Draft);
})->with(['web', 'api']);

test('scheduling a mastodon poll with a choice too long reports it once on the web and api', function (string $surface) {
    $post = pollParityPost(ContentType::MastodonPoll);

    $response = pollParityUpdate($surface, $post, [
        'status' => Status::Scheduled->value,
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'meta' => ['poll' => ['options' => ['Yes', Str::repeat('a', MastodonPoll::OPTION_MAX_LENGTH + 1)], 'duration_minutes' => 60]],
    ]);

    $response->assertUnprocessable();
    expect($response->json('errors')['meta.poll.options.1'])->toBe([__('posts.form.poll.choice_max', ['max' => MastodonPoll::OPTION_MAX_LENGTH])]);
})->with(['web', 'api']);

test('a mastodon poll switch that is not true or false is refused on the web and api', function (string $surface, string $switch) {
    $post = pollParityPost(ContentType::MastodonPoll);

    $response = pollParityUpdate($surface, $post, ['meta' => ['poll' => ['options' => ['Yes', 'No'], 'duration_minutes' => 60, $switch => 'abc']]]);

    $response->assertUnprocessable()->assertJsonValidationErrors(["meta.poll.{$switch}"]);
    expect($post->fresh()->meta)->toBe([]);
})->with(['web', 'api'])->with(['multiple', 'hide_totals']);
