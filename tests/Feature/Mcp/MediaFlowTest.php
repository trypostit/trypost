<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Exceptions\Post\QueueBusyException;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Platform\ListContentTypesTool;
use App\Mcp\Tools\Post\AttachMediaFromUploadTool;
use App\Mcp\Tools\Post\AttachMediaFromUrlTool;
use App\Mcp\Tools\Post\CreatePostTool;
use App\Mcp\Tools\Post\RequestMediaUploadTool;
use App\Mcp\Tools\Post\UpdatePostTool;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Media\MediaOptimizer;
use App\Services\Social\XPublisher;
use App\Support\HeicConverter;
use App\Support\PostApproval;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    Queue::fake();
    Storage::fake(null, ['url' => 'https://cdn.example.com']);
    Cache::flush();

    $token = createApiTestToken();
    $this->user = $token['user'];
    $this->workspace = $token['workspace'];
    $this->headers = ['Authorization' => "Bearer {$token['plain_token']}"];

    $this->x = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id, 'timezone' => 'UTC', 'token_expires_at' => now()->addHours(2)]);
    $this->linkedin = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id, 'timezone' => 'UTC']);
    $this->instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id, 'timezone' => 'UTC']);
});

/**
 * The upload an agent runs: request a signed URL with the MCP tool, then post
 * the file to it as a plain multipart client would. Returns the upload token.
 */
function mediaFlowUpload(object $test, UploadedFile $file, ?User $user = null): string
{
    $issued = [];

    TryPostServer::actingAs($user ?? $test->user)
        ->tool(RequestMediaUploadTool::class, [])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) use (&$issued): void {
            $issued = $json->toArray();
            $json->etc();
        });

    $test->post(data_get($issued, 'upload_url'), [data_get($issued, 'field_name') => $file])->assertCreated();

    return (string) data_get($issued, 'upload_token');
}

function mediaFlowPng(string $name = 'photo.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, file_get_contents(base_path('tests/fixtures/1x1.png')));
}

function mediaFlowPdf(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('deck.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
}

function mediaFlowScheduled(): string
{
    return now()->addDay()->toIso8601String();
}

function mediaFlowPost(object $test, SocialAccount $account, ContentType $contentType, PostStatus $status = PostStatus::Draft): Post
{
    return Post::factory()->forAccount($account, $contentType)->create(['user_id' => $test->user->id, 'status' => $status]);
}

/**
 * @param  class-string  $tool
 * @return array<string, mixed>
 */
function mediaFlowAttachArguments(object $test, Post $post, string $tool): array
{
    if ($tool === AttachMediaFromUploadTool::class) {
        return ['post_id' => $post->id, 'upload_token' => mediaFlowUpload($test, mediaFlowPng())];
    }

    Http::fake(['example.com/*' => Http::response(file_get_contents(base_path('tests/fixtures/1x1.png')), 200)]);

    return ['post_id' => $post->id, 'urls' => [['url' => 'https://example.com/photo.png']]];
}

test('an agent uploads a file through the signed url, schedules an x post with it and x receives that file with its alt text', function () {
    $token = mediaFlowUpload($this, mediaFlowPng());

    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, [
        'content' => 'Launch day',
        'media' => [['upload_token' => $token, 'alt' => 'A single pixel']],
        'status' => 'scheduled',
        'scheduled_at' => mediaFlowScheduled(),
        'social_account_id' => $this->x->id,
    ])->assertOk();

    $post = Post::query()->sole();
    $item = data_get($post->media, '0');

    expect($post->status)->toBe(PostStatus::Scheduled)
        ->and(data_get($item, 'meta.alt_text'))->toBe('A single pixel')
        ->and(Media::query()->sole()->post_id)->toBe($post->id)
        ->and(Storage::exists(data_get($item, 'path')))->toBeTrue();

    $optimizer = Mockery::mock(MediaOptimizer::class);
    $optimizer->shouldReceive('optimizeImage')->andReturnUsing(function (string $path): string {
        $copy = tempnam(sys_get_temp_dir(), 'x_opt_');
        copy($path, $copy);

        return $copy;
    });
    app()->instance(MediaOptimizer::class, $optimizer);

    $stored = Storage::get(data_get($item, 'path'));
    Http::fake(fn (Request $request) => match (true) {
        $request->url() === data_get($item, 'url') => Http::response($stored, 200, ['Content-Type' => 'image/png']),
        str_contains($request->url(), '/media/metadata') => Http::response([], 200),
        str_contains($request->url(), '/media/upload') => Http::response(['data' => ['id' => 'uploaded-media']], 200),
        str_contains($request->url(), '/2/tweets') => Http::response(['data' => ['id' => '1900', 'text' => 'Launch day']], 201),
        default => Http::response('', 404),
    });

    (new XPublisher)->publish($post);

    Http::assertSent(fn (Request $request): bool => $request->url() === data_get($item, 'url'));
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/media/metadata')
        && data_get($request->data(), 'metadata.alt_text.text') === 'A single pixel');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/2/tweets')
        && data_get($request->data(), 'media.media_ids') === ['uploaded-media']);
});

test('a heic photo sent to the signed upload url reaches the post as a jpeg', function () {
    HeicConverter::flush();

    if (! HeicConverter::available()) {
        $this->markTestSkipped('Imagick with HEIC support is not available');
    }

    $token = mediaFlowUpload($this, UploadedFile::fake()->createWithContent('IMG_0001.HEIC', file_get_contents(base_path('tests/fixtures/sample.heic'))));

    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, [
        'content' => 'From the phone',
        'media' => [['upload_token' => $token]],
        'social_account_id' => $this->instagram->id,
    ])->assertOk();

    $item = data_get(Post::query()->sole()->media, '0');

    expect(data_get($item, 'mime_type'))->toBe('image/jpeg')
        ->and(data_get($item, 'path'))->toEndWith('.jpg')
        ->and(getimagesizefromstring(Storage::get(data_get($item, 'path')))[2])->toBe(IMAGETYPE_JPEG);
});

test('carousel files keep the order the agent gives them', function () {
    $tokens = [
        mediaFlowUpload($this, mediaFlowPng('first.png')),
        mediaFlowUpload($this, mediaFlowPng('second.png')),
        mediaFlowUpload($this, mediaFlowPng('third.png')),
    ];

    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, [
        'content' => 'Carousel',
        'media' => array_map(fn (string $token): array => ['upload_token' => $token], $tokens),
        'social_account_id' => $this->instagram->id,
        'content_type' => ContentType::InstagramFeed->value,
    ])->assertOk();

    expect(array_column(Post::query()->sole()->media, 'original_filename'))->toBe(['first.png', 'second.png', 'third.png']);
});

test('a reply file by upload token is checked against the network at save', function (string $surface) {
    $token = mediaFlowUpload($this, mediaFlowPdf());
    $payload = [
        'content' => 'Root',
        'status' => 'scheduled',
        'scheduled_at' => mediaFlowScheduled(),
        'social_account_id' => $this->x->id,
        'meta' => ['thread_replies' => [['text' => 'Reply', 'media' => [['upload_token' => $token]]]]],
    ];
    $message = __('posts.form.warnings.no_document_allowed');

    if ($surface === 'mcp') {
        TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, $payload)->assertHasErrors([$message]);
    } else {
        $errors = $this->postJson(route('api.posts.store'), $payload, $this->headers)->assertUnprocessable()->json('errors');

        expect(collect($errors)->flatten()->all())->toContain($message);
    }

    expect(Post::query()->count())->toBe(0)
        ->and(Media::query()->sole()->upload_token)->toBe($token);
})->with(['mcp', 'api']);

test('a reply file by media id is checked against the network when a post is scheduled', function () {
    $post = mediaFlowPost($this, $this->x, ContentType::XPost);
    $document = Media::factory()->ownedByPost($post)->document()->stored()->create();

    TryPostServer::actingAs($this->user)->tool(UpdatePostTool::class, [
        'post_id' => $post->id,
        'content' => 'Root',
        'status' => 'scheduled',
        'scheduled_at' => mediaFlowScheduled(),
        'meta' => ['thread_replies' => [['text' => 'Reply', 'media' => [['id' => $document->id]]]]],
    ])->assertHasErrors([__('posts.form.warnings.no_document_allowed')]);

    expect($post->fresh()->status)->toBe(PostStatus::Draft);
});

test('a reply image by upload token is accepted, owned by the post and stored whole', function () {
    $token = mediaFlowUpload($this, mediaFlowPng());

    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, [
        'content' => 'Root',
        'status' => 'scheduled',
        'scheduled_at' => mediaFlowScheduled(),
        'social_account_id' => $this->x->id,
        'meta' => ['thread_replies' => [['text' => 'Reply', 'media' => [['upload_token' => $token]]]]],
    ])->assertOk();

    $post = Post::query()->sole();
    $reply = data_get($post->meta, 'thread_replies.0.media.0');
    $row = Media::query()->sole();

    expect(data_get($reply, 'type'))->toBe('image')
        ->and(data_get($reply, 'mime_type'))->toBe('image/png')
        ->and($row->post_id)->toBe($post->id)
        ->and($row->upload_token)->toBeNull();
});

test('a member who needs approval schedules a post with an uploaded file and it waits for approval with the file', function () {
    $requester = workspaceMember($this->workspace, 'approval');
    $token = mediaFlowUpload($this, mediaFlowPng(), $requester);

    TryPostServer::actingAs($requester)->tool(CreatePostTool::class, [
        'content' => 'Needs a look',
        'media' => [['upload_token' => $token]],
        'status' => 'scheduled',
        'scheduled_at' => mediaFlowScheduled(),
        'social_account_id' => $this->linkedin->id,
    ])->assertOk();

    $post = Post::query()->sole();

    expect($post->status)->toBe(PostStatus::PendingApproval)
        ->and($post->media)->toHaveCount(1)
        ->and(Media::query()->sole()->post_id)->toBe($post->id);
});

test('the attach tools answer the busy message while the post lock is held', function (string $tool) {
    $post = mediaFlowPost($this, $this->linkedin, ContentType::LinkedInPost);
    $arguments = mediaFlowAttachArguments($this, $post, $tool);

    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')->andThrow(new LockTimeoutException);
    Cache::partialMock()->shouldReceive('lock')->with("post-approval:{$post->id}", PostApproval::LOCK_SECONDS)->andReturn($lock);

    TryPostServer::actingAs($this->user)->tool($tool, $arguments)
        ->assertHasErrors([(new QueueBusyException)->getMessage()]);

    expect($post->fresh()->media)->toBe([]);
})->with([
    'from upload' => [AttachMediaFromUploadTool::class],
    'from url' => [AttachMediaFromUrlTool::class],
]);

test('attaching to a post that is publishing or published is refused and attaches nothing', function (PostStatus $status, string $tool) {
    $post = mediaFlowPost($this, $this->linkedin, ContentType::LinkedInPost, $status);

    TryPostServer::actingAs($this->user)->tool($tool, mediaFlowAttachArguments($this, $post, $tool))->assertHasErrors();

    expect($post->fresh()->media)->toBe([])
        ->and(Media::query()->whereNotNull('post_id')->count())->toBe(0);
})->with([
    'publishing' => [PostStatus::Publishing],
    'published' => [PostStatus::Published],
])->with([
    'from upload' => [AttachMediaFromUploadTool::class],
    'from url' => [AttachMediaFromUrlTool::class],
]);

test('media null is refused and an empty list removes every file', function (string $surface) {
    $token = mediaFlowUpload($this, mediaFlowPng());
    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, [
        'content' => 'Root',
        'media' => [['upload_token' => $token]],
        'social_account_id' => $this->linkedin->id,
    ])->assertOk();
    $post = Post::query()->sole();

    if ($surface === 'mcp') {
        TryPostServer::actingAs($this->user)->tool(UpdatePostTool::class, ['post_id' => $post->id, 'media' => null])->assertHasErrors();
        expect($post->fresh()->media)->toHaveCount(1);

        TryPostServer::actingAs($this->user)->tool(UpdatePostTool::class, ['post_id' => $post->id, 'media' => []])->assertOk();
    } else {
        $this->putJson(route('api.posts.update', $post), ['media' => null], $this->headers)->assertJsonValidationErrors('media');
        expect($post->fresh()->media)->toHaveCount(1);

        $this->putJson(route('api.posts.update', $post), ['media' => []], $this->headers)->assertOk();
    }

    expect($post->fresh()->media)->toBe([])
        ->and(Media::query()->count())->toBe(0);
})->with(['mcp', 'api']);

test('a url that cannot be hosted says why, on create and on attach', function () {
    config()->set('trypost.media.max_size_mb.image', 1);
    Http::fake([
        'example.com/huge.png' => fn () => Http::response(file_get_contents(base_path('tests/fixtures/1x1.png')).str_repeat("\0", 2 * 1024 * 1024), 200),
        'example.com/page.html' => fn () => Http::response('<html><body>Hi</body></html>', 200, ['Content-Type' => 'text/html']),
        'example.com/moved.png' => fn () => Http::response('', 302, ['Location' => 'https://example.com/huge.png']),
    ]);

    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, [
        'content' => 'x',
        'media' => [['url' => 'https://example.com/huge.png']],
        'social_account_id' => $this->linkedin->id,
    ])->assertHasErrors([__('posts.composer.media_sources.errors.too_large')]);

    $this->postJson(route('api.posts.store'), [
        'content' => 'x',
        'media' => [['url' => 'https://example.com/page.html']],
        'social_account_id' => $this->linkedin->id,
    ], $this->headers)->assertJsonValidationErrors(['media.0.url' => __('posts.composer.media_sources.errors.type_not_allowed')]);

    $post = mediaFlowPost($this, $this->linkedin, ContentType::LinkedInPost);
    $urls = [['url' => 'https://example.com/huge.png'], ['url' => 'https://example.com/page.html'], ['url' => 'https://example.com/moved.png']];
    $expected = [
        ['url' => 'https://example.com/huge.png', 'reason' => 'too_large', 'message' => __('posts.composer.media_sources.errors.too_large')],
        ['url' => 'https://example.com/page.html', 'reason' => 'type_not_allowed', 'message' => __('posts.composer.media_sources.errors.type_not_allowed')],
        ['url' => 'https://example.com/moved.png', 'reason' => 'unreachable', 'message' => __('posts.errors.media_url_unreachable', ['url' => 'https://example.com/moved.png'])],
    ];

    $this->postJson(route('api.posts.attach-media-from-url', $post), ['urls' => $urls], $this->headers)
        ->assertOk()
        ->assertJsonPath('failures', $expected);

    TryPostServer::actingAs($this->user)->tool(AttachMediaFromUrlTool::class, ['post_id' => $post->id, 'urls' => $urls])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('attached_count', 0)->where('failures', $expected)->etc());

    expect(Media::query()->count())->toBe(0);
});

test('a plain multipart client gets json errors from the signed upload url and the api', function () {
    $issued = [];
    TryPostServer::actingAs($this->user)->tool(RequestMediaUploadTool::class, [])
        ->assertStructuredContent(function (AssertableJson $json) use (&$issued): void {
            $issued = $json->toArray();
            $json->etc();
        });

    $this->post(data_get($issued, 'upload_url'), ['media' => UploadedFile::fake()->create('evil.exe', 10, 'application/octet-stream')])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('media');

    $this->post(data_get($issued, 'upload_url').'0', ['media' => mediaFlowPng()])
        ->assertForbidden()
        ->assertJsonPath('message', 'Invalid signature.');

    $this->get(route('api.posts.index'))->assertUnauthorized()->assertJsonStructure(['message']);

    expect(Media::query()->count())->toBe(0);
});

test('list-content-types-tool shows every media rule a save enforces', function () {
    $rows = [];
    TryPostServer::actingAs($this->user)->tool(ListContentTypesTool::class, [])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) use (&$rows): void {
            $rows = collect($json->toArray()['platforms'])->flatMap(fn (array $platform): array => $platform['content_types'])->keyBy('value')->all();
            $json->etc();
        });

    expect($rows[ContentType::InstagramFeed->value])->toMatchArray([
        'aspect_ratio_min' => 0.75,
        'aspect_ratio_max' => 1.91,
        'auto_fits_image' => false,
        'supports_user_tags' => true,
        'supports_video_cover' => true,
        'supports_alt_text' => true,
    ])
        ->and($rows[ContentType::InstagramStory->value])->toMatchArray(['aspect_ratio_min' => null, 'aspect_ratio_max' => null, 'video_aspect_ratio_min' => 0.1, 'video_aspect_ratio_max' => 10.0, 'auto_fits_image' => true, 'supports_user_tags' => false])
        ->and($rows[ContentType::GoogleBusinessPost->value])->toMatchArray(['image_min_width' => 250, 'image_min_height' => 250])
        ->and($rows[ContentType::ThreadsPost->value])->toMatchArray(['aspect_ratio_min' => 0.1, 'video_aspect_ratio_min' => null]);

    $editorOnly = array_flip(['max_files', 'min_files', 'crop_presets', 'platform_label']);

    foreach (ContentType::cases() as $contentType) {
        $rules = array_diff_key($contentType->mediaRules(), $editorOnly);
        $renamed = ['accept_images', 'accept_videos', 'accept_documents', 'requires_media', 'accepts_gif', 'accepts_mov', 'forbids_mixed_media', 'max_image_bytes', 'max_video_bytes', 'max_document_bytes', 'max_video_duration_sec'];

        expect(array_intersect_key($rows[$contentType->value], $rules))->toEqual($rules)
            ->and(array_diff(array_keys($rules), array_keys($rows[$contentType->value]), $renamed))->toBe([]);
    }
});

test('a url that redirects to the file is followed for api and mcp imports')
    ->todo(note: 'Owner decision: MediaAttacher::hostUpload refuses redirects (MediaAttacherTest pins it), so share links that redirect (Dropbox, Drive, many CDNs) fail as unreachable for agents. SafeHttpFetcher vets every hop, as for RSS images and web imports. Follow them?');
