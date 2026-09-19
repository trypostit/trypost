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
