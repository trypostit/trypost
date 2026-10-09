<?php

declare(strict_types=1);

use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\PreviewPostTool;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Post\PostPreviewer;
use Illuminate\Testing\Fluent\AssertableJson;

test('youtube description preview includes effective bytes and the description length limit', function (mixed $description, string $expected) {
    $post = Post::factory()->youtube()->create([
        'content' => 'Title',
        'meta' => ['description' => $description],
    ]);

    $preview = app(PostPreviewer::class)->forPost($post->fresh());

    expect($preview['description'])->toBe($expected)
        ->and($preview['description_length_bytes'])->toBe(strlen($expected))
        ->and($preview['sanitized_content'])->toBe('Title')
        ->and($preview['sanitized_length'])->toBe(5)
        ->and($preview['max_content_length'])->toBe(5000);
})->with([
    'custom description' => ['ação', 'ação'],
    'null description' => [null, 'Title'],
    'empty description' => ['', 'Title'],
    'whitespace description' => [" \t\n\u{00A0}", 'Title'],
    'invalid metadata type' => [['invalid'], 'Title'],
]);

test('other networks do not gain youtube description preview fields', function () {
    $post = Post::factory()->linkedin()->create(['content' => 'Title']);

    $preview = app(PostPreviewer::class)->forPost($post->fresh());

    expect($preview)->not->toHaveKeys(['description', 'description_length_bytes']);
});

test('API and MCP previews expose the YouTube description', function () {
    $token = createApiTestToken();
    $account = SocialAccount::factory()->youtube()->create(['workspace_id' => $token['workspace']->id]);
    $post = Post::factory()->forAccount($account)->create([
        'user_id' => $token['user']->id,
        'content' => 'Short title',
        'meta' => ['description' => 'Channel description'],
    ]);

    $response = $this->withToken($token['plain_token'])
        ->getJson(route('api.posts.preview', $post))
        ->assertOk();

    expect($response->json('description'))->toBe('Channel description')
        ->and($response->json('description_length_bytes'))->toBe(strlen('Channel description'))
        ->and($response->json('sanitized_content'))->toBe('Short title');

    TryPostServer::actingAs($token['user'])->tool(PreviewPostTool::class, ['post_id' => $post->id])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->etc();
            $preview = $json->toArray();

            expect($preview['description'])->toBe('Channel description')
                ->and($preview['description_length_bytes'])->toBe(strlen('Channel description'))
                ->and($preview['sanitized_content'])->toBe('Short title');
        });
});

test('youtube preview shows the explicit title or the derived one', function (array $meta, string $expected) {
    $post = Post::factory()->youtube()->create([
        'content' => 'First sentence. Second',
        'meta' => $meta,
    ]);

    expect(app(PostPreviewer::class)->forPost($post->fresh())['title'])->toBe($expected);
})->with([
    'explicit title' => [['title' => 'My title'], 'My title'],
    'derived title' => [[], 'First sentence. Second'],
]);
