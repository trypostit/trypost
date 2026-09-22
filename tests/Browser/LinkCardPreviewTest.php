<?php

declare(strict_types=1);

use Amp\DeferredFuture;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Enums\UserWorkspace\Role;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Execution;

/**
 * @param  array<int, array<string, mixed>>  $media
 */
function seedLinkCardPreviewPost(
    Platform $platform,
    string $content = 'Draft without a link',
    array $media = [],
): Post {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, ['role' => Role::Member->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $account = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => $platform,
        'scopes' => $platform->requiredPublishScopes(),
    ]);

    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'content' => $content,
        'media' => $media,
    ]);

    PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => $platform,
        'content_type' => match ($platform) {
            Platform::Facebook => ContentType::FacebookPost,
            Platform::LinkedIn => ContentType::LinkedInPost,
            Platform::Mastodon => ContentType::MastodonPost,
            default => throw new LogicException('Unsupported link-card browser test platform.'),
        },
    ]);

    test()->actingAs($user);

    return $post;
}

test('facebook linkedin and mastodon previews render fetched link cards', function (Platform $platform) {
    $url = "https://93.184.216.34/{$platform->value}";
    Http::fake([$url => Http::response('<meta property="og:title" content="Article card">')]);

    $post = seedLinkCardPreviewPost($platform, "Read {$url}");

    visit(route('app.posts.edit', $post))
        ->click('@editor-tab-preview')
        ->assertSee('Article card')
        ->assertPresent('@link-card')
        ->assertNoJavaScriptErrors();

    Http::assertSentCount(1);
})->with([
    'Facebook' => Platform::Facebook,
    'LinkedIn' => Platform::LinkedIn,
    'Mastodon' => Platform::Mastodon,
]);

test('removing a url removes its visible card', function () {
    Http::fake(['https://93.184.216.34/*' => Http::response('<meta property="og:title" content="Article card">')]);
    $post = seedLinkCardPreviewPost(Platform::LinkedIn, 'https://93.184.216.34/article');

    visit(route('app.posts.edit', $post))
        ->click('@editor-tab-preview')
        ->assertSee('Article card')
        ->clear('textarea:not([readonly])')
        ->assertMissing('@link-card')
        ->assertNoJavaScriptErrors();
});

test('a failed fetch clears the previous card and a later url can recover', function () {
    Http::fake([
        'https://93.184.216.34/article' => Http::response('<meta property="og:title" content="Article card">'),
        'https://93.184.216.34/broken' => Http::response('', 500),
        'https://93.184.216.34/recovered' => Http::response('<meta property="og:title" content="Recovered card">'),
    ]);
    $post = seedLinkCardPreviewPost(Platform::LinkedIn, 'https://93.184.216.34/article');
    $page = visit(route('app.posts.edit', $post))
        ->click('@editor-tab-preview')
        ->assertSee('Article card')
        ->fill('textarea:not([readonly])', 'https://93.184.216.34/broken');

    Execution::instance()->waitForExpectation(fn () => Http::assertSentCount(2));
    $page->assertMissing('@link-card')
        ->fill('textarea:not([readonly])', 'https://93.184.216.34/recovered')
        ->assertSee('Recovered card')
        ->assertNoJavaScriptErrors();
});

test('a pending response cannot overwrite a newer card or revive a removed url', function (bool $removeUrl) {
    $release = new DeferredFuture;
    $started = false;
    Http::fake(function ($request) use ($release, &$started) {
        if ($request->url() === 'https://93.184.216.34/slow') {
            $started = true;
            $release->getFuture()->await();

            return Http::response('<meta property="og:title" content="Stale card">');
        }

        return Http::response('<meta property="og:title" content="Current card">');
    });

    $post = seedLinkCardPreviewPost(Platform::LinkedIn, 'https://93.184.216.34/slow');
    $page = visit(route('app.posts.edit', $post))->click('@editor-tab-preview');

    try {
        Execution::instance()->waitForExpectation(function () use (&$started) {
            expect($started)->toBeTrue();
        });

        $page->fill('textarea:not([readonly])', $removeUrl ? '' : 'https://93.184.216.34/current');

        if (! $removeUrl) {
            $page->assertSee('Current card');
        }
    } finally {
        $release->complete();
    }

    $page->page()->waitForLoadState('networkidle');
    $page->assertDontSee('Stale card');

    if ($removeUrl) {
        $page->assertMissing('@link-card');
    } else {
        $page->assertSee('Current card');
    }

    $page->assertNoJavaScriptErrors();
})->with(['newer url' => false, 'removed url' => true]);

test('attached media suppresses fetching until it is removed', function () {
    Http::fake(['https://93.184.216.34/*' => Http::response('<meta property="og:title" content="Article card">')]);
    $post = seedLinkCardPreviewPost(Platform::LinkedIn, 'https://93.184.216.34/article', [[
        'id' => 'image-1',
        'type' => 'image',
        'mime_type' => 'image/png',
        'path' => 'uploads/image.png',
        'url' => 'data:image/png;base64,'.base64_encode(file_get_contents(__DIR__.'/../fixtures/1x1.png')),
        'size' => 68,
    ]]);

    $page = visit(route('app.posts.edit', $post))
        ->click('@editor-tab-preview')
        ->assertPresent('@media-thumbnail')
        ->assertMissing('@link-card');

    Http::assertNothingSent();

    $page->click('@media-remove')
        ->assertSee('Article card')
        ->assertNoJavaScriptErrors();

    Http::assertSentCount(1);
});
