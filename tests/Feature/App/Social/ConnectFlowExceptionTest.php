<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Social\PendingConnection;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));
});

test('an expired connection lands on the expired page without filing an error', function () {
    Log::spy();

    $this->actingAs($this->user)
        ->get(route('app.social.instagram.callback'))
        ->assertRedirect(route('app.social.connect.show', Platform::Instagram));

    $this->get(route('app.social.connect.show', Platform::Instagram))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'expired'));

    Log::shouldNotHaveReceived('error');
});

test('an expired callback does not fail a connection another network has pending', function () {
    startSocialConnect($this->workspace, Platform::Facebook);

    $this->actingAs($this->user)
        ->get(route('app.social.instagram.callback'))
        ->assertRedirect(route('app.social.connect.show', Platform::Instagram));

    expect(PendingConnection::current()->platform())->toBe(Platform::Facebook)
        ->and(socialConnectFailure())->toBeNull();
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
        ->assertRedirect(route('app.social.connect.show', Platform::Mastodon));

    expect(session()->all())
        ->not->toHaveKey('mastodon_client_secret')
        ->not->toHaveKey('mastodon_instance')
        ->not->toHaveKey('mastodon_oauth_state');
});

test('a lost threads session leaves no oauth state behind', function () {
    session(['threads_oauth_state' => 'test-state']);

    $this->actingAs($this->user)
        ->get(route('app.social.threads.callback', ['code' => 'x', 'state' => 'test-state']))
        ->assertRedirect(route('app.social.connect.show', Platform::Threads));

    expect(session()->all())->not->toHaveKey('threads_oauth_state');
});
