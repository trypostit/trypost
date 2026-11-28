<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Models\Offer;
use App\Models\Post;
use App\Models\SocialAccount;
use Stripe\ApiRequestor;

beforeEach(function () {
    $this->admin = admin(['name' => 'Admin']);
    $this->owner = workspaceOwner(['name' => 'Grace'], ['name' => 'Grace Co']);
    subscribeAccount($this->owner->account, ['stripe_price' => 'price_socials_monthly', 'created_at' => now()->subDays(40)]);
    $channel = SocialAccount::factory()->create(['workspace_id' => $this->owner->current_workspace_id, 'platform' => Platform::X]);
    channelPostAt($channel, now()->subHour());
    Post::factory()->forAccount($channel)->failed()->create(['user_id' => $this->owner->id]);
    Offer::factory()->create(['slug' => 'spring-sale']);
    fakeStripeReads(['price_socials_monthly' => [1900, 'month']]);
});

afterEach(function () {
    ApiRequestor::setHttpClient(null);
});

test('every admin page opens without a JavaScript error', function (string $route, array $parameters, string $content) {
    $this->actingAs($this->admin);

    $page = visit(route($route, $parameters));

    waitFor($page, "@{$content}");
    $page->assertNoJavaScriptErrors();
})->with([
    'dashboard' => ['admin.dashboard', [], 'admin-dashboard'],
    'users' => ['admin.users.index', [], 'admin-users'],
    'user insights' => ['admin.users.insights.index', [], 'admin-user-insights'],
    'posts' => ['admin.posts.index', [], 'admin-publishing'],
    'failed posts' => ['admin.posts.failed.index', [], 'admin-failed-posts'],
    'networks' => ['admin.networks.index', [], 'admin-networks'],
    'quotas' => ['admin.quotas.index', [], 'admin-quotas'],
    'subscriptions' => ['admin.billing.subscriptions.index', [], 'admin-subscriptions'],
    'signup insights' => ['admin.billing.insights.index', [], 'admin-insights'],
    'offers' => ['admin.billing.offers.index', [], 'admin-offers'],
]);

test('each tab of a user page opens its content', function () {
    $this->actingAs($this->admin);

    $page = visit(route('admin.users.show', $this->owner));
    waitFor($page, '@admin-user-overview');

    foreach (['workspaces', 'posts', 'integrations', 'billing', 'activity', 'overview'] as $tab) {
        $page->click("@admin-user-tab-{$tab}");
        waitFor($page, "@admin-user-{$tab}");
    }

    $page->assertNoJavaScriptErrors();
});

test('the sidebar moves between the admin pages', function () {
    $this->actingAs($this->admin);

    $page = visit(route('admin.dashboard'));
    waitFor($page, '@admin-sidebar');

    $page->click('@admin-nav-networks');
    waitForPath($page, route('admin.networks.index', absolute: false));
    waitFor($page, '@admin-networks');

    $page->click('@admin-nav-offers');
    waitForPath($page, route('admin.billing.offers.index', absolute: false));
    waitFor($page, '@admin-offers');

    $page->assertNoJavaScriptErrors();
});
