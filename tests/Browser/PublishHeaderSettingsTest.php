<?php

declare(strict_types=1);

use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;

function publishHeaderUser(bool $admin): User
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['account_id' => $owner->account_id, 'user_id' => $owner->id]);
    $workspace->members()->attach($owner->id, membershipPivot('admin'));
    $owner->update(['current_workspace_id' => $workspace->id]);

    if ($admin) {
        return $owner->fresh();
    }

    $member = User::factory()->create(['account_id' => $owner->account_id]);
    $workspace->members()->attach($member->id, membershipPivot('member'));
    $member->update(['current_workspace_id' => $workspace->id]);

    return $member->fresh();
}

test('the all channels header links to the channels settings with a tooltip', function () {
    $user = publishHeaderUser(admin: true);
    SocialAccount::factory()->linkedin()->create(['workspace_id' => $user->current_workspace_id]);
    connectAiTo($user);
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    waitFor($page, '@publish-channels-settings');

    $page->hover('@publish-channels-settings');
    waitFor($page, '@publish-channels-settings-tooltip');
    $page->assertSeeIn('@publish-channels-settings-tooltip', __('channels.settings'));

    $page->click('@publish-channels-settings');
    waitForPath($page, route('app.workspace.channels', absolute: false));

    $page->assertNoJavaScriptErrors();
});

test('the channel header settings cog shows a tooltip', function () {
    $user = publishHeaderUser(admin: true);
    $channel = SocialAccount::factory()->linkedin()->create(['workspace_id' => $user->current_workspace_id]);
    connectAiTo($user);
    $this->actingAs($user);

    $page = visit(route('app.channels.publish', $channel));
    waitFor($page, '@publish-channel-settings');

    $page->hover('@publish-channel-settings');
    waitFor($page, '@publish-channel-settings-tooltip');

    $page->assertSeeIn('@publish-channel-settings-tooltip', __('channels.settings'))
        ->assertNoJavaScriptErrors();
});

test('members do not see the channels settings cog in the header', function () {
    $user = publishHeaderUser(admin: false);
    SocialAccount::factory()->linkedin()->create(['workspace_id' => $user->current_workspace_id]);
    connectAiTo($user);
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    waitFor($page, '@header-title');

    $page->assertMissing('@publish-channels-settings')
        ->assertNoJavaScriptErrors();
});
