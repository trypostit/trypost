<?php

declare(strict_types=1);

use App\Actions\Admin\RecordAdminAction;
use App\Enums\Admin\AdminActionType;
use App\Enums\Changelog\Reaction;
use App\Enums\SocialAccount\GatedFeature;
use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Models\ChangelogEntry;
use App\Models\ChangelogEntryView;
use App\Models\Invite;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\Webhook;
use App\Models\WorkspaceLabel;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Stripe\ApiRequestor;

beforeEach(function () {
    $this->admin = backofficeAdmin(['name' => 'Admin']);
    $this->user = workspaceOwner(['name' => 'Grace', 'utm_source' => 'google', 'google_id' => 'g-1'], ['name' => 'Grace Co']);
    $this->workspace = $this->user->currentWorkspace;
});

afterEach(function () {
    ApiRequestor::setHttpClient(null);
});

test('the overview shows how the user signs in, came in and was last seen', function () {
    DB::table('sessions')->insert(['id' => 'sess-1', 'user_id' => $this->user->id, 'ip_address' => '203.0.113.7', 'user_agent' => 'Safari', 'payload' => '', 'last_activity' => now()->timestamp]);

    $this->actingAs($this->admin)
        ->get(route('admin.users.show', $this->user))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tab', 'overview')
            ->where('user.name', 'Grace')
            ->where('data.sign_in.google', true)
            ->where('data.sign_in.github', false)
            ->where('data.attribution.utm_source', 'google')
            ->where('data.last_seen.ip', '203.0.113.7'),
        );
});

test('the workspaces tab lists channels, members and pending invites', function () {
    SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedInPage, 'status' => Status::TokenExpired]);
    SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::X]);
    Invite::factory()->create(['account_id' => $this->user->account_id, 'email' => 'new@example.test']);

    $this->actingAs($this->admin)
        ->get(route('admin.users.show', ['user' => $this->user, 'tab' => 'workspaces']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('data.workspaces.0.name', 'Grace Co')
            ->where('data.workspaces.0.is_owner', true)
            ->has('data.workspaces.0.channels', 2)
            ->has('data.workspaces.0.members', 1)
            ->where('data.channels_by_network', ['linkedin' => 1, 'x' => 1])
            ->where('data.pending_invites.0.email', 'new@example.test'),
        );
});

test('the posts tab counts posts by network and status and the workspace content', function () {
    $channel = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::X]);
    Post::factory()->forAccount($channel)->published()->count(2)->create(['user_id' => $this->user->id]);
    Post::factory()->forAccount($channel)->failed()->count(2)->create(['user_id' => $this->user->id]);
    WorkspaceLabel::factory()->count(3)->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->admin)
        ->get(route('admin.users.show', ['user' => $this->user, 'tab' => 'posts']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('data.total', 4)
            ->where('data.by_status.published', 2)
            ->where('data.by_status.failed', 2)
            ->where('data.by_network.0.platform', 'x')
            ->has('data.recent_failures', 2)
            ->where('data.content.labels', 3),
        );
});

test('the integrations tab lists api keys and webhooks', function () {
    $result = createApiTestToken(['user' => $this->user, 'workspace' => $this->workspace]);
    Webhook::factory()->create(['workspace_id' => $this->workspace->id, 'endpoint' => 'https://hooks.example.test/in']);

    $this->actingAs($this->admin)
        ->get(route('admin.users.show', ['user' => $this->user, 'tab' => 'integrations']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('data.api_keys', 1)
            ->where('data.api_keys.0.workspace_name', 'Grace Co')
            ->where('data.webhooks.0.endpoint', 'https://hooks.example.test/in')
            ->has('data.mcp_apps')
            ->has('data.media_sources'),
        );

    expect($result['user']->is($this->user))->toBeTrue();
});

test('the billing tab shows the stored subscription and reads cards and discount from Stripe', function () {
    $this->user->account->subscriptions()->create([
        'type' => 'legacy',
        'stripe_id' => 'sub_old',
        'stripe_status' => 'canceled',
        'stripe_price' => 'price_old',
        'ends_at' => now()->subMonth(),
    ]);
    fakeStripeBilling($this->user->account, coupon: ['name' => 'Black Friday', 'percent_off' => 50, 'duration' => 'once'], cardBrand: 'visa', paidInvoices: 2);
    $this->user->account->subscription()->update(['ends_at' => now()->addWeek()]);

    $this->actingAs($this->admin)
        ->get(route('admin.users.show', ['user' => $this->user, 'tab' => 'billing']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('data.subscription.status', 'active')
            ->whereNot('data.subscription.cancels_at', null)
            ->where('data.subscription.on_grace_period', true)
            ->where('data.stripe.error', null)
            ->where('data.stripe.discount.name', 'Black Friday')
            ->where('data.stripe.discount.percent_off', 50)
            ->where('data.stripe.cards.0.brand', 'visa')
            ->has('data.stripe.used_discounts', 1)
            ->where('data.stripe.used_discounts.0.name', 'Black Friday')
            ->where('data.stripe.used_discounts.0.amount_off', '$18.00')
            ->where('data.account.stripe_url', "https://dashboard.stripe.com/test/customers/{$this->user->account->stripe_id}"),
        );
});

test('the billing tab keeps the stored part and every Stripe key when Stripe fails', function () {
    $this->user->account->forceFill(['stripe_id' => 'cus_down'])->save();
    fakeStripeReads(down: true);

    $this->actingAs($this->admin)
        ->get(route('admin.users.show', ['user' => $this->user, 'tab' => 'billing']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('data.account.stripe_id', 'cus_down')
            ->where('data.stripe.cards', [])
            ->where('data.stripe.discount', null)
            ->where('data.stripe.used_discounts', [])
            ->where('data.stripe.invoices', [])
            ->whereNot('data.stripe.error', null),
        );
});

test('the activity tab shows admin history and sessions', function () {
    app(RecordAdminAction::class)->execute($this->admin, AdminActionType::BlockUser, $this->user, ['reason' => 'Spam']);
    $this->freezeSecond();
    DB::table('sessions')->insert(['id' => 'sess-2', 'user_id' => $this->user->id, 'ip_address' => '198.51.100.2', 'user_agent' => 'Chrome', 'payload' => '', 'last_activity' => now()->subHours(2)->timestamp]);

    $this->actingAs($this->admin)
        ->get(route('admin.users.show', ['user' => $this->user, 'tab' => 'activity']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('data.history.0.event', 'user.blocked')
            ->where('data.history.0.causer_name', 'Admin')
            ->where('data.sessions.0.ip', '198.51.100.2')
            ->where('data.sessions.0.id', hash('sha256', 'sess-2'))
            ->where('data.sessions.0.last_active', '2 hours ago'),
        );
});

test('an unknown tab is refused', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.users.show', ['user' => $this->user, 'tab' => 'secrets']))
        ->assertSessionHasErrors('tab');
});

test('the workspaces tab names the features a channel lacks a scope for', function () {
    gateFeature(Platform::Threads, GatedFeature::ThreadReplies, ['threads_manage_replies']);
    SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Threads, 'scopes' => ['threads_basic', 'threads_content_publish', 'threads_manage_insights'], 'position' => 1]);
    SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Threads, 'scopes' => ['threads_basic', 'threads_content_publish', 'threads_manage_insights', 'threads_manage_replies'], 'position' => 2]);

    $this->actingAs($this->admin)
        ->get(route('admin.users.show', ['user' => $this->user, 'tab' => 'workspaces']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('data.workspaces.0.channels.0.missing_permissions', ['thread_replies'])
            ->where('data.workspaces.0.channels.1.missing_permissions', []),
        );
});

test('the activity tab lists the updates the user opened with their reaction', function () {
    $liked = ChangelogEntry::factory()->create(['title' => 'Polls on X']);
    $opened = ChangelogEntry::factory()->create(['title' => 'Reel covers']);
    ChangelogEntryView::factory()->create(['user_id' => $this->user->id, 'changelog_entry_id' => $opened->id, 'created_at' => now()->subDay()]);
    ChangelogEntryView::factory()->create(['user_id' => $this->user->id, 'changelog_entry_id' => $liked->id, 'reaction' => Reaction::Like]);

    $this->actingAs($this->admin)
        ->get(route('admin.users.show', ['user' => $this->user, 'tab' => 'activity']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('data.changelog.opened', 2)
            ->where('data.changelog.liked', 1)
            ->where('data.changelog.entries.0.entry.title', 'Polls on X')
            ->where('data.changelog.entries.0.entry.reaction', 'like')
            ->where('data.changelog.entries.1.entry.title', 'Reel covers')
            ->where('data.changelog.entries.1.entry.reaction', null),
        );
});
