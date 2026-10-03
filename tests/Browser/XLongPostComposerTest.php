<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;

function waitForXLongPostTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                const sheet = document.querySelector('[data-testid="post-composer-dialog"]');
                if ((sheet === null || sheet.getAnimations().every((animation) => animation.playState !== 'running'))
                    && document.querySelector('[data-testid="{$testId}"]')?.getBoundingClientRect().height > 0) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

function xLongPostCounter(mixed $page, string $testId): string
{
    return trim((string) $page->script("document.querySelector('[data-testid=\"{$testId}\"]').textContent"));
}

test('a premium x card counts against the account limit', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $x = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::X,
        'meta' => ['x_subscription_type' => 'Premium'],
        'position' => 0,
    ]);
    $linkedin = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::LinkedIn,
        'position' => 1,
    ]);
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    waitForXLongPostTestId($page, 'composer-add-account');
    $page->click('@composer-add-account')
        ->click("@composer-account-option-{$x->id}")
        ->click("@composer-account-option-{$linkedin->id}");
    waitForXLongPostTestId($page, 'composer-base-content');
    $page->fill('@composer-base-content', 'Hello')
        ->assertMissing('@composer-base-char-count')
        ->click('@composer-next');
    waitForXLongPostTestId($page, "composer-char-count-{$x->id}");

    expect(xLongPostCounter($page, "composer-char-count-{$x->id}"))->toBe('24995');
    $page->assertNoJavaScriptErrors();
});
