<?php

declare(strict_types=1);

use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

test('admins see the channel list of the current workspace only', function () {
    $mine = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id]);
    SocialAccount::factory()->x()->create();

    $this->actingAs($this->user)
        ->get(route('app.workspace.channels'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/workspace/Channels', false)
            ->has('connectedChannels', 1)
            ->where('connectedChannels.0.id', $mine->id)
        );
});

test('members cannot open the channel list', function () {
    $member = User::factory()->create();
    $this->workspace->members()->attach($member->id, membershipPivot('member'));
    $member->update(['current_workspace_id' => $this->workspace->id]);

    $this->actingAs($member)
        ->get(route('app.workspace.channels'))
        ->assertForbidden();
});

test('channel pages render publish and insights for the current workspace', function () {
    $account = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->user)
        ->get(route('app.channels.publish', $account))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('publish/Index')
            ->where('scope', 'channel')
            ->where('channel.id', $account->id));

    $this->actingAs($this->user)
        ->get(route('app.channels.insights', $account))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('channels/Insights')
            ->where('channel.id', $account->id));
});

test('channel pages of another workspace are not found', function (string $name) {
    $foreign = SocialAccount::factory()->linkedin()->create();

    $this->actingAs($this->user)
        ->get(route($name, $foreign))
        ->assertNotFound();
})->with(['app.channels.publish', 'app.channels.insights', 'app.channels.settings']);

test('disconnect refuses another workspace channel', function () {
    $foreign = SocialAccount::factory()->linkedin()->create();

    $this->actingAs($this->user)
        ->delete(route('app.channels.disconnect', $foreign))
        ->assertForbidden();

    expect(SocialAccount::query()->whereKey($foreign->id)->exists())->toBeTrue();
});
