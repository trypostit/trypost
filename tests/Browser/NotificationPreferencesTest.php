<?php

declare(strict_types=1);

use App\Models\NotificationPreference;
use App\Models\User;
use App\Models\Workspace;

function waitForNotificationPreferencesTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                if (document.querySelector('[data-testid="{$testId}"]')?.getBoundingClientRect().height > 0) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

function notificationPreferencesUser(): User
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);

    return $user->fresh();
}

test('a switch saves on its own, survives a reload, and there is no save button', function () {
    $user = notificationPreferencesUser();
    $this->actingAs($user);

    $page = visit(route('app.notifications.preferences'));
    waitForNotificationPreferencesTestId($page, 'notifications-post_failed');

    expect($page->script('document.querySelectorAll(\'[data-testid="notifications-page"] button:not([role="switch"])\').length'))->toBe(0);

    $page->click('@notifications-post_failed');
    waitForNotificationPreferencesTestId($page, 'notifications-saved-post_failed');

    expect(NotificationPreference::query()->where('user_id', $user->id)->sole()->post_failed)->toBeFalse();

    $page = visit(route('app.notifications.preferences'));
    waitForNotificationPreferencesTestId($page, 'notifications-post_failed');
    $page->assertAttribute('@notifications-post_failed', 'data-state', 'unchecked')
        ->assertAttribute('@notifications-post_published', 'data-state', 'checked')
        ->assertNoJavaScriptErrors();
});

test('two switches toggled quickly both persist', function () {
    $user = notificationPreferencesUser();
    $this->actingAs($user);

    $page = visit(route('app.notifications.preferences'));
    waitForNotificationPreferencesTestId($page, 'notifications-collaboration');
    $page->script(<<<'JS'
        document.querySelector('[data-testid="notifications-post_published"]').click();
        document.querySelector('[data-testid="notifications-collaboration"]').click();
    JS);
    waitForNotificationPreferencesTestId($page, 'notifications-saved-collaboration');

    $preference = NotificationPreference::query()->where('user_id', $user->id)->sole();
    expect($preference->post_published)->toBeFalse()
        ->and($preference->collaboration)->toBeFalse();
    $page->assertNoJavaScriptErrors();
});
