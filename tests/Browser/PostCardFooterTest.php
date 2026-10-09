<?php

declare(strict_types=1);

use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;

function waitForPostCardFooterTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 200; attempt++) {
                const element = document.querySelector('[data-testid="{$testId}"]');
                if (element && element.getBoundingClientRect().height > 0) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

test('a post card footer sits in a rounded muted panel and a draft drops its status on the drafts tab', function () {
    $user = User::factory()->create(['timezone' => 'UTC']);
    $workspace = Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $account = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id, 'timezone' => 'UTC']);
    $draft = Post::factory()->forAccount($account)->draft()->create(['user_id' => $user->id, 'content' => 'A draft']);
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'drafts']))->resize(1440, 900);
    waitForPostCardFooterTestId($page, "post-footer-{$draft->id}");

    expect($page->script(<<<JS
        (() => {
            const footer = document.querySelector('[data-testid="post-footer-{$draft->id}"]');
            const probe = document.createElement('div');
            probe.className = 'bg-muted';
            document.body.appendChild(probe);
            const muted = getComputedStyle(probe).backgroundColor;
            probe.remove();
            return {
                muted: getComputedStyle(footer).backgroundColor === muted,
                rounded: parseFloat(getComputedStyle(footer).borderTopLeftRadius) > 0,
                border: getComputedStyle(footer).borderTopWidth,
            };
        })()
    JS))->toBe(['muted' => true, 'rounded' => true, 'border' => '0px']);

    $page->assertMissing("@post-status-{$draft->id}")
        ->assertNoJavaScriptErrors();
});
