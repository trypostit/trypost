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

test('Instagram feed original images remain publishable without cropping', function () {
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
            'size' => 1024,
            'meta' => ['width' => 1080, 'height' => 1440],
        ], range(1, 5)),
    ]);
    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => Platform::Instagram,
        'content_type' => ContentType::InstagramFeed,
        'meta' => ['aspect_ratio' => 'original'],
    ]);

    $this->actingAs($user);

    $page = visit(route('app.posts.edit', $post));
    waitForChannelIssueTestId($page, "channel-{$postPlatform->id}");

    $page->click('@instagram-settings-toggle')
        ->assertMissing('@media-rules-warning')
        ->assertMissing("@channel-issue-{$postPlatform->id}")
        ->assertEnabled('@post-submit')
        ->click('@instagram-aspect-4-5')
        ->assertMissing('@media-rules-warning')
        ->assertMissing("@channel-issue-{$postPlatform->id}")
        ->assertEnabled('@post-submit')
        ->click('@instagram-aspect-original')
        ->assertMissing('@media-rules-warning')
        ->assertMissing("@channel-issue-{$postPlatform->id}")
        ->assertEnabled('@post-submit')
        ->click('@editor-tab-preview')
        ->assertNoJavaScriptErrors();

    waitForChannelIssueTestId($page, 'instagram-feed-media');

    $heightToWidth = $page->script('(() => { const frame = document.querySelector("[data-testid=instagram-feed-media]"); const rect = frame.getBoundingClientRect(); return rect.height / rect.width; })()');

    expect(abs($heightToWidth - 4 / 3))->toBeLessThan(0.01);
});
