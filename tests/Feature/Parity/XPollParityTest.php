<?php

declare(strict_types=1);

use App\Dto\MediaItem;
use App\Enums\Post\Status;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\PublishPostTool;
use App\Mcp\Tools\Post\UpdatePostTool;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Support\Social\XPoll;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Server\Testing\TestResponse as McpTestResponse;

beforeEach(function () {
    ['user' => $this->user, 'workspace' => $this->workspace, 'token' => $this->token] = parityContext();
});

function xPollParityPost(ContentType $type = ContentType::XPoll, array $meta = [], array $accountMeta = []): Post
{
    $account = SocialAccount::factory()->x()->create(['workspace_id' => test()->workspace->id, 'meta' => $accountMeta]);

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
        ? $response->assertHasErrors([XPoll::optionsRequiredMessage()])
        : $response->assertUnprocessable()->assertJsonValidationErrors(['meta.poll.options' => XPoll::optionsRequiredMessage()]);
    expect($post->fresh()->status)->toBe(Status::Draft);
})->with(['web', 'api', 'mcp']);

test('switching an x poll to an x post drops the poll on the web, api and mcp', function (string $surface) {
    $post = xPollParityPost(meta: ['poll' => ['options' => ['Yes', 'No'], 'duration_minutes' => 60]]);

    $response = xPollParityUpdate($surface, $post, ['content_type' => ContentType::XPost->value, 'meta' => []]);

    $surface === 'mcp' ? $response->assertOk() : $response->assertSessionHasNoErrors();
    expect($post->fresh()->meta)->not->toHaveKey('poll');
})->with(['web', 'api', 'mcp']);

test('an x poll update keeps the parts it does not send on the web, api and mcp', function (string $surface, array $poll, array $expected) {
    $post = xPollParityPost(meta: ['poll' => ['options' => ['Yes', 'No', 'Maybe'], 'duration_minutes' => 4320]]);

    $response = xPollParityUpdate($surface, $post, ['meta' => ['poll' => $poll]]);

    $surface === 'mcp' ? $response->assertOk() : $response->assertSessionHasNoErrors();
    expect($post->fresh()->meta['poll'])->toEqual($expected);
})->with(['web', 'api', 'mcp'])->with([
    'options only' => [['options' => ['Tabs', 'Spaces', 'Both']], ['options' => ['Tabs', 'Spaces', 'Both'], 'duration_minutes' => 4320]],
    'length only' => [['duration_minutes' => 60], ['options' => ['Yes', 'No', 'Maybe'], 'duration_minutes' => 60]],
    'fewer options' => [['options' => ['Yes', 'No']], ['options' => ['Yes', 'No'], 'duration_minutes' => 4320]],
    'choices that only look alike as numbers' => [['options' => ['1', '01']], ['options' => ['1', '01'], 'duration_minutes' => 4320]],
]);

test('scheduling an x poll that sends only part of its poll keeps the rest on the web, api and mcp', function (string $surface, array $poll, array $expected) {
    $post = xPollParityPost(meta: ['poll' => ['options' => ['Yes', 'No', 'Maybe'], 'duration_minutes' => 4320]]);

    $response = xPollParityUpdate($surface, $post, [
        'status' => Status::Scheduled->value,
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'meta' => ['poll' => $poll],
    ]);

    $surface === 'mcp' ? $response->assertOk() : $response->assertSessionHasNoErrors();
    expect($post->fresh())
        ->status->toBe(Status::Scheduled)
        ->and($post->fresh()->meta['poll'])->toEqual($expected);
})->with(['web', 'api', 'mcp'])->with([
    'length only' => [['duration_minutes' => 60], ['options' => ['Yes', 'No', 'Maybe'], 'duration_minutes' => 60]],
    'options only' => [['options' => ['Tabs', 'Spaces']], ['options' => ['Tabs', 'Spaces'], 'duration_minutes' => 4320]],
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
        ? $response->assertHasErrors([__('posts.errors.media_not_accepted')])
        : expect($response->assertUnprocessable()->collect('errors')->flatten()->all())->toBe([__('posts.errors.media_not_accepted')]);
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

test('scheduling an x poll without a question asks only for the question on the web, api and mcp', function (string $surface, string $content) {
    $post = xPollParityPost(meta: ['poll' => ['options' => ['Yes', 'No'], 'duration_minutes' => 60]]);

    $response = xPollParityUpdate($surface, $post, [
        'content' => $content,
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
})->with(['web', 'api', 'mcp'])->with([
    'no text' => [''],
    'editor markup without words' => ['<p> </p>'],
]);

test('a poll on a thread reply is stored normalized on the web, api and mcp', function (string $surface) {
    $post = xPollParityPost(ContentType::XPost);

    $response = xPollParityUpdate($surface, $post, ['meta' => ['thread_replies' => [
        ['text' => 'Tabs or spaces?', 'poll' => ['options' => [' Tabs ', '', 'Spaces'], 'duration_minutes' => 60]],
    ]]]);

    $surface === 'mcp' ? $response->assertOk() : $response->assertSessionHasNoErrors();
    expect($post->fresh()->meta['thread_replies'])->toEqual([
        ['text' => 'Tabs or spaces?', 'media' => [], 'poll' => ['options' => ['Tabs', 'Spaces'], 'duration_minutes' => 60]],
    ]);
})->with(['web', 'api', 'mcp']);

test('repeated choices on a thread reply poll are refused on the web, api and mcp', function (string $surface) {
    $post = xPollParityPost(ContentType::XPost);

    $response = xPollParityUpdate($surface, $post, ['meta' => ['thread_replies' => [
        ['text' => 'Tabs or spaces?', 'poll' => ['options' => ['Yes', ' yes'], 'duration_minutes' => 60]],
    ]]]);

    $surface === 'mcp'
        ? $response->assertHasErrors([__('posts.form.x.poll.options_distinct')])
        : $response->assertUnprocessable()->assertJsonValidationErrors(['meta.thread_replies.0.poll.options' => __('posts.form.x.poll.options_distinct')]);
    expect($post->fresh()->meta)->toBe([]);
})->with(['web', 'api', 'mcp']);

test('scheduling a thread reply poll without its question or with one choice is refused on the web, api and mcp', function (string $surface, array $reply, string $field, string $message) {
    $post = xPollParityPost(ContentType::XPost);

    $response = xPollParityUpdate($surface, $post, [
        'status' => Status::Scheduled->value,
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'meta' => ['thread_replies' => [$reply]],
    ]);

    $surface === 'mcp'
        ? $response->assertHasErrors([__($message)])
        : $response->assertUnprocessable()->assertJsonValidationErrors([$field => __($message)]);
    expect($post->fresh()->status)->toBe(Status::Draft);
})->with(['web', 'api', 'mcp'])->with([
    'no question' => [['text' => '', 'poll' => ['options' => ['Yes', 'No'], 'duration_minutes' => 60]], 'meta.thread_replies.0', 'posts.form.x.poll.question_required'],
    'a question of empty markup' => [['text' => '<p></p>', 'poll' => ['options' => ['Yes', 'No'], 'duration_minutes' => 60]], 'meta.thread_replies.0', 'posts.form.x.poll.question_required'],
    'no length' => [['text' => 'Tabs or spaces?', 'poll' => ['options' => ['Yes', 'No']]], 'meta.thread_replies.0.poll.duration_minutes', 'posts.form.x.poll.duration_required'],
    'one choice' => [['text' => 'Tabs or spaces?', 'poll' => ['options' => ['Yes'], 'duration_minutes' => 60]], 'meta.thread_replies.0.poll.options', fn (): string => XPoll::optionsRequiredMessage()],
]);

test('a thread reply poll with media is refused even as a draft on the web, api and mcp', function (string $surface) {
    $post = xPollParityPost(ContentType::XPost);
    $upload = Media::factory()->temporaryUpload(test()->workspace)->stored()->create();
    Http::fake();

    $response = xPollParityUpdate($surface, $post, ['meta' => ['thread_replies' => [
        [
            'text' => 'Tabs or spaces?',
            'poll' => ['options' => ['Tabs', 'Spaces'], 'duration_minutes' => 60],
            'media' => [$surface === 'web' ? MediaItem::fromMedia($upload)->toArray() : ['upload_token' => $upload->upload_token]],
        ],
    ]]]);

    $surface === 'mcp'
        ? $response->assertHasErrors([__('posts.errors.media_not_accepted')])
        : $response->assertUnprocessable()->assertJsonValidationErrors(['meta.thread_replies.0.media' => __('posts.errors.media_not_accepted')]);
    expect($post->fresh()->meta)->toBe([]);
})->with(['web', 'api', 'mcp']);

test('a thread with polls on the post and on its replies is scheduled on the web, api and mcp', function (string $surface) {
    $post = xPollParityPost(meta: ['poll' => ['options' => ['Yes', 'No'], 'duration_minutes' => 60]]);

    $response = xPollParityUpdate($surface, $post, [
        'status' => Status::Scheduled->value,
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'meta' => ['poll' => ['options' => ['Yes', 'No'], 'duration_minutes' => 60], 'thread_replies' => [
            ['text' => 'And on Fridays?', 'poll' => ['options' => ['Tabs', 'Spaces'], 'duration_minutes' => 120]],
            ['text' => 'Thanks for voting!'],
        ]],
    ]);

    $surface === 'mcp' ? $response->assertOk() : $response->assertSessionHasNoErrors();
    expect($post->fresh())
        ->status->toBe(Status::Scheduled)
        ->and($post->fresh()->meta['thread_replies'][0]['poll'])->toEqual(['options' => ['Tabs', 'Spaces'], 'duration_minutes' => 120]);
})->with(['web', 'api', 'mcp']);

test('a thread reply poll off x is dropped, never checked, on the web, api and mcp', function (string $surface) {
    $account = SocialAccount::factory()->create(['workspace_id' => test()->workspace->id, 'platform' => Platform::Bluesky]);
    $post = Post::factory()->forAccount($account, ContentType::BlueskyPost)->create([
        'user_id' => test()->user->id,
        'content' => 'Thread',
        'status' => Status::Draft,
        'media' => [],
        'meta' => [],
    ]);
    $upload = Media::factory()->temporaryUpload(test()->workspace)->stored()->create();
    Http::fake();

    $response = xPollParityUpdate($surface, $post, ['meta' => ['thread_replies' => [[
        'text' => 'Tabs or spaces?',
        'poll' => ['options' => ['Tabs'], 'duration_minutes' => 60],
        'media' => [$surface === 'web' ? MediaItem::fromMedia($upload)->toArray() : ['upload_token' => $upload->upload_token]],
    ]]]]);

    $surface === 'mcp' ? $response->assertOk() : $response->assertSessionHasNoErrors();
    expect($post->fresh()->meta['thread_replies'][0])->not->toHaveKey('poll')
        ->and($post->fresh()->meta['thread_replies'][0]['media'])->toHaveCount(1);
})->with(['web', 'api', 'mcp']);

test('an empty poll on a thread reply is no poll on the web, api and mcp', function (string $surface) {
    $post = xPollParityPost(ContentType::XPost);
    $upload = Media::factory()->temporaryUpload(test()->workspace)->stored()->create();
    Http::fake();

    $response = xPollParityUpdate($surface, $post, ['meta' => ['thread_replies' => [[
        'text' => 'Just a photo',
        'poll' => [],
        'media' => [$surface === 'web' ? MediaItem::fromMedia($upload)->toArray() : ['upload_token' => $upload->upload_token]],
    ]]]]);

    $surface === 'mcp' ? $response->assertOk() : $response->assertSessionHasNoErrors();
    expect($post->fresh()->meta['thread_replies'][0])->not->toHaveKey('poll')
        ->and($post->fresh()->meta['thread_replies'][0]['media'])->toHaveCount(1);
})->with(['web', 'api', 'mcp']);

test('publishing a stored x poll without a question is refused before it reaches x on mcp', function () {
    $post = xPollParityPost(meta: ['poll' => ['options' => ['Yes', 'No'], 'duration_minutes' => 60]]);
    $post->update(['content' => '<p></p>']);

    TryPostServer::actingAs(test()->user)
        ->tool(PublishPostTool::class, ['post_id' => $post->id])
        ->assertHasErrors([__('posts.form.x.poll.question_required')]);

    expect($post->fresh()->status)->toBe(Status::Draft);
});

test('a poll question over 280 characters is refused on a long post account on the web, api and mcp, while the same text as a post passes', function (string $surface) {
    $poll = xPollParityPost(meta: ['poll' => ['options' => ['Yes', 'No'], 'duration_minutes' => 60]], accountMeta: ['x_subscription_type' => 'Premium']);
    $schedule = ['content' => Str::repeat('a', XPoll::QUESTION_MAX_LENGTH + 1), 'status' => Status::Scheduled->value, 'scheduled_at' => now()->addDay()->toIso8601String()];
    $message = __('posts.form.content_exceeds_platform', ['platform' => Platform::X->label(), 'limit' => XPoll::QUESTION_MAX_LENGTH, 'over' => 1]);

    $response = xPollParityUpdate($surface, $poll, $schedule);

    $surface === 'mcp'
        ? $response->assertHasErrors([$message])
        : expect($response->assertUnprocessable()->collect('errors')->flatten()->all())->toContain($message);
    expect($poll->fresh()->status)->toBe(Status::Draft);

    $post = xPollParityPost(ContentType::XPost, accountMeta: ['x_subscription_type' => 'Premium']);
    $passed = xPollParityUpdate($surface, $post, $schedule);

    $surface === 'mcp' ? $passed->assertOk() : $passed->assertSessionHasNoErrors();
    expect($post->fresh()->status)->toBe(Status::Scheduled);
})->with(['web', 'api', 'mcp']);

test('an api update that sends only the text measures it against the stored poll type', function () {
    $post = xPollParityPost(meta: ['poll' => ['options' => ['Yes', 'No'], 'duration_minutes' => 60]], accountMeta: ['x_subscription_type' => 'Premium']);

    test()->withHeaders(parityApi(test()->token))->putJson(route('api.posts.update', $post), [
        'content' => Str::repeat('a', XPoll::QUESTION_MAX_LENGTH + 1),
        'status' => Status::Scheduled->value,
        'scheduled_at' => now()->addDay()->toIso8601String(),
    ])->assertUnprocessable();

    expect($post->fresh()->status)->toBe(Status::Draft);
});

test('a thread reply poll question over 280 characters is refused on a long post account on the web, api and mcp, while the same reply without a poll passes', function (string $surface) {
    $post = xPollParityPost(ContentType::XPost, accountMeta: ['x_subscription_type' => 'Premium']);
    $question = Str::repeat('a', XPoll::QUESTION_MAX_LENGTH + 1);
    $schedule = fn (array $reply): array => ['status' => Status::Scheduled->value, 'scheduled_at' => now()->addDay()->toIso8601String(), 'meta' => ['thread_replies' => [$reply]]];
    $message = __('posts.form.thread.reply_too_long', ['limit' => XPoll::QUESTION_MAX_LENGTH, 'over' => 1]);

    $response = xPollParityUpdate($surface, $post, $schedule(['text' => $question, 'poll' => ['options' => ['Yes', 'No'], 'duration_minutes' => 60]]));

    $surface === 'mcp'
        ? $response->assertHasErrors([$message])
        : $response->assertUnprocessable()->assertJsonValidationErrors(['meta.thread_replies.0' => $message]);
    expect($post->fresh()->status)->toBe(Status::Draft);

    $passed = xPollParityUpdate($surface, $post, $schedule(['text' => $question]));

    $surface === 'mcp' ? $passed->assertOk() : $passed->assertSessionHasNoErrors();
    expect($post->fresh()->status)->toBe(Status::Scheduled);
})->with(['web', 'api', 'mcp']);
