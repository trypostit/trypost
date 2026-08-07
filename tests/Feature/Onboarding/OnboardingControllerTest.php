<?php

declare(strict_types=1);

use App\Actions\Onboarding\ResolveOnboardingStatus;
use App\Enums\PostHog\OnboardingEvent;
use App\Enums\SocialAccount\Platform;
use App\Enums\UserWorkspace\Role;
use App\Events\OnboardingStatusUpdated;
use App\Jobs\PostHog\SendEvent;
use App\Models\AccessToken;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    config([
        'trypost.self_hosted' => false,
        'services.posthog.enabled' => true,
        'services.posthog.api_key' => 'phc_test',
    ]);

    Bus::fake();

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->user->refresh();

    subscribeAccount($this->user->account);
});

test('onboarding renders activation status and connection props', function () {
    $socialAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $this->actingAs($this->user)
        ->get(route('app.onboarding'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('onboarding/Index', false)
            ->where('status.mcp_connected', false)
            ->where('status.social_connected', true)
            ->where('status.first_post_created', false)
            ->where('status.all_complete', false)
            ->where('status.show_residual', true)
            ->where('status.completed_at', null)
            ->where('status.dismissed_at', null)
            ->where('mcpUrl', route('mcp.trypost'))
            ->where('canSkipSteps', true)
            ->where('canManageAccounts', true)
            ->where('canCreatePost', true)
            ->missing('mcpClients')
            ->where('samplePrompt', __('onboarding.first_post.sample_prompt'))
            ->has('platforms', collect(Platform::cases())->filter->isConnectable()->count())
            ->where('accounts.0.id', $socialAccount->id)
        );

    Bus::assertDispatched(SendEvent::class, fn (SendEvent $event): bool => $event->method === 'capture'
        && data_get($event->payload, 'distinctId') === $this->user->id
        && data_get($event->payload, 'event') === OnboardingEvent::Viewed->value);
});

test('onboarding flags the social step when only a sibling workspace is connected', function () {
    $otherWorkspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    SocialAccount::factory()->create(['workspace_id' => $otherWorkspace->id]);

    $this->actingAs($this->user)
        ->get(route('app.onboarding'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('status.social_connected', true)
            ->where('accounts', [])
            ->missing('socialConnectedElsewhere')
        );
});

test('onboarding does not flag social elsewhere when the current workspace is connected', function () {
    SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->user)
        ->get(route('app.onboarding'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('status.social_connected', true)
            ->missing('socialConnectedElsewhere')
        );
});

test('onboarding does not capture viewed during a partial reload', function () {
    $response = $this->actingAs($this->user)
        ->get(route('app.onboarding'))
        ->assertOk();

    Bus::fake();

    $response->assertInertia(fn ($page) => $page
        ->reloadOnly(['status', 'accounts', 'onboardingResidual'], fn ($reload) => $reload
            ->has('status')
            ->has('accounts')
        )
    );

    Bus::assertNotDispatched(
        SendEvent::class,
        fn (SendEvent $event): bool => data_get($event->payload, 'event') === OnboardingEvent::Viewed->value,
    );
});

test('a member visit does not capture onboarding viewed', function () {
    Bus::fake();

    $member = User::factory()->create([
        'account_id' => $this->user->account_id,
        'current_workspace_id' => $this->workspace->id,
    ]);
    $this->workspace->members()->attach($member->id, ['role' => Role::Member->value]);

    $this->actingAs($member->fresh())
        ->get(route('app.onboarding'))
        ->assertOk();

    Bus::assertNotDispatched(
        SendEvent::class,
        fn (SendEvent $event): bool => data_get($event->payload, 'event') === OnboardingEvent::Viewed->value,
    );

    $this->actingAs($this->user->fresh())
        ->get(route('app.onboarding'))
        ->assertOk();

    Bus::assertDispatched(
        SendEvent::class,
        fn (SendEvent $event): bool => data_get($event->payload, 'event') === OnboardingEvent::Viewed->value,
    );
});

test('onboarding does not capture viewed when syncProgress stamps completion', function () {
    AccessToken::withoutEvents(fn () => mcpAccessToken($this->user, mcpOauthClient()));
    SocialAccount::withoutEvents(fn () => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]));
    Post::withoutEvents(fn () => Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));

    Bus::fake();

    $this->actingAs($this->user->fresh())
        ->get(route('app.onboarding'))
        ->assertOk();

    Bus::assertNotDispatched(
        SendEvent::class,
        fn (SendEvent $event): bool => data_get($event->payload, 'event') === OnboardingEvent::Viewed->value,
    );
    Bus::assertDispatched(
        SendEvent::class,
        fn (SendEvent $event): bool => data_get($event->payload, 'event') === OnboardingEvent::Completed->value,
    );
});

test('owner can skip the mcp step', function () {
    Carbon::setTestNow('2026-07-24 12:00:00');
    Event::fake([OnboardingStatusUpdated::class]);

    $this->actingAs($this->user)
        ->post(route('app.onboarding.mcp.skip'))
        ->assertRedirect();

    expect($this->user->account->fresh()->onboarding_skipped_steps)->toBe(['mcp']);

    Bus::assertDispatched(SendEvent::class, fn (SendEvent $event): bool => data_get($event->payload, 'event') === OnboardingEvent::StepSkipped->value
        && data_get($event->payload, 'properties.step') === 'mcp');
    Event::assertDispatched(
        OnboardingStatusUpdated::class,
        fn (OnboardingStatusUpdated $event): bool => $event->workspaceId === $this->workspace->id,
    );
});

test('skipping the last open step completes the onboarding', function () {
    Carbon::setTestNow('2026-07-24 12:00:00');

    SocialAccount::withoutEvents(fn () => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]));
    Post::withoutEvents(fn () => Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));

    $this->actingAs($this->user)
        ->post(route('app.onboarding.mcp.skip'))
        ->assertRedirect();

    expect($this->user->account->fresh()->onboarding_completed_at?->equalTo(now()))->toBeTrue();

    Bus::assertDispatched(SendEvent::class, fn (SendEvent $event): bool => data_get($event->payload, 'event') === OnboardingEvent::Completed->value);
});

test('skipping an already connected step is a no-op', function () {
    AccessToken::withoutEvents(fn () => mcpAccessToken($this->user, mcpOauthClient()));

    $this->actingAs($this->user->fresh())
        ->post(route('app.onboarding.mcp.skip'))
        ->assertRedirect();

    expect($this->user->account->fresh()->onboarding_skipped_steps)->toBeNull();
});

test('skipping the same step twice is a no-op', function () {
    $this->actingAs($this->user)
        ->post(route('app.onboarding.mcp.skip'));

    Bus::fake();

    $this->actingAs($this->user->fresh())
        ->post(route('app.onboarding.mcp.skip'))
        ->assertRedirect();

    expect($this->user->account->fresh()->onboarding_skipped_steps)->toBe(['mcp']);

    Bus::assertNotDispatched(
        SendEvent::class,
        fn (SendEvent $event): bool => data_get($event->payload, 'event') === OnboardingEvent::StepSkipped->value,
    );
});

test('dismissed accounts are redirected away from onboarding index', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');
    $this->user->account->forceFill(['onboarding_dismissed_at' => now()])->save();

    Bus::fake();

    $this->actingAs($this->user->fresh())
        ->get(route('app.onboarding'))
        ->assertRedirect(route('app.calendar'));

    Bus::assertNotDispatched(
        SendEvent::class,
        fn (SendEvent $event): bool => data_get($event->payload, 'event') === OnboardingEvent::Viewed->value,
    );
});

test('completed accounts are redirected away from onboarding on full visits', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');
    $this->user->account->forceFill(['onboarding_completed_at' => now()])->save();

    Bus::fake();

    $this->actingAs($this->user->fresh())
        ->get(route('app.onboarding'))
        ->assertRedirect(route('app.calendar'));

    Bus::assertNotDispatched(
        SendEvent::class,
        fn (SendEvent $event): bool => data_get($event->payload, 'event') === OnboardingEvent::Viewed->value,
    );
});

test('partial reloads keep the ready state after completion is stamped', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');

    $response = $this->actingAs($this->user->fresh())
        ->get(route('app.onboarding'))
        ->assertOk();

    AccessToken::withoutEvents(fn () => mcpAccessToken($this->user, mcpOauthClient()));
    SocialAccount::withoutEvents(fn () => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]));
    Post::withoutEvents(fn () => Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));

    expect(app(ResolveOnboardingStatus::class)->markCompleted($this->user->fresh()))->toBeTrue();

    $response->assertInertia(fn ($page) => $page
        ->reloadOnly(['status', 'accounts', 'onboardingResidual'], fn ($reload) => $reload
            ->where('status.all_complete', true)
            ->where('status.completed_at', now()->toIso8601String())
        )
    );

    // Full revisit after completion leaves for the calendar.
    $this->actingAs($this->user->fresh())
        ->get(route('app.onboarding'))
        ->assertRedirect(route('app.calendar'));
});

test('same-request auto-stamp still shows the ready state', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');

    AccessToken::withoutEvents(fn () => mcpAccessToken($this->user, mcpOauthClient()));
    SocialAccount::withoutEvents(fn () => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]));
    Post::withoutEvents(fn () => Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));

    Bus::fake();

    // Steps are done but completed_at is null — syncProgress stamps on this visit
    // and still renders the ready state (wasAlreadyComplete was false).
    $this->actingAs($this->user->fresh())
        ->get(route('app.onboarding'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('onboarding/Index', false)
            ->where('status.all_complete', true)
            ->where('status.completed_at', now()->toIso8601String())
        );

    Bus::assertNotDispatched(
        SendEvent::class,
        fn (SendEvent $event): bool => data_get($event->payload, 'event') === OnboardingEvent::Viewed->value,
    );
});

test('completed accounts stay on onboarding during partial reloads', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');

    AccessToken::withoutEvents(fn () => mcpAccessToken($this->user, mcpOauthClient()));
    SocialAccount::withoutEvents(fn () => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]));
    Post::withoutEvents(fn () => Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));

    $response = $this->actingAs($this->user->fresh())
        ->get(route('app.onboarding'))
        ->assertOk();

    $this->user->account->forceFill(['onboarding_completed_at' => now()])->save();

    $response->assertInertia(fn ($page) => $page
        ->reloadOnly(['status', 'accounts', 'onboardingResidual'], fn ($reload) => $reload
            ->where('status.all_complete', true)
            ->where('status.completed_at', now()->toIso8601String())
        )
    );
});

test('skip step after completion is a no-op', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');
    Event::fake([OnboardingStatusUpdated::class]);

    $this->user->account->forceFill(['onboarding_completed_at' => now()])->save();

    Bus::fake();

    $this->actingAs($this->user->fresh())
        ->post(route('app.onboarding.mcp.skip'))
        ->assertRedirect();

    expect($this->user->account->fresh()->onboarding_skipped_steps)->toBeNull();

    Bus::assertNotDispatched(
        SendEvent::class,
        fn (SendEvent $event): bool => data_get($event->payload, 'event') === OnboardingEvent::StepSkipped->value,
    );
    Event::assertNotDispatched(OnboardingStatusUpdated::class);
});

test('onboarding cannot be completed before every activation step', function () {
    $this->actingAs($this->user)
        ->post(route('app.onboarding.complete'))
        ->assertRedirect(route('app.onboarding'));

    expect($this->user->account->fresh()->onboarding_completed_at)->toBeNull();

    Bus::assertNotDispatched(SendEvent::class, fn (SendEvent $event): bool => data_get($event->payload, 'event') === OnboardingEvent::Completed->value);
});

test('onboarding completes after every activation step', function () {
    Carbon::setTestNow('2026-07-24 12:00:00');
    Event::fake([OnboardingStatusUpdated::class]);

    AccessToken::withoutEvents(fn () => mcpAccessToken($this->user, mcpOauthClient()));
    SocialAccount::withoutEvents(fn () => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]));
    Post::withoutEvents(fn () => Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));

    $this->actingAs($this->user->fresh())
        ->post(route('app.onboarding.complete'))
        ->assertRedirect(route('app.calendar'));

    expect($this->user->account->fresh()->onboarding_completed_at?->equalTo(now()))->toBeTrue();

    Bus::assertDispatched(SendEvent::class, fn (SendEvent $event): bool => data_get($event->payload, 'event') === OnboardingEvent::Completed->value);
    Event::assertDispatched(
        OnboardingStatusUpdated::class,
        fn (OnboardingStatusUpdated $event): bool => $event->workspaceId === $this->workspace->id,
    );
});

test('onboarding complete does not re-fire completed when already stamped', function () {
    Carbon::setTestNow('2026-07-24 12:00:00');

    AccessToken::withoutEvents(fn () => mcpAccessToken($this->user, mcpOauthClient()));
    SocialAccount::withoutEvents(fn () => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]));
    Post::withoutEvents(fn () => Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));
    $this->user->account->forceFill(['onboarding_completed_at' => now()])->save();

    Bus::fake();

    $this->actingAs($this->user->fresh())
        ->post(route('app.onboarding.complete'))
        ->assertRedirect(route('app.calendar'));

    Bus::assertNotDispatched(
        SendEvent::class,
        fn (SendEvent $event): bool => data_get($event->payload, 'event') === OnboardingEvent::Completed->value,
    );
});

test('members do not see the skip control', function () {
    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($member->id, [
        'role' => Role::Member->value,
    ]);
    $member->update(['current_workspace_id' => $this->workspace->id]);

    $this->actingAs($member->fresh())
        ->get(route('app.onboarding'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('canSkipSteps', false)
            ->where('canManageAccounts', false)
            ->where('canCreatePost', true));
});

test('viewers cannot manage social accounts or create posts from onboarding', function () {
    $viewer = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($viewer->id, [
        'role' => Role::Viewer->value,
    ]);
    $viewer->update(['current_workspace_id' => $this->workspace->id]);

    $this->actingAs($viewer->fresh())
        ->get(route('app.onboarding'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('canManageAccounts', false)
            ->where('canCreatePost', false));
});

test('only the account owner can skip onboarding steps', function () {
    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($member->id, [
        'role' => Role::Member->value,
    ]);
    $member->update(['current_workspace_id' => $this->workspace->id]);

    $this->actingAs($member->fresh())
        ->post(route('app.onboarding.mcp.skip'))
        ->assertForbidden();

    expect($this->user->account->fresh()->onboarding_skipped_steps)->toBeNull();
});

test('teammates can stamp completion via the complete endpoint', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');
    Event::fake([OnboardingStatusUpdated::class]);

    AccessToken::withoutEvents(fn () => mcpAccessToken($this->user, mcpOauthClient()));
    SocialAccount::withoutEvents(fn () => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]));
    Post::withoutEvents(fn () => Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));

    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($member->id, [
        'role' => Role::Member->value,
    ]);
    $member->update(['current_workspace_id' => $this->workspace->id]);

    $otherWorkspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);

    $this->actingAs($member->fresh())
        ->post(route('app.onboarding.complete'))
        ->assertRedirect(route('app.calendar'));

    expect($this->user->account->fresh()->onboarding_completed_at?->equalTo(now()))->toBeTrue();

    Event::assertDispatched(
        OnboardingStatusUpdated::class,
        fn (OnboardingStatusUpdated $event): bool => $event->workspaceId === $this->workspace->id,
    );
    Event::assertDispatched(
        OnboardingStatusUpdated::class,
        fn (OnboardingStatusUpdated $event): bool => $event->workspaceId === $otherWorkspace->id,
    );
});

test('complete stamps when activation finished on another workspace', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');
    Event::fake([OnboardingStatusUpdated::class]);

    AccessToken::withoutEvents(fn () => mcpAccessToken($this->user, mcpOauthClient()));
    SocialAccount::withoutEvents(fn () => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]));
    Post::withoutEvents(fn () => Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));

    $emptyWorkspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->user->update(['current_workspace_id' => $emptyWorkspace->id]);

    $this->actingAs($this->user->fresh())
        ->post(route('app.onboarding.complete'))
        ->assertRedirect(route('app.calendar'));

    expect($this->user->account->fresh()->onboarding_completed_at?->equalTo(now()))->toBeTrue();
});

test('complete after dismiss redirects without stamping completion', function () {
    Carbon::setTestNow('2026-07-24 12:00:00');

    AccessToken::withoutEvents(fn () => mcpAccessToken($this->user, mcpOauthClient()));
    SocialAccount::withoutEvents(fn () => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]));
    Post::withoutEvents(fn () => Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));
    $this->user->account->forceFill(['onboarding_dismissed_at' => now()])->save();

    Bus::fake();

    $this->actingAs($this->user->fresh())
        ->post(route('app.onboarding.complete'))
        ->assertRedirect(route('app.calendar'));

    expect($this->user->account->fresh()->onboarding_completed_at)->toBeNull();

    Bus::assertNotDispatched(
        SendEvent::class,
        fn (SendEvent $event): bool => data_get($event->payload, 'event') === OnboardingEvent::Completed->value,
    );
});

test('unsubscribed accounts are redirected to welcome by middleware', function (string $routeName, string $method, array|string $params = []) {
    $this->user->account->subscriptions()->delete();
    $this->actingAs($this->user->fresh());

    $response = $method === 'get'
        ? $this->get(route($routeName, $params))
        : $this->post(route($routeName, $params));

    $response->assertRedirect(route('app.welcome.persona'));
})->with([
    'index' => ['app.onboarding', 'get'],
    'skip step' => ['app.onboarding.mcp.skip', 'post'],
    'complete' => ['app.onboarding.complete', 'post'],
]);

test('self hosted activation endpoints redirect to calendar', function (string $routeName, string $method, array|string $params = []) {
    config(['trypost.self_hosted' => true]);
    $this->actingAs($this->user);

    $response = $method === 'get'
        ? $this->get(route($routeName, $params))
        : $this->post(route($routeName, $params));

    $response->assertRedirect(route('app.calendar'));
})->with([
    'index' => ['app.onboarding', 'get'],
    'skip step' => ['app.onboarding.mcp.skip', 'post'],
    'complete' => ['app.onboarding.complete', 'post'],
]);
