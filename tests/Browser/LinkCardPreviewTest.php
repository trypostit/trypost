<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Enums\UserWorkspace\Role;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;

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

/**
 * The browser server is a separate process, so Laravel HTTP fakes do not cross
 * that boundary. Intercept only the standalone Inertia HTTP request used by
 * useLinkCard; every other XHR still uses the browser's native implementation.
 *
 * @param  array<string, array{status?: int, delay?: int, body?: array<string, mixed>|null}>  $responses
 */
function installLinkPreviewResponses(mixed $page, array $responses): void
{
    $encodedResponses = json_encode($responses, JSON_THROW_ON_ERROR);

    $page->script(<<<JS
        (() => {
            const responses = {$encodedResponses};
            const originalOpen = XMLHttpRequest.prototype.open;
            const originalSend = XMLHttpRequest.prototype.send;
            window.__linkPreviewRequests = 0;

            XMLHttpRequest.prototype.open = function(method, url, ...rest) {
                this.__linkPreviewRequest = String(url).includes('/posts/link-preview');
                return originalOpen.call(this, method, url, ...rest);
            };

            XMLHttpRequest.prototype.send = function(body) {
                if (!this.__linkPreviewRequest) {
                    return originalSend.call(this, body);
                }

                window.__linkPreviewRequests++;
                const target = JSON.parse(String(body)).url;
                const response = responses[target] ?? { status: 500, body: null };

                setTimeout(() => {
                    Object.defineProperty(this, 'status', { configurable: true, value: response.status ?? 200 });
                    Object.defineProperty(this, 'responseText', {
                        configurable: true,
                        value: JSON.stringify(response.body ?? null),
                    });
                    this.getAllResponseHeaders = () => 'content-type: application/json\\r\\n';
                    this.onload?.();
                }, response.delay ?? 0);
            };
        })();
    JS);
}

function waitForLinkCardState(mixed $page, bool $visible): void
{
    $expected = $visible ? 'true' : 'false';

    $page->script(<<<JS
        (async () => {
            for (let i = 0; i < 100; i++) {
                const card = document.querySelector('[data-testid="link-card"]');
                const isVisible = Boolean(card && card.getBoundingClientRect().height > 0);
                if (isVisible === {$expected}) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

function waitForLinkCardTitle(mixed $page, string $title): void
{
    $encodedTitle = json_encode($title, JSON_THROW_ON_ERROR);

    $page->script(<<<JS
        (async () => {
            for (let i = 0; i < 100; i++) {
                const title = document.querySelector('[data-testid="link-card-title"]');
                if (title?.textContent?.trim() === {$encodedTitle}) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

test('facebook linkedin and mastodon previews render fetched link cards', function (Platform $platform) {
    $url = "https://93.184.216.34/{$platform->value}";
    $title = "{$platform->label()} preview card";

    $post = seedLinkCardPreviewPost($platform);
    $page = visit(route('app.posts.edit', $post));
    $page->click('@editor-tab-preview');
    installLinkPreviewResponses($page, [
        $url => ['body' => [
            'uri' => $url,
            'domain' => '93.184.216.34',
            'title' => $title,
            'description' => 'Browser-tested description',
            'image' => null,
        ]],
    ]);
    $page->fill('textarea:not([readonly])', "Read {$url}");
    waitForLinkCardTitle($page, $title);

    $page->assertSee($title)
        ->assertPresent('@link-card')
        ->assertNoJavaScriptErrors();
})->with([
    'Facebook' => Platform::Facebook,
    'LinkedIn' => Platform::LinkedIn,
    'Mastodon' => Platform::Mastodon,
]);

test('the link card ignores stale responses, handles failure and disappears with the url', function () {
    $firstUrl = 'https://93.184.216.34/first';
    $secondUrl = 'https://93.184.216.34/second';
    $failedUrl = 'https://93.184.216.34/failure';

    $post = seedLinkCardPreviewPost(Platform::LinkedIn);
    $page = visit(route('app.posts.edit', $post));
    $page->click('@editor-tab-preview');
    installLinkPreviewResponses($page, [
        $firstUrl => ['delay' => 900, 'body' => [
            'uri' => $firstUrl,
            'domain' => '93.184.216.34',
            'title' => 'First card',
            'description' => '',
            'image' => null,
        ]],
        $secondUrl => ['delay' => 100, 'body' => [
            'uri' => $secondUrl,
            'domain' => '93.184.216.34',
            'title' => 'Second card',
            'description' => '',
            'image' => null,
        ]],
        $failedUrl => ['status' => 500],
    ]);

    $page->fill('textarea:not([readonly])', "Initial {$firstUrl}");
    $page->script('() => new Promise((resolve) => setTimeout(resolve, 500))');
    $page->fill('textarea:not([readonly])', "Updated {$secondUrl}");
    waitForLinkCardTitle($page, 'Second card');
    $page->script('() => new Promise((resolve) => setTimeout(resolve, 500))');
    $page->assertSee('Second card')->assertDontSee('First card');

    $page->fill('textarea:not([readonly])', "Broken {$failedUrl}");
    $page->script('() => new Promise((resolve) => setTimeout(resolve, 600))');
    waitForLinkCardState($page, false);
    $page->assertMissing('@link-card');

    $page->clear('textarea:not([readonly])');
    waitForLinkCardState($page, false);

    $page->assertMissing('@link-card')
        ->assertNoJavaScriptErrors();
});

test('attached media suppresses the link card request', function () {
    $url = 'https://93.184.216.34/with-media';
    $post = seedLinkCardPreviewPost(
        Platform::LinkedIn,
        "Media post {$url}",
        [[
            'id' => 'image-1',
            'type' => 'image',
            'mime_type' => 'image/png',
            'path' => 'uploads/image.png',
            'url' => 'data:image/png;base64,iVBORw0KGgo=',
            'size' => 12,
        ]],
    );

    $page = visit(route('app.posts.edit', $post));
    installLinkPreviewResponses($page, [
        $url => ['body' => [
            'uri' => $url,
            'domain' => '93.184.216.34',
            'title' => 'Should not render',
            'description' => '',
            'image' => null,
        ]],
    ]);
    $page->click('@editor-tab-preview');
    $page->script('() => new Promise((resolve) => setTimeout(resolve, 600))');

    $page->assertMissing('@link-card')
        ->assertScript('window.__linkPreviewRequests', 0)
        ->assertNoJavaScriptErrors();
});
