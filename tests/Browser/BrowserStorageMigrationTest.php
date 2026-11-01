<?php

declare(strict_types=1);

use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;

function browserStorageMigrationUser(): array
{
    config(['trypost.self_hosted' => false]);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    return [$user->fresh(), $workspace];
}

/**
 * @param  array<string, string>  $keys
 */
function seedLegacyBrowserStorage(mixed $page, array $keys): void
{
    $page->script(sprintf('(() => { Object.entries(%s).forEach(([key, value]) => localStorage.setItem(key, value)); })()', json_encode($keys)));
}

test('the earlier keys move into the user\'s storage on the first load, and every earlier key is removed', function () {
    [$user, $workspace] = browserStorageMigrationUser();
    $this->actingAs($user);
    $draft = ['version' => 1, 'savedAt' => now()->toISOString(), 'content' => 'Unfinished', 'overrides' => (object) [], 'accountIds' => [], 'media' => [], 'labelIds' => [], 'scheduleMode' => null, 'scheduledAt' => null];

    $page = visit(route('app.settings.preferences'))->resize(1280, 900);
    waitFor($page, '@settings-sidebar');
    seedLegacyBrowserStorage($page, [
        'publish.tz' => 'Asia/Tokyo',
        'publish.showSlots' => 'false',
        'insights.performanceColumns' => '["posts","reach"]',
        'insights.columns.instagram' => '["reach","likes"]',
        "calendar.skipCustomTimeConfirm.{$user->id}" => 'true',
        'trypost.emoji.recents' => '["🚀"]',
        'trypost:instagram:collaborators-notice-seen' => '1',
        'trypost:composer:last-used-accounts' => '["channel-a"]',
        "trypost.command-palette.recent.{$user->id}" => '["go-calendar"]',
        "trypost:composer:autosave:{$user->id}:{$workspace->id}" => json_encode($draft),
        'trypost:composer:autosave:someone-else:their-workspace' => json_encode($draft),
        'calendar.skipCustomTimeConfirm.someone-else' => 'true',
    ]);
    $page->refresh();
    waitFor($page, '@settings-sidebar');

    expect(browserStorageItem($page, $user, 'publish.tz'))->toBe('Asia/Tokyo')
        ->and(browserStorageItem($page, $user, 'publish.showSlots'))->toBeFalse()
        ->and(browserStorageItem($page, $user, 'insights.performanceColumns'))->toBe(['posts', 'reach'])
        ->and(browserStorageItem($page, $user, 'insights.columns.instagram'))->toBe(['reach', 'likes'])
        ->and(browserStorageItem($page, $user, 'calendar.skipCustomTimeConfirm'))->toBeTrue()
        ->and(browserStorageItem($page, $user, 'emoji.recents'))->toBe(['🚀'])
        ->and(browserStorageItem($page, $user, 'composer.instagramCollaboratorsNoticeSeen'))->toBeTrue()
        ->and(browserStorageItem($page, $user, 'composer.lastUsedAccounts', $workspace->id))->toBe(['channel-a'])
        ->and(browserStorageItem($page, $user, 'commandPalette.recent', $workspace->id))->toBe(['go-calendar'])
        ->and(browserStorageItem($page, $user, 'composer.autosave', $workspace->id)['content'] ?? null)->toBe('Unfinished')
        ->and($page->script("Object.keys(localStorage).filter((key) => key !== 'trypost:{$user->id}' && (key.startsWith('trypost') || key.startsWith('publish.') || key.startsWith('insights.') || key.startsWith('calendar.')))"))->toBe([]);
    $page->assertNoJavaScriptErrors();
});

test('a value already in the user\'s storage wins over the earlier key', function () {
    [$user] = browserStorageMigrationUser();
    $this->actingAs($user);

    $page = visit(route('app.calendar'))->resize(1280, 900);
    waitFor($page, '@app-header');
    setBrowserStorageItem($page, $user, 'publish.tz', 'Europe/Lisbon');
    seedLegacyBrowserStorage($page, ['publish.tz' => 'Asia/Tokyo']);
    $page->refresh();
    waitFor($page, '@app-header');

    expect(browserStorageItem($page, $user, 'publish.tz'))->toBe('Europe/Lisbon')
        ->and($page->script("localStorage.getItem('publish.tz')"))->toBeNull();
    $page->assertNoJavaScriptErrors();
});

test('the earlier keys move in when the user signs in, and the first page already shows them', function () {
    [$user, $workspace] = browserStorageMigrationUser();
    $user->update(['email' => 'storage-migration@example.com']);
    $channel = SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $workspace->id,
        'display_name' => 'Acme LinkedIn',
    ]);

    $page = visit(route('login'));
    waitFor($page, '@login-email');
    seedLegacyBrowserStorage($page, [
        'trypost.emoji.recents' => '["🚀"]',
        "trypost.command-palette.recent.{$user->id}" => json_encode(["channel-{$channel->id}"]),
    ]);

    $page->type('@login-email', 'storage-migration@example.com')
        ->type('@login-password', 'password')
        ->click('@login-submit');
    waitForCondition($page, "localStorage.getItem('trypost.emoji.recents') === null");
    waitFor($page, '@app-content-shell');
    $page->keys('@app-content-shell', 'ControlOrMeta+k');
    waitFor($page, '@command-palette-input');

    expect(browserStorageItem($page, $user, 'emoji.recents'))->toBe(['🚀']);
    $page->assertSeeIn("@command-palette-recent-channel-{$channel->id}", 'Acme LinkedIn')
        ->assertNoJavaScriptErrors();
});

test('without a current workspace the earlier keys wait for one', function () {
    [$user] = browserStorageMigrationUser();
    $user->update(['current_workspace_id' => null]);
    $this->actingAs($user);

    $page = visit(route('app.settings.preferences'));
    waitFor($page, '@settings-sidebar');
    seedLegacyBrowserStorage($page, ['trypost:composer:last-used-accounts' => '["channel-a"]']);
    $page->refresh();
    waitFor($page, '@settings-sidebar');

    expect($page->script("localStorage.getItem('trypost:composer:last-used-accounts')"))->toBe('["channel-a"]');
    $page->assertNoJavaScriptErrors();
});

test('an unfinished post from the earlier key is offered back in the composer', function () {
    [$user, $workspace] = browserStorageMigrationUser();
    $this->actingAs($user);
    $draft = ['version' => 1, 'savedAt' => now()->toISOString(), 'content' => 'Written before the update', 'overrides' => (object) [], 'accountIds' => [], 'media' => [], 'labelIds' => [], 'scheduleMode' => null, 'scheduledAt' => null];

    $page = visit(route('app.posts.index'));
    waitFor($page, '@posts-new-post');
    seedLegacyBrowserStorage($page, ["trypost:composer:autosave:{$user->id}:{$workspace->id}" => json_encode($draft)]);
    $page->refresh();
    waitFor($page, '@posts-new-post');
    $page->click('@posts-new-post');
    waitFor($page, '@composer-resume-dialog');

    $page->assertSeeIn('@composer-resume-preview', 'Written before the update')
        ->assertNoJavaScriptErrors();
});
