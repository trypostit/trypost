<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

test('connect routes redirect to workspace creation when there is no current workspace', function () {
    $user = User::factory()->create(['current_workspace_id' => null]);

    $this->actingAs($user)
        ->get(route('app.social.x.connect'))
        ->assertRedirect(route('app.workspaces.create'));
});

test('connect routes require a workspace even in self-hosted mode', function () {
    config()->set('trypost.self_hosted', true);

    $user = User::factory()->create(['current_workspace_id' => null]);

    $this->actingAs($user)
        ->get(route('app.social.x.connect'))
        ->assertRedirect(route('app.workspaces.create'));
});

test('oauth callbacks and the confirmation page are not blocked by the workspace gate', function () {
    $user = User::factory()->create(['current_workspace_id' => null]);

    $this->actingAs($user)
        ->get(route('app.social.x.callback'))
        ->assertRedirect(route('app.social.connect.show', Platform::X));

    $this->get(route('app.social.connect.show', Platform::X))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('app/accounts/ConnectFinish')
            ->where('state', 'expired'),
        );
});
