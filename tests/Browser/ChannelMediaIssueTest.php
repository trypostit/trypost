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
 * A post carrying a PDF with one deselected X channel. X never accepts a
 * document, so the channel has a media issue before the user touches it.
 */
function seedChannelMediaIssuePost(): PostPlatform
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, ['role' => Role::Member->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $account = SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id]);

    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'content' => 'hello',
        'media' => [[
            'id' => 'd1',
            'type' => 'document',
            'mime_type' => 'application/pdf',
            'path' => 'uploads/deck.pdf',
            'url' => 'https://cdn.test/deck.pdf',
            'size' => 1024,
        ]],
    ]);

    $postPlatform = PostPlatform::factory()->disabled()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => Platform::X,
        'content_type' => ContentType::XPost,
    ]);

    test()->actingAs($user);

    return $postPlatform;
}

function waitForChannelIssueTestId(mixed $page, string $testId): void
{
    waitForChannelIssueCondition($page, $testId, 'el.getBoundingClientRect().height > 0');
}

function waitForChannelIssuePressed(mixed $page, string $testId): void
{
    waitForChannelIssueCondition($page, $testId, "el.getAttribute('aria-pressed') === 'true'");
}

/**
 * Polls from the page (never sleep(): the test's HTTP server only ticks while
 * Pest awaits Playwright) until the element exists and `$condition` holds.
 */
function waitForChannelIssueCondition(mixed $page, string $testId, string $condition): void
{
    $page->script(<<<JS
        (async () => {
            const sel = '[data-testid="{$testId}"]';
            for (let i = 0; i < 100; i++) {
                const el = document.querySelector(sel);
                if (el && ({$condition})) return;
                await new Promise((r) => setTimeout(r, 50));
            }
        })();
    JS);
}

test('a channel the media does not fit stays selectable and shows the issue badge', function () {
    $postPlatform = seedChannelMediaIssuePost();

    $page = visit(route('app.posts.edit', $postPlatform->post));
    waitForChannelIssueTestId($page, "channel-{$postPlatform->id}");

    $page->assertEnabled("@channel-{$postPlatform->id}")
        ->assertAttribute("@channel-{$postPlatform->id}", 'aria-pressed', 'false')
        ->assertPresent("@channel-issue-{$postPlatform->id}");

    $page->click("@channel-{$postPlatform->id}");
    waitForChannelIssuePressed($page, "channel-{$postPlatform->id}");

    $page->assertAttribute("@channel-{$postPlatform->id}", 'aria-pressed', 'true')
        ->assertPresent("@channel-issue-{$postPlatform->id}")
        ->assertNoJavaScriptErrors();
});

test('the channel issue tooltip links to the network section of the media docs', function () {
    $postPlatform = seedChannelMediaIssuePost();

    $page = visit(route('app.posts.edit', $postPlatform->post));
    waitForChannelIssueTestId($page, "channel-{$postPlatform->id}");

    $page->hover("@channel-{$postPlatform->id}");
    waitForChannelIssueTestId($page, "channel-issue-docs-{$postPlatform->id}");

    $page->assertAttribute(
        "@channel-issue-docs-{$postPlatform->id}",
        'href',
        'https://docs.trypost.it/knowledge-base/media#x-twitter',
    )->assertNoJavaScriptErrors();
});

/**
 * @param  array<string, string>  $platformMeta
 * @param  array<string, int>  $imageMeta
 * @return array{Post, PostPlatform}
 */
function seedInstagramFeedImagePost(array $platformMeta = ['aspect_ratio' => 'original'], array $imageMeta = ['width' => 1080, 'height' => 1440], int $size = 1024): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, ['role' => Role::Member->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'content' => 'A five-image carousel',
        'media' => array_map(fn (int $index): array => [
            'id' => "image-{$index}",
            'type' => 'image',
            'mime_type' => 'image/jpeg',
            'path' => "uploads/image-{$index}.jpg",
            'url' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            'size' => $size,
            'meta' => $imageMeta,
        ], range(1, 5)),
    ]);
    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => Platform::Instagram,
        'content_type' => ContentType::InstagramFeed,
        'meta' => $platformMeta,
    ]);

    test()->actingAs($user);

    return [$post, $postPlatform];
}

test('Instagram feed aspect choices do not reject the source image in the editor', function () {
    [$post, $postPlatform] = seedInstagramFeedImagePost();

    $page = visit(route('app.posts.edit', $post));
    waitForChannelIssueTestId($page, "channel-{$postPlatform->id}");

    $page->click('@instagram-settings-toggle');

    foreach (['1-1' => 1.0, '4-5' => 5 / 4, '16-9' => 9 / 16, 'original' => 4 / 3] as $option => $expectedHeightToWidth) {
        $page->click("@instagram-aspect-{$option}")
            ->assertMissing('@media-rules-warning')
            ->assertMissing("@channel-issue-{$postPlatform->id}")
            ->assertEnabled('@post-submit')
            ->click('@editor-tab-preview');

        waitForChannelIssueTestId($page, 'instagram-feed-media');

        $heightToWidth = $page->script('(() => { const frame = document.querySelector("[data-testid=instagram-feed-media]"); const rect = frame.getBoundingClientRect(); return rect.height / rect.width; })()');

        expect(abs($heightToWidth - $expectedHeightToWidth))->toBeLessThan(0.01);

        $page->click('@editor-tab-channels');
    }

    $page->assertNoJavaScriptErrors();
});

test('Instagram feed without a saved aspect ratio uses the original image', function () {
    [$post, $postPlatform] = seedInstagramFeedImagePost([]);

    $page = visit(route('app.posts.edit', $post));
    waitForChannelIssueTestId($page, "channel-{$postPlatform->id}");

    $page->click('@instagram-settings-toggle');

    $originalSelected = $page->script('document.querySelector("[data-testid=instagram-aspect-original]").classList.contains("bg-violet-100")');

    expect($originalSelected)->toBeTrue();

    $page->assertMissing('@media-rules-warning')
        ->assertMissing("@channel-issue-{$postPlatform->id}")
        ->assertEnabled('@post-submit')
        ->click('@editor-tab-preview')
        ->assertNoJavaScriptErrors();

    waitForChannelIssueTestId($page, 'instagram-feed-media');

    $heightToWidth = $page->script('(() => { const frame = document.querySelector("[data-testid=instagram-feed-media]"); const rect = frame.getBoundingClientRect(); return rect.height / rect.width; })()');

    expect(abs($heightToWidth - 4 / 3))->toBeLessThan(0.01);
});

test('Instagram original preview falls back to square when image dimensions are unavailable', function () {
    [$post] = seedInstagramFeedImagePost(imageMeta: []);

    $page = visit(route('app.posts.edit', $post));
    $page->click('@editor-tab-preview');
    waitForChannelIssueTestId($page, 'instagram-feed-media');

    $heightToWidth = $page->script('(() => { const frame = document.querySelector("[data-testid=instagram-feed-media]"); const rect = frame.getBoundingClientRect(); return rect.height / rect.width; })()');

    expect(abs($heightToWidth - 1.0))->toBeLessThan(0.01);
});

test('Instagram aspect selection still enforces image size limits', function () {
    [$post] = seedInstagramFeedImagePost(size: 9 * 1024 * 1024);

    $page = visit(route('app.posts.edit', $post));
    $page->click('@instagram-settings-toggle');
    waitForChannelIssueTestId($page, 'media-rules-warning');

    $page->assertPresent('@media-rules-warning')
        ->assertDisabled('@post-submit')
        ->assertNoJavaScriptErrors();
});
