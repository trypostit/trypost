<?php

declare(strict_types=1);

use App\Actions\Admin\RecordAdminAction;
use App\Enums\Admin\AdminActionType;
use App\Enums\Changelog\Reaction;
use App\Enums\SocialAccount\GatedFeature;
use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Enums\User\Locale;
use App\Enums\User\PreviousSoftware;
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
use Stripe\HttpClient\ClientInterface;

beforeEach(function () {
    $this->admin = admin(['name' => 'Admin']);
    $this->user = workspaceOwner(['name' => 'Grace', 'utm_source' => 'google', 'google_id' => 'g-1'], ['name' => 'Grace Co']);
    $this->workspace = $this->user->currentWorkspace;
});

afterEach(function () {
    ApiRequestor::setHttpClient(null);
});

test('the overview shows how the user signs in, came in and was last seen', function () {
    $this->freezeSecond();
    config(['session.driver' => 'database']);
    DB::table('sessions')->insert(['id' => 'sess-1', 'user_id' => $this->user->id, 'ip_address' => '203.0.113.7', 'user_agent' => 'Safari', 'payload' => '', 'last_activity' => now()->timestamp]);

    $this->actingAs($this->admin)
        ->get(route('admin.users.show', $this->user))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tab', 'overview')
            ->where('user.name', 'Grace')
            ->where('data.sign_in.google', true)
            ->where('data.sign_in.github', false)
            ->where('data.attribution.utm_source', 'google')
            ->where('data.last_seen.ip', '203.0.113.7')
            ->where('data.last_seen.at', now()->startOfSecond()->toIso8601String()),
        );
});

test('the overview names the tool the user used before and keeps their language and zone with their preferences', function () {
    $this->user->update(['previous_software' => PreviousSoftware::SproutSocial, 'locale' => Locale::PortugueseBrazil, 'timezone' => 'America/Sao_Paulo']);

    $this->actingAs($this->admin)
        ->get(route('admin.users.show', $this->user))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('data.welcome.previous_software', PreviousSoftware::SproutSocial->label())
            ->where('data.preferences.locale', Locale::PortugueseBrazil->value)
            ->where('data.preferences.timezone', 'America/Sao_Paulo')
            ->missing('data.signup.locale')
            ->missing('data.signup.timezone'),
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
    subscribeAccount($this->user->account, ['type' => 'legacy', 'stripe_id' => 'sub_old', 'stripe_status' => 'canceled', 'stripe_price' => 'price_old', 'ends_at' => now()->subMonth()]);
    fakeStripeBilling($this->user->account, coupon: ['name' => 'Black Friday', 'percent_off' => 50, 'duration' => 'once'], cardBrand: 'visa', paidInvoices: 2);
    $this->user->account->subscription()->update(['ends_at' => now()->addWeek()]);

    $this->actingAs($this->admin)
        ->get(route('admin.users.show', ['user' => $this->user, 'tab' => 'billing']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('data.subscription.status', 'active')
            ->whereNot('data.subscription.cancels_at', null)
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
    config(['session.driver' => 'database']);
    DB::table('sessions')->insert(['id' => 'sess-2', 'user_id' => $this->user->id, 'ip_address' => '198.51.100.2', 'user_agent' => 'Chrome', 'payload' => '', 'last_activity' => now()->subHours(2)->timestamp]);

    $this->actingAs($this->admin)
        ->get(route('admin.users.show', ['user' => $this->user, 'tab' => 'activity']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('data.history.0.event', 'user.blocked')
            ->where('data.history.0.causer_name', 'Admin')
            ->where('data.sessions.0.ip', '198.51.100.2')
            ->where('data.sessions.0.id', hash('sha256', 'sess-2'))
            ->where('data.sessions.0.last_activity', now()->subHours(2)->toIso8601String())
            ->missing('data.sessions.0.last_active'),
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

test('the workspaces tab marks a workspace the user only belongs to and leaves accepted invitations out', function () {
    $other = workspaceOwner(['name' => 'Ada'], ['name' => 'Ada Co']);
    $other->currentWorkspace->members()->attach($this->user->id, membershipPivot('member'));
    Invite::factory()->create(['account_id' => $this->user->account_id, 'email' => 'pending@example.test']);
    Invite::factory()->create(['account_id' => $this->user->account_id, 'email' => 'joined@example.test', 'accepted_at' => now()]);

    $this->actingAs($this->admin)
        ->get(route('admin.users.show', ['user' => $this->user, 'tab' => 'workspaces']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('data.workspaces.0.name', 'Ada Co')
            ->where('data.workspaces.0.is_owner', false)
            ->where('data.workspaces.1.name', 'Grace Co')
            ->where('data.workspaces.1.is_owner', true)
            ->has('data.pending_invites', 1)
            ->where('data.pending_invites.0.email', 'pending@example.test'),
        );
});

test('the billing tab of an account without a current subscription reads the saved cards and the discount of its other live subscription', function () {
    config(['cashier.secret' => 'sk_test_fake']);
    $this->user->account->forceFill(['stripe_id' => 'cus_former'])->save();
    subscribeAccount($this->user->account, ['type' => 'legacy', 'stripe_id' => 'sub_legacy']);
    ApiRequestor::setHttpClient(new class implements ClientInterface
    {
        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
        {
            $body = match (true) {
                str_contains($absUrl, '/v1/subscriptions/sub_legacy') => ['id' => 'sub_legacy', 'object' => 'subscription', 'discounts' => [[
                    'id' => 'di_legacy',
                    'object' => 'discount',
                    'coupon' => ['id' => 'LOYAL', 'object' => 'coupon', 'name' => 'Loyalty', 'percent_off' => 20, 'amount_off' => null, 'duration' => 'forever'],
                    'promotion_code' => null,
                    'start' => now()->subMonth()->timestamp,
                    'end' => null,
                ]]],
                str_contains($absUrl, '/v1/payment_methods'), str_contains($absUrl, '/v1/customers/cus_former/payment_methods') => ['object' => 'list', 'has_more' => false, 'url' => '/v1/payment_methods', 'data' => [[
                    'id' => 'pm_saved',
                    'object' => 'payment_method',
                    'customer' => 'cus_former',
                    'type' => 'card',
                    'card' => ['brand' => 'mastercard', 'last4' => '4444', 'exp_month' => 1, 'exp_year' => 2040],
                ]]],
                default => ['object' => 'list', 'data' => [], 'has_more' => false, 'url' => '/v1/invoices'],
            };

            return [json_encode($body), 200, []];
        }
    });

    $this->actingAs($this->admin)
        ->get(route('admin.users.show', ['user' => $this->user, 'tab' => 'billing']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('data.subscription', null)
            ->where('data.stripe.error', null)
            ->has('data.stripe.cards', 1)
            ->where('data.stripe.cards.0.brand', 'mastercard')
            ->where('data.stripe.cards.0.last4', '4444')
            ->where('data.stripe.cards.0.is_default', false)
            ->where('data.stripe.discount.name', 'Loyalty')
            ->where('data.stripe.discount.percent_off', 20),
        );
});
