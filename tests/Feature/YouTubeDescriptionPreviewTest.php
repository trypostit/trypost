<?php

declare(strict_types=1);

use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\PreviewPostTool;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Services\Post\PostPreviewer;
use Illuminate\Testing\Fluent\AssertableJson;

test('youtube description preview includes effective bytes and the description length limit', function (mixed $description, string $expected) {
    $post = Post::factory()->create(['content' => 'Title']);
    $account = SocialAccount::factory()->youtube()->create(['workspace_id' => $post->workspace_id]);
    PostPlatform::factory()->youtube()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'enabled' => true,
        'meta' => ['description' => $description],
    ]);

    $preview = app(PostPreviewer::class)->forPost($post->fresh())['platforms'][0];

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
    $post = Post::factory()->create(['content' => 'Title']);
    PostPlatform::factory()->create(['post_id' => $post->id, 'enabled' => true]);

    $preview = app(PostPreviewer::class)->forPost($post->fresh())['platforms'][0];

    expect($preview)->not->toHaveKeys(['description', 'description_length_bytes']);
});

test('API and MCP previews expose independent YouTube descriptions', function () {
    $token = createApiTestToken();
    $post = Post::factory()->create([
        'workspace_id' => $token['workspace']->id,
        'user_id' => $token['user']->id,
        'content' => 'Short title',
    ]);
    $platforms = collect(['First channel', 'Second channel'])->map(function (string $description) use ($post) {
        $account = SocialAccount::factory()->youtube()->create(['workspace_id' => $post->workspace_id]);

        return PostPlatform::factory()->youtube()->create([
            'post_id' => $post->id,
            'social_account_id' => $account->id,
            'enabled' => true,
            'meta' => ['description' => $description],
        ]);
    });

    $response = $this->withToken($token['plain_token'])
        ->getJson(route('api.posts.preview', $post))
        ->assertOk();

    foreach ($platforms as $platform) {
        $preview = collect($response->json('platforms'))->firstWhere('post_platform_id', $platform->id);

        expect($preview['description'])->toBe($platform->meta['description'])
            ->and($preview['description_length_bytes'])->toBe(strlen($platform->meta['description']))
            ->and($preview['sanitized_content'])->toBe('Short title');
    }

    TryPostServer::actingAs($token['user'])->tool(PreviewPostTool::class, ['post_id' => $post->id])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) use ($platforms) {
            $json->etc();

            foreach ($platforms as $platform) {
                $preview = collect($json->toArray()['platforms'])->firstWhere('post_platform_id', $platform->id);

                expect($preview['description'])->toBe($platform->meta['description'])
                    ->and($preview['description_length_bytes'])->toBe(strlen($platform->meta['description']))
                    ->and($preview['sanitized_content'])->toBe('Short title');
            }
        });
});

test('youtube preview shows the explicit title or the derived one', function (array $meta, string $expected) {
    $post = Post::factory()->create(['content' => 'First sentence. Second']);
    $account = SocialAccount::factory()->youtube()->create(['workspace_id' => $post->workspace_id]);
    PostPlatform::factory()->youtube()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'enabled' => true,
        'meta' => $meta,
    ]);

    expect(app(PostPreviewer::class)->forPost($post->fresh())['platforms'][0]['title'])->toBe($expected);
})->with([
    'explicit title' => [['title' => 'My title'], 'My title'],
    'derived title' => [[], 'First sentence. Second'],
]);
