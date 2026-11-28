<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use Stripe\ApiRequestor;

afterEach(function () {
    ApiRequestor::setHttpClient(null);
});

test('the users search narrows the list by name or email', function () {
    $admin = admin(['name' => 'Admin']);
    $grace = User::factory()->create(['name' => 'Grace Hopper']);
    $ada = User::factory()->create(['name' => 'Ada Lovelace']);
    $this->actingAs($admin);

    $page = visit(route('admin.users.index'));

    waitFor($page, "@admin-user-{$ada->id}");
    $page->fill('@header-search-input', 'grace');
    waitForCondition($page, "location.search.includes('search=grace')");
    waitForGone($page, "@admin-user-{$ada->id}");
    waitFor($page, "@admin-user-{$grace->id}");
    $page->assertNoJavaScriptErrors();
});

test('the subscriptions list filters by status and searches the owner', function () {
    $this->actingAs(admin());
    $active = subscribeAccount(User::factory()->create(['name' => 'Active Owner'])->account, ['stripe_price' => 'price_socials_monthly']);
    $pastDue = subscribeAccount(User::factory()->create(['name' => 'Late Payer'])->account, ['stripe_price' => 'price_socials_monthly', 'stripe_status' => 'past_due']);
    fakeStripeReads(['price_socials_monthly' => [1900, 'month']]);

    $page = visit(route('admin.billing.subscriptions.index', ['tab' => 'subscriptions']));

    waitFor($page, "@admin-subscription-{$active->id}");
    $page->click('@admin-subscriptions-filter-past_due');
    waitForGone($page, "@admin-subscription-{$active->id}");
    waitFor($page, "@admin-subscription-{$pastDue->id}");

    $page->click('@admin-subscriptions-filter-all');
    waitFor($page, "@admin-subscription-{$active->id}");
    $page->fill('@header-search-input', 'Active Owner');
    waitForGone($page, "@admin-subscription-{$pastDue->id}");
    waitFor($page, "@admin-subscription-{$active->id}");
    $page->assertNoJavaScriptErrors();
});

test('the publishing page follows the period picked', function () {
    $this->actingAs(admin());

    $page = visit(route('admin.posts.index'));

    waitFor($page, '@insights-range-7d');
    $page->click('@insights-range-7d');
    waitForCondition($page, "location.search.includes('range=7d')");
    waitFor($page, '@admin-publishing');
    $page->assertNoJavaScriptErrors();
});

test('the networks table sorts by the column clicked and flips on a second click', function () {
    $this->actingAs(admin());
    SocialAccount::factory()->count(2)->create(['platform' => Platform::X]);
    SocialAccount::factory()->create(['platform' => Platform::LinkedIn]);

    $page = visit(route('admin.networks.index'));

    waitForState($page, '@admin-networks-table th:has(@admin-networks-table-sort-connected)', "element.getAttribute('aria-sort') === 'descending'");

    $page->click('@admin-networks-table-sort-label');
    waitForState($page, '@admin-networks-table th:has(@admin-networks-table-sort-label)', "element.getAttribute('aria-sort') !== 'none'");
    $first = elementsMap($page, '@admin-networks-table th:has(@admin-networks-table-sort-label)', "element.getAttribute('aria-sort')")[0];

    $page->click('@admin-networks-table-sort-label');
    waitForState($page, '@admin-networks-table th:has(@admin-networks-table-sort-label)', "element.getAttribute('aria-sort') !== ".json_encode($first));
    $page->assertNoJavaScriptErrors();
});
