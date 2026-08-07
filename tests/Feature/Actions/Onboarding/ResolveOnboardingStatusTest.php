<?php

declare(strict_types=1);

use App\Actions\Onboarding\ResolveOnboardingStatus;
use App\Enums\PostHog\OnboardingEvent;
use App\Enums\SocialAccount\Status;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

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

test('resolves the empty onboarding state', function () {
    $status = app(ResolveOnboardingStatus::class)->handle($this->user);

    expect($status)->toBe([
        'mcp_connected' => false,
        'social_connected' => false,
        'first_post_created' => false,
        'skipped_steps' => [],
        'all_complete' => false,
        'show_residual' => true,
        'completed_at' => null,
        'dismissed_at' => null,
    ]);
});

test('resolves an OAuth token as MCP connected', function () {
    mcpAccessToken($this->user, mcpOauthClient());

    $status = app(ResolveOnboardingStatus::class)->handle($this->user);

    expect($status)->toMatchArray([
        'mcp_connected' => true,
        'social_connected' => false,
        'first_post_created' => false,
        'all_complete' => false,
    ]);
});

test('does not resolve a personal access token as MCP connected', function () {
    $this->user->createToken('Personal Access Token');

    $status = app(ResolveOnboardingStatus::class)->handle($this->user);

    expect($status['mcp_connected'])->toBeFalse();
});

test('does not resolve a workspace-bound personal access token as MCP connected', function () {
    $result = $this->user->createToken('Personal Access Token');
    AccessToken::find($result->token->id)
        ->forceFill(['workspace_id' => $this->workspace->id])
        ->saveQuietly();

    $status = app(ResolveOnboardingStatus::class)->handle($this->user);

    expect($status['mcp_connected'])->toBeFalse();
});

test('does not resolve a revoked token as MCP connected', function () {
    $token = mcpAccessToken($this->user, mcpOauthClient());
    $token->forceFill(['revoked' => true])->saveQuietly();

    $status = app(ResolveOnboardingStatus::class)->handle($this->user);

    expect($status['mcp_connected'])->toBeFalse();
});

test('resolves a social account in the current workspace as connected', function () {
    SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);

    $status = app(ResolveOnboardingStatus::class)->handle($this->user);

    expect($status)->toMatchArray([
        'mcp_connected' => false,
        'social_connected' => true,
        'first_post_created' => false,
        'all_complete' => false,
    ]);
});

test('captures each completed step once without re-firing later', function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test']);
    Bus::fake();

    SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);

    app(ResolveOnboardingStatus::class)->syncProgress($this->user);
    app(ResolveOnboardingStatus::class)->syncProgress($this->user->fresh());

    Bus::assertDispatchedTimes(SendEvent::class, 1);
    Bus::assertDispatched(SendEvent::class, fn (SendEvent $event): bool => $event->method === 'capture'
        && data_get($event->payload, 'event') === OnboardingEvent::StepCompleted->value
        && data_get($event->payload, 'properties.step') === 'social');
});

test('does not resolve an expired oauth token as mcp connected', function () {
    $token = AccessToken::withoutEvents(fn () => mcpAccessToken($this->user, mcpOauthClient()));
    $token->forceFill(['expires_at' => now()->subMinute()])->saveQuietly();

    $status = app(ResolveOnboardingStatus::class)->handle($this->user);

    expect($status['mcp_connected'])->toBeFalse();
});

test('requires an effective workspace for the mcp step', function () {
    $this->user->update(['current_workspace_id' => null]);
    SocialAccount::withoutEvents(fn () => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]));
    Post::withoutEvents(fn () => Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));
    AccessToken::withoutEvents(fn () => mcpAccessToken($this->user, mcpOauthClient()));

    $status = app(ResolveOnboardingStatus::class)->handle($this->user->fresh());

    expect($status)->toMatchArray([
        'mcp_connected' => false,
        'social_connected' => true,
        'first_post_created' => true,
        'all_complete' => false,
    ]);
});

test('members do not see the residual checklist', function () {
    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    $member->update(['current_workspace_id' => $this->workspace->id]);

    expect(app(ResolveOnboardingStatus::class)->residual($member->fresh()))->toBeFalse()
        ->and(app(ResolveOnboardingStatus::class)->handle($member->fresh())['show_residual'])->toBeFalse();
});

test('generic trial without card still shows residual for the owner', function () {
    config(['trypost.billing.require_card_for_trial' => false]);
    $this->user->account->subscriptions()->delete();
    $this->user->account->update(['trial_ends_at' => now()->addDays(7)]);

    expect(app(ResolveOnboardingStatus::class)->residual($this->user->fresh()))->toBe([
        'completed' => 0,
        'total' => ResolveOnboardingStatus::TOTAL_STEPS,
    ]);
});

test('resolves a social account in another workspace as connected', function () {
    $otherWorkspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    SocialAccount::factory()->create(['workspace_id' => $otherWorkspace->id]);

    $status = app(ResolveOnboardingStatus::class)->handle($this->user);

    expect($status['social_connected'])->toBeTrue();
});

test('does not resolve an unusable social account as connected', function (Status $status) {
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'status' => $status,
    ]);

    $onboarding = app(ResolveOnboardingStatus::class)->handle($this->user);

    expect($onboarding['social_connected'])->toBeFalse();
})->with([
    'disconnected' => Status::Disconnected,
    'token expired' => Status::TokenExpired,
]);

test('resolves any post in the current workspace as the first post', function () {
    Post::factory()->failed()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $status = app(ResolveOnboardingStatus::class)->handle($this->user);

    expect($status)->toMatchArray([
        'mcp_connected' => false,
        'social_connected' => false,
        'first_post_created' => true,
        'all_complete' => false,
    ]);
});

test('resolves a post in another workspace as the first post', function () {
    $otherWorkspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    Post::factory()->create([
        'workspace_id' => $otherWorkspace->id,
        'user_id' => $this->user->id,
    ]);

    $status = app(ResolveOnboardingStatus::class)->handle($this->user);

    expect($status['first_post_created'])->toBeTrue();
});

test('marks onboarding completed once all three steps are complete', function () {
    Carbon::setTestNow('2026-07-24 12:00:00');

    mcpAccessToken($this->user, mcpOauthClient());
    SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);
    Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $status = app(ResolveOnboardingStatus::class)->syncProgress($this->user);

    expect($status)->toBe([
        'mcp_connected' => true,
        'social_connected' => true,
        'first_post_created' => true,
        'skipped_steps' => [],
        'all_complete' => true,
        'show_residual' => false,
        'completed_at' => now()->toIso8601String(),
        'dismissed_at' => null,
    ])->and($this->user->account->fresh()->onboarding_completed_at?->equalTo(now()))->toBeTrue();

    Carbon::setTestNow(now()->addHour());
    app(ResolveOnboardingStatus::class)->syncProgress($this->user->fresh());

    expect($this->user->account->fresh()->onboarding_completed_at?->toIso8601String())
        ->toBe('2026-07-24T12:00:00+00:00');
});

test('handle does not mutate the account when every step is complete', function () {
    AccessToken::withoutEvents(fn () => mcpAccessToken($this->user, mcpOauthClient()));
    SocialAccount::withoutEvents(fn () => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]));
    Post::withoutEvents(fn () => Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));

    $status = app(ResolveOnboardingStatus::class)->handle($this->user);

    expect($status)->toMatchArray([
        'all_complete' => true,
        'show_residual' => false,
        'completed_at' => null,
    ])->and($this->user->account->fresh()->onboarding_completed_at)->toBeNull();
});

test('stamps completion when the last step completes off the onboarding page', function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test']);
    Carbon::setTestNow('2026-07-24 12:00:00');
    Bus::fake();

    mcpAccessToken($this->user, mcpOauthClient());
    SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);

    expect($this->user->account->fresh()->onboarding_completed_at)->toBeNull();

    Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    expect($this->user->account->fresh()->onboarding_completed_at?->equalTo(now()))->toBeTrue();

    Bus::assertDispatched(SendEvent::class, fn (SendEvent $event): bool => data_get($event->payload, 'event') === OnboardingEvent::Completed->value);
});

test('resolves a teammate oauth token as mcp connected for the account', function () {
    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $this->workspace->id]);
    AccessToken::withoutEvents(fn () => mcpAccessToken($member, mcpOauthClient()));

    $status = app(ResolveOnboardingStatus::class)->handle($this->user);

    expect($status['mcp_connected'])->toBeTrue();
});

test('does not resolve an unscoped oauth token as mcp connected', function () {
    AccessToken::withoutEvents(
        fn () => mcpAccessToken($this->user, mcpOauthClient(), scopes: []),
    );

    $status = app(ResolveOnboardingStatus::class)->handle($this->user);

    expect($status['mcp_connected'])->toBeFalse();
});

test('does not resolve a viewer oauth token as mcp connected', function () {
    $viewer = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($viewer->id, ['role' => Role::Viewer->value]);
    $viewer->update(['current_workspace_id' => $this->workspace->id]);
    AccessToken::withoutEvents(fn () => mcpAccessToken($viewer, mcpOauthClient()));

    $status = app(ResolveOnboardingStatus::class)->handle($this->user);

    expect($status['mcp_connected'])->toBeFalse();
});

test('dismissed onboarding does not show the residual checklist', function () {
    Carbon::setTestNow('2026-07-24 12:00:00');
    $this->user->account->forceFill(['onboarding_dismissed_at' => now()])->save();

    $status = app(ResolveOnboardingStatus::class)->handle($this->user->fresh());

    expect($status['show_residual'])->toBeFalse()
        ->and($status['dismissed_at'])->toBe(now()->toIso8601String());
});

test('completed onboarding returns immediately without resolving steps or capturing analytics', function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test']);
    Carbon::setTestNow('2026-07-24 12:00:00');
    Bus::fake();
    $this->user->account->forceFill(['onboarding_completed_at' => now()])->save();

    $status = app(ResolveOnboardingStatus::class)->syncProgress($this->user->fresh());

    expect($status)->toBe([
        'mcp_connected' => true,
        'social_connected' => true,
        'first_post_created' => true,
        'skipped_steps' => [],
        'all_complete' => true,
        'show_residual' => false,
        'completed_at' => now()->toIso8601String(),
        'dismissed_at' => null,
    ]);
    Bus::assertNothingDispatched();
});

test('self-hosted onboarding does not show the residual checklist', function () {
    config(['trypost.self_hosted' => true]);

    $status = app(ResolveOnboardingStatus::class)->handle($this->user);

    expect($status['show_residual'])->toBeFalse();
});

test('unsubscribed account does not show the residual checklist', function () {
    $this->user->account->subscriptions()->delete();

    $status = app(ResolveOnboardingStatus::class)->handle($this->user);

    expect($status['show_residual'])->toBeFalse();
});

test('residual returns progress counts while onboarding is active', function () {
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    expect(app(ResolveOnboardingStatus::class)->residual($this->user))->toBe([
        'completed' => 1,
        'total' => ResolveOnboardingStatus::TOTAL_STEPS,
    ]);
});

test('checklist and residual both count social and posts from any workspace', function () {
    AccessToken::withoutEvents(fn () => mcpAccessToken($this->user, mcpOauthClient()));

    $otherWorkspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    SocialAccount::withoutEvents(fn () => SocialAccount::factory()->create([
        'workspace_id' => $otherWorkspace->id,
    ]));

    $emptyWorkspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->user->update(['current_workspace_id' => $emptyWorkspace->id]);

    expect(app(ResolveOnboardingStatus::class)->handle($this->user->fresh()))->toMatchArray([
        'mcp_connected' => true,
        'social_connected' => true,
        'first_post_created' => false,
        'all_complete' => false,
        'show_residual' => true,
    ])->and(app(ResolveOnboardingStatus::class)->residual($this->user->fresh()))->toBe([
        'completed' => 2,
        'total' => ResolveOnboardingStatus::TOTAL_STEPS,
    ]);
});

test('residual returns false when the banner should not show', function () {
    $this->user->account->forceFill(['onboarding_dismissed_at' => now()])->save();

    expect(app(ResolveOnboardingStatus::class)->residual($this->user->fresh()))->toBeFalse();
});

test('syncProgress stamps when a teammate is on an empty workspace but account activation is done', function () {
    Carbon::setTestNow('2026-07-24 12:00:00');

    SocialAccount::withoutEvents(fn () => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]));
    Post::withoutEvents(fn () => Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));

    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    $memberWorkspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $member->id,
    ]);
    $memberWorkspace->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $memberWorkspace->id]);

    expect(app(ResolveOnboardingStatus::class)->syncProgress($member->fresh())['all_complete'])->toBeFalse();

    AccessToken::withoutEvents(fn () => mcpAccessToken($member, mcpOauthClient()));

    expect(app(ResolveOnboardingStatus::class)->syncProgress($member->fresh())['all_complete'])->toBeTrue()
        ->and($this->user->account->fresh()->onboarding_completed_at?->equalTo(now()))->toBeTrue();
});

test('mcp connection attributes step analytics to the acting teammate', function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test']);
    Bus::fake();
    Cache::flush();

    SocialAccount::withoutEvents(fn () => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]));
    Post::withoutEvents(fn () => Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));

    $member = User::factory()->create([
        'account_id' => $this->user->account_id,
        'current_workspace_id' => $this->workspace->id,
    ]);
    $this->workspace->members()->attach($member->id, ['role' => Role::Member->value]);

    // Owner has a lower id and would win if we still fan-out by users.id asc.
    expect($this->user->id < $member->id)->toBeTrue();

    mcpAccessToken($member, mcpOauthClient());

    Bus::assertDispatched(
        SendEvent::class,
        fn (SendEvent $event): bool => $event->method === 'capture'
            && data_get($event->payload, 'event') === OnboardingEvent::StepCompleted->value
            && data_get($event->payload, 'properties.step') === 'mcp'
            && data_get($event->payload, 'distinctId') === (string) $member->id,
    );
});

test('markCompleted leaves the in-memory account clean', function () {
    Carbon::setTestNow('2026-07-24 12:00:00');
    Event::fake([OnboardingStatusUpdated::class]);

    expect(app(ResolveOnboardingStatus::class)->markCompleted($this->user))->toBeTrue()
        ->and($this->user->account->isDirty())->toBeFalse()
        ->and($this->user->account->onboarding_completed_at?->equalTo(now()))->toBeTrue();

    Event::assertDispatched(
        OnboardingStatusUpdated::class,
        fn (OnboardingStatusUpdated $event): bool => $event->workspaceId === $this->workspace->id,
    );
});

test('markCompleted refuses to stamp after onboarding was dismissed', function () {
    $this->user->account->forceFill(['onboarding_dismissed_at' => now()])->save();

    expect(app(ResolveOnboardingStatus::class)->markCompleted($this->user->fresh()))->toBeFalse()
        ->and($this->user->account->fresh()->onboarding_completed_at)->toBeNull();
});

test('syncProgress stamps when another workspace already finished social and post', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');

    SocialAccount::withoutEvents(fn () => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]));
    Post::withoutEvents(fn () => Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));
    AccessToken::withoutEvents(fn () => mcpAccessToken($this->user, mcpOauthClient()));

    $emptyWorkspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->user->update(['current_workspace_id' => $emptyWorkspace->id]);

    $status = app(ResolveOnboardingStatus::class)->syncProgress($this->user->fresh());

    expect($status['all_complete'])->toBeTrue()
        ->and($status['show_residual'])->toBeFalse()
        ->and($this->user->account->fresh()->onboarding_completed_at?->equalTo(now()))->toBeTrue();
});

test('residual hides without writing when another workspace already finished activation', function () {
    SocialAccount::withoutEvents(fn () => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]));
    Post::withoutEvents(fn () => Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));
    AccessToken::withoutEvents(fn () => mcpAccessToken($this->user, mcpOauthClient()));

    $emptyWorkspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->user->update(['current_workspace_id' => $emptyWorkspace->id]);

    expect(app(ResolveOnboardingStatus::class)->residual($this->user->fresh()))->toBeFalse()
        ->and($this->user->account->fresh()->onboarding_completed_at)->toBeNull();
});

test('residual does not query step state for dismissed accounts', function () {
    $this->user->account->forceFill(['onboarding_dismissed_at' => now()])->save();

    $queries = [];
    DB::listen(fn ($query) => $queries[] = $query->sql);

    expect(app(ResolveOnboardingStatus::class)->residual($this->user->fresh()))->toBeFalse();

    expect(collect($queries)->filter(fn (string $sql): bool => str_contains($sql, 'oauth_access_tokens')
        || str_contains($sql, 'social_accounts')
        || str_contains($sql, 'posts')))->toBeEmpty();
});

test('residual does not query step state for members', function () {
    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    $member->update(['current_workspace_id' => $this->workspace->id]);

    $queries = [];
    DB::listen(fn ($query) => $queries[] = $query->sql);

    expect(app(ResolveOnboardingStatus::class)->residual($member->fresh()))->toBeFalse();

    expect(collect($queries)->filter(fn (string $sql): bool => str_contains($sql, 'oauth_access_tokens')
        || str_contains($sql, 'social_accounts')
        || str_contains($sql, 'posts')))->toBeEmpty();
});

test('residual does not query step state in self hosted mode', function () {
    config(['trypost.self_hosted' => true]);

    $queries = [];
    DB::listen(fn ($query) => $queries[] = $query->sql);

    expect(app(ResolveOnboardingStatus::class)->residual($this->user->fresh()))->toBeFalse();

    expect(collect($queries)->filter(fn (string $sql): bool => str_contains($sql, 'oauth_access_tokens')
        || str_contains($sql, 'social_accounts')
        || str_contains($sql, 'posts')))->toBeEmpty();
});

test('skipMcp marks the optional MCP step as skipped without completing the checklist', function () {
    Carbon::setTestNow('2026-07-24 12:00:00');

    expect(app(ResolveOnboardingStatus::class)->skipMcp($this->user))->toBeTrue();

    $status = app(ResolveOnboardingStatus::class)->handle($this->user->fresh());

    expect($status['skipped_steps'])->toBe(['mcp'])
        ->and($status['mcp_connected'])->toBeFalse()
        ->and($status['all_complete'])->toBeFalse()
        ->and($this->user->account->fresh()->onboarding_completed_at)->toBeNull();
});

test('skipMcp completes the checklist when MCP was the last open step', function () {
    Carbon::setTestNow('2026-07-24 12:00:00');

    SocialAccount::withoutEvents(fn () => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]));
    Post::withoutEvents(fn () => Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));

    expect(app(ResolveOnboardingStatus::class)->skipMcp($this->user))->toBeTrue();

    expect($this->user->account->fresh()->onboarding_completed_at?->equalTo(now()))->toBeTrue();

    // Ready UI must keep the Skipped badge — do not force mcp_connected=true.
    $status = app(ResolveOnboardingStatus::class)->handle($this->user->fresh());

    expect($status['mcp_connected'])->toBeFalse()
        ->and($status['skipped_steps'])->toBe(['mcp'])
        ->and($status['all_complete'])->toBeTrue();
});

test('skipMcp refuses when MCP is already connected or already skipped', function () {
    mcpAccessToken($this->user, mcpOauthClient());

    expect(app(ResolveOnboardingStatus::class)->skipMcp($this->user->fresh()))->toBeFalse();

    expect($this->user->account->fresh()->onboarding_skipped_steps)->toBeNull();
});

test('residual counts a skipped step as done', function () {
    SocialAccount::withoutEvents(fn () => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]));

    app(ResolveOnboardingStatus::class)->skipMcp($this->user);

    expect(app(ResolveOnboardingStatus::class)->residual($this->user->fresh()))->toBe([
        'completed' => 2,
        'total' => ResolveOnboardingStatus::TOTAL_STEPS,
    ]);
});

test('connecting the mcp step after skipping it prefers Complete over Skipped', function () {
    app(ResolveOnboardingStatus::class)->skipMcp($this->user);

    mcpAccessToken($this->user, mcpOauthClient());

    $status = app(ResolveOnboardingStatus::class)->handle($this->user->fresh());

    expect($status['mcp_connected'])->toBeTrue()
        ->and($status['skipped_steps'])->toBe(['mcp']);

    // Mirrors Index.vue: skipped prop is only true when not actually connected.
    $showSkippedBadge = in_array('mcp', $status['skipped_steps'], true)
        && ! $status['mcp_connected'];

    expect($showSkippedBadge)->toBeFalse();
});

test('connecting mcp after a skipped step completed onboarding replaces the skipped status', function () {
    SocialAccount::withoutEvents(fn () => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]));
    Post::withoutEvents(fn () => Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));

    expect(app(ResolveOnboardingStatus::class)->skipMcp($this->user))->toBeTrue()
        ->and($this->user->account->fresh()->onboarding_completed_at)->not->toBeNull();

    mcpAccessToken($this->user, mcpOauthClient());

    $status = app(ResolveOnboardingStatus::class)->handle($this->user->fresh());

    expect($status['mcp_connected'])->toBeTrue()
        ->and($status['skipped_steps'])->toBe([]);
});
