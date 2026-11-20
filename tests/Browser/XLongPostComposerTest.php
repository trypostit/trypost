<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;

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
    waitFor($page, '@composer-add-account');
    waitForState($page, '@post-composer-dialog', "element.getAnimations().every((animation) => animation.playState !== 'running')");
    $page->click('@composer-add-account')
        ->click("@composer-account-option-{$x->id}")
        ->click("@composer-account-option-{$linkedin->id}");
    waitFor($page, '@composer-base-content');
    waitForState($page, '@post-composer-dialog', "element.getAnimations().every((animation) => animation.playState !== 'running')");
    $page->fill('@composer-base-content', 'Hello')
        ->assertMissing('@composer-base-char-count')
        ->click('@composer-next');
    waitFor($page, "@composer-char-count-{$x->id}");
    waitForState($page, '@post-composer-dialog', "element.getAnimations().every((animation) => animation.playState !== 'running')");

    expect(xLongPostCounter($page, "composer-char-count-{$x->id}"))->toBe('24995');
    $page->assertNoJavaScriptErrors();
});
