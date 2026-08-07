<?php

declare(strict_types=1);

use App\Actions\Onboarding\ResolveOnboardingStatus;
use App\Enums\UserWorkspace\Role;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config(['trypost.self_hosted' => false]);

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->user->refresh();

    subscribeAccount($this->user->account);
});

test('defers the onboarding checklist progress for mid-activation owners', function () {
    $this->actingAs($this->user)
        ->get(route('app.calendar'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->missing('onboardingProgress')
            ->loadDeferredProps(fn ($page) => $page
                ->where('onboardingProgress.completed', 0)
                ->where('onboardingProgress.total', ResolveOnboardingStatus::totalSteps())
            )
        );
});

test('does not share the onboarding progress state after dismissal', function () {
    $this->user->account->forceFill(['onboarding_dismissed_at' => now()])->save();

    $this->actingAs($this->user->fresh())
        ->get(route('app.calendar'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('onboardingProgress', false));
});

test('does not share the onboarding progress state after completion', function () {
    $this->user->account->forceFill(['onboarding_completed_at' => now()])->save();

    $this->actingAs($this->user->fresh())
        ->get(route('app.calendar'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('onboardingProgress', false));
});

test('does not share the onboarding progress with workspace members', function () {
    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($member->id, [
        'role' => Role::Member->value,
    ]);
    $member->update(['current_workspace_id' => $this->workspace->id]);

    $this->actingAs($member->fresh())
        ->get(route('app.calendar'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('onboardingProgress', false));
});

test('does not share the onboarding progress in self-hosted mode', function () {
    config(['trypost.self_hosted' => true]);

    $this->actingAs($this->user)
        ->get(route('app.calendar'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('onboardingProgress', false));
});

test('does not defer onboarding progress on passport oauth consent', function () {
    $clientId = mcpOauthClient('Consent Share Agent');
    DB::table('oauth_clients')->where('id', $clientId)->update([
        'redirect_uris' => json_encode(['https://client.example/callback']),
    ]);

    $this->actingAs($this->user)
        ->get(route('passport.authorizations.authorize', oauthAuthorizeQuery($clientId)))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('mcp/Authorize')
            // Inline false — never missing/deferred on this URL (see OAuthAuthorizeAuthTokenTest).
            ->where('onboardingProgress', false)
        );
});
