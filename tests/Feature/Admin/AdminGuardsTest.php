<?php

declare(strict_types=1);

use App\Http\Middleware\Admin\EnsureCanAccessAdmin;
use App\Http\Middleware\Admin\EnsureAdminEnabled;
use App\Models\Activity;
use App\Models\User;
use App\Support\Admin\Impersonation;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    $this->admin = admin();
});

test('every admin route needs a signed-in admin on Cloud', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => str_starts_with((string) $route->getName(), 'admin.'));

    expect($routes)->not->toBeEmpty();

    $routes->each(fn (RoutingRoute $route) => expect($route->gatherMiddleware())
        ->toContain('auth', EnsureAdminEnabled::class, EnsureCanAccessAdmin::class));
});

test('a user who is not an admin cannot block, unblock or view the app as someone', function () {
    $someone = User::factory()->create();
    $blocked = User::factory()->blocked()->create();
    $target = workspaceOwner();

    $this->actingAs($someone)
        ->post(route('admin.users.block.store', $target), ['reason' => 'Spam'])
        ->assertForbidden();
    $this->actingAs($someone)
        ->delete(route('admin.users.block.destroy', $blocked))
        ->assertForbidden();
    $this->actingAs($someone)
        ->post(route('admin.users.impersonation.store', $target))
        ->assertForbidden();

    $this->assertAuthenticatedAs($someone);
    expect($target->fresh()->isBlocked())->toBeFalse()
        ->and($blocked->fresh()->isBlocked())->toBeTrue()
        ->and(session()->has(Impersonation::SESSION_KEY))->toBeFalse()
        ->and(Activity::query()->exists())->toBeFalse();
});

test('a blocked user cannot use the MCP server', function () {
    $user = workspaceOwner();
    $token = mcpBearerToken($user, $user->currentWorkspace)['plain_token'];
    $user->forceFill(['blocked_at' => now(), 'blocked_reason' => 'Spam'])->save();

    $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson(route('mcp.trypost'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertForbidden()
        ->assertJsonPath('message', __('auth.blocked'));
});

test('a blocked user signing in with google or github is signed out on the next request', function (string $driver, string $callback) {
    config([
        'trypost.google_auth_enabled' => true,
        'trypost.github_auth_enabled' => true,
        'services.google-auth.client_id' => 'test-client-id',
        'services.google-auth.client_secret' => 'test-client-secret',
        'services.google-auth.redirect' => 'https://app.trypost.test/auth/google/callback',
    ]);
    $user = workspaceOwner(['email' => 'blocked@example.com']);
    $user->forceFill(['blocked_at' => now(), 'blocked_reason' => 'Spam'])->save();

    $socialiteUser = new SocialiteUser;
    $socialiteUser->map(['id' => 'blocked-oauth', 'name' => 'Blocked', 'email' => 'blocked@example.com']);
    Socialite::shouldReceive('driver')->with($driver)->andReturn($socialiteDriver = Mockery::mock());
    $socialiteDriver->shouldReceive('user')->andReturn($socialiteUser);

    $this->get(route($callback));
    $this->assertAuthenticatedAs($user);

    $this->get(route('app.calendar'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => __('auth.blocked')]);

    $this->assertGuest();
})->with([
    'google' => ['google-auth', 'auth.google.callback'],
    'github' => ['github', 'auth.github.callback'],
]);

test('stopping after the admin account was deleted signs the person out instead of leaving them in as the customer', function () {
    $user = workspaceOwner();
    $adminId = $this->admin->id;
    $this->admin->delete();

    $this->actingAs($user)
        ->withSession([Impersonation::SESSION_KEY => $adminId])
        ->delete(route('app.impersonation.destroy'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
    expect(session()->has(Impersonation::SESSION_KEY))->toBeFalse()
        ->and(Activity::query()->exists())->toBeFalse();
});
