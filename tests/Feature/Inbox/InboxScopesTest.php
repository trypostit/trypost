<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));
});

test('connect asks for no inbox scope while the inbox is off', function (string $route, array $scopes) {
    $location = urldecode((string) $this->actingAs($this->user)->get(route($route))->headers->get('Location'));

    foreach ($scopes as $scope) {
        expect($location)->not->toContain($scope);
    }
})->with('inbox connect scopes');

test('connect asks for every inbox scope, mentions and messages included, while the inbox is on', function (string $route, array $scopes) {
    config()->set('trypost.inbox.enabled', true);

    $location = urldecode((string) $this->actingAs($this->user)->get(route($route))->headers->get('Location'));

    foreach ($scopes as $scope) {
        expect($location)->toContain($scope);
    }
})->with('inbox connect scopes');

dataset('inbox connect scopes', [
    'facebook' => ['app.social.facebook.connect', ['pages_read_user_content', 'pages_manage_engagement', 'pages_manage_metadata', 'pages_messaging']],
    'instagram via facebook' => ['app.social.instagram-facebook.connect', ['instagram_manage_comments', 'pages_manage_metadata', 'instagram_manage_messages']],
    'instagram' => ['app.social.instagram.connect', ['instagram_business_manage_comments', 'instagram_business_manage_messages']],
    'threads' => ['app.social.threads.connect', ['threads_read_replies', 'threads_manage_replies', 'threads_manage_mentions']],
]);

test('the facebook grant check covers the inbox scopes, so a granted one is stored', function () {
    config()->set('trypost.inbox.enabled', true);
    Queue::fake();
    startSocialConnect($this->workspace->id, Platform::Facebook);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('facebook_user_123');
    $socialiteUser->token = 'test-user-token';

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn(Mockery::mock()->shouldReceive('usingGraphVersion')->andReturnSelf()->shouldReceive('user')->andReturn($socialiteUser)->getMock());

    $graphApi = config('trypost.platforms.facebook.graph_api');

    Http::fake([
        "{$graphApi}/me/permissions*" => Http::response(['data' => [
            ['permission' => 'pages_manage_posts', 'status' => 'granted'],
            ['permission' => 'pages_read_user_content', 'status' => 'granted'],
            ['permission' => 'pages_manage_engagement', 'status' => 'granted'],
            ['permission' => 'pages_manage_metadata', 'status' => 'granted'],
        ]]),
        "{$graphApi}/me?*" => Http::response(['id' => 'facebook_user_123', 'name' => 'User']),
        "{$graphApi}/me/businesses*" => Http::response(['data' => []]),
        "{$graphApi}/me/accounts*" => Http::response(['data' => [[
            'id' => 'page_123',
            'name' => 'My Facebook Page',
            'username' => 'myfbpage',
            'picture' => ['data' => ['url' => null]],
            'access_token' => 'page-access-token',
        ]]]),
        '*' => Http::response(['success' => true]),
    ]);

    $this->actingAs($this->user)->get(route('app.social.facebook.callback'))
        ->assertRedirect(route('app.social.connect.show', Platform::Facebook));
    finishSocialConnect(Platform::Facebook)->assertRedirect();

    $account = SocialAccount::query()->where('platform_user_id', 'page_123')->firstOrFail();

    expect($account->scopes)->toContain('pages_manage_engagement')
        ->and($account->hasInboxAccess())->toBeTrue();
});

test('a declined inbox scope does not refuse the connection', function () {
    config()->set('trypost.inbox.enabled', true);
    Queue::fake();
    startSocialConnect($this->workspace->id, Platform::Facebook);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('facebook_user_123');
    $socialiteUser->token = 'test-user-token';

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn(Mockery::mock()->shouldReceive('usingGraphVersion')->andReturnSelf()->shouldReceive('user')->andReturn($socialiteUser)->getMock());

    $graphApi = config('trypost.platforms.facebook.graph_api');

    Http::fake([
        "{$graphApi}/me/permissions*" => Http::response(['data' => [
            ['permission' => 'pages_manage_posts', 'status' => 'granted'],
            ['permission' => 'pages_manage_engagement', 'status' => 'declined'],
        ]]),
        "{$graphApi}/me?*" => Http::response(['id' => 'facebook_user_123', 'name' => 'User']),
        "{$graphApi}/me/businesses*" => Http::response(['data' => []]),
        "{$graphApi}/me/accounts*" => Http::response(['data' => [[
            'id' => 'page_123',
            'name' => 'My Facebook Page',
            'username' => 'myfbpage',
            'picture' => ['data' => ['url' => null]],
            'access_token' => 'page-access-token',
        ]]]),
    ]);

    $this->actingAs($this->user)->get(route('app.social.facebook.callback'))
        ->assertRedirect(route('app.social.connect.show', Platform::Facebook));
    finishSocialConnect(Platform::Facebook)->assertRedirect();

    $account = SocialAccount::query()->where('platform_user_id', 'page_123')->firstOrFail();

    expect($account->scopes)->not->toContain('pages_manage_engagement')
        ->and($account->hasInboxAccess())->toBeFalse();
});

test('a channel connected before the network was turned on shows the reconnect banner', function (bool $withInboxScopes) {
    config()->set('trypost.inbox.enabled', true);
    Queue::fake();
    $factory = SocialAccount::factory()->facebook();
    ($withInboxScopes ? $factory->withInboxScopes() : $factory)->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->workspace->owner)->get(route('app.inbox.index'))
        ->assertInertia(fn ($page) => $page->where('inboxChannels.0.has_inbox_access', $withInboxScopes));
})->with([
    'publish scopes only' => [false],
    'inbox scopes granted' => [true],
]);
