<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
});

test('an expired connect popup closes without filing an error', function () {
    Log::spy();

    $this->actingAs($this->user)
        ->get(route('app.social.instagram.callback'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('accounts/PopupCallback')
            ->where('success', false)
            ->where('message', __('accounts.popup_callback.session_expired'))
        );

    Log::shouldNotHaveReceived('error');
});

test('a lost mastodon session leaves no client credentials behind', function () {
    session([
        'mastodon_instance' => 'https://mastodon.social',
        'mastodon_client_id' => 'client-id',
        'mastodon_client_secret' => 'client-secret',
        'mastodon_oauth_state' => 'test-state',
    ]);

    $this->actingAs($this->user)
        ->get(route('app.social.mastodon.callback', ['code' => 'x', 'state' => 'test-state']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('success', false));

    expect(session()->all())
        ->not->toHaveKey('mastodon_client_secret')
        ->not->toHaveKey('mastodon_instance')
        ->not->toHaveKey('mastodon_oauth_state');
});

test('a lost threads session leaves no oauth state behind', function () {
    session(['threads_oauth_state' => 'test-state']);

    $this->actingAs($this->user)
        ->get(route('app.social.threads.callback', ['code' => 'x', 'state' => 'test-state']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('success', false));

    expect(session()->all())->not->toHaveKey('threads_oauth_state');
});
