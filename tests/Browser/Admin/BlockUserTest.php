<?php

declare(strict_types=1);

use App\Models\User;

test('an admin blocks a user with a reason and unblocks them from the user page', function () {
    $admin = admin();
    $user = User::factory()->create(['name' => 'Spammer']);

    $this->actingAs($admin);

    $page = visit(route('admin.users.show', $user));

    waitFor($page, '@admin-block-user');
    $page->click('@admin-block-user');
    waitForOpen($page, '@block-user-dialog');
    $page->fill('@block-user-reason', 'Posting spam links');
    $page->click('@block-user-submit');

    waitFor($page, '@admin-user-blocked');
    $page->assertSeeIn('@admin-user-blocked', 'Posting spam links')
        ->assertVisible('@admin-unblock-user')
        ->assertNoJavaScriptErrors();

    expect($user->fresh()->isBlocked())->toBeTrue();

    $page->click('@admin-unblock-user');
    waitForGone($page, '@admin-user-blocked');
    $page->assertVisible('@admin-block-user')->assertNoJavaScriptErrors();

    expect($user->fresh()->isBlocked())->toBeFalse();
});

test('the users tabs follow the selected status', function () {
    $admin = admin();
    $blocked = User::factory()->blocked()->create();
    $this->actingAs($admin);

    $page = visit(route('admin.users.index'));

    waitFor($page, '@admin-users-filter-blocked');
    $page->click('@admin-users-filter-blocked');

    waitForState($page, '@admin-users-filter-blocked', "element.dataset.state === 'active'");
    waitForCondition($page, "location.search.includes('status=blocked')");
    waitForGone($page, "@admin-user-{$admin->id}");
    waitForCount($page, '@admin-users [data-testid^="admin-user-blocked-"]', 1);
    letPageRun($page, 1000);

    waitForState($page, '@admin-users-filter-blocked', "element.dataset.state === 'active'");
    $page->assertVisible("@admin-user-blocked-{$blocked->id}")
        ->assertNoJavaScriptErrors();
});
