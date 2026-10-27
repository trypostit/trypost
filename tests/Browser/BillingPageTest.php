<?php

declare(strict_types=1);

use App\Enums\Plan\Slug;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use Stripe\ApiRequestor;

afterEach(function () {
    ApiRequestor::setHttpClient(null);
});

function waitForBillingPageTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            const selector = '[data-testid="{$testId}"]';
            for (let attempt = 0; attempt < 200; attempt++) {
                const element = document.querySelector(selector);
                if (element && element.getBoundingClientRect().height > 0) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

function billingPageOwner(): User
{
    $user = User::factory()->create();
    $user->account->update([
        'plan_id' => Plan::query()->where('slug', Slug::Socials)->value('id'),
    ]);

    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    return $user->fresh();
}

test('the billing page shows the current plan and changes plans in a dialog', function () {
    config(['trypost.self_hosted' => false]);

    $this->actingAs(billingPageOwner());

    $page = visit(route('app.billing.index'));
    waitForBillingPageTestId($page, 'billing-current-plan');

    $page->assertSeeIn('@billing-current-plan-name', 'Socials')
        ->assertSeeIn('@billing-current-plan-price', '$19')
        ->assertSeeIn('@billing-current-plan-price', '/month')
        ->assertSeeIn('@billing-current-plan-badge', 'Active')
        ->assertMissing('@billing-current-plan-status')
        ->assertMissing('@billing-current-plan-usage')
        ->assertVisible('@settings-nav-billing')
        ->assertMissing('@settings-nav-account')
        ->assertMissing('@billing-details-form')
        ->assertMissing('@plan-card-socials');

    $page->click('@billing-change-plan');
    waitForBillingPageTestId($page, 'plan-card-workspaces');

    $page->assertVisible('@billing-plan-dialog')
        ->assertVisible('@plan-card-socials')
        ->assertVisible('@plan-card-workspaces')
        ->assertSeeIn('@plan-select-socials', 'Current plan')
        ->assertSeeIn('@plan-select-workspaces', 'Upgrade to Workspaces')
        ->assertSeeIn('@plan-price-socials', '$19');

    $page->click('@plan-interval-yearly');
    waitForBillingPageTestId($page, 'plan-save-two-months');

    $page->assertSeeIn('@plan-price-socials', '$15.83')
        ->assertSeeIn('@plan-select-socials', 'Switch to yearly')
        ->assertVisible('@billing-plan-dialog')
        ->assertRoute('app.billing.index')
        ->assertNoJavaScriptErrors();
});

test('self-hosted settings show neither an account nor a billing item', function () {
    config(['trypost.self_hosted' => true]);

    $this->actingAs(billingPageOwner());

    $page = visit(route('app.profile.edit'));
    waitForBillingPageTestId($page, 'settings-nav-profile');

    $page->assertMissing('@settings-nav-account')
        ->assertMissing('@settings-nav-billing')
        ->assertNoJavaScriptErrors();
});

test('the billing page explains a discount and lists every card, the default first', function () {
    config(['trypost.self_hosted' => false]);

    $user = User::factory()->create();
    $user->account->update(['plan_id' => Plan::query()->where('slug', Slug::Socials)->value('id')]);
    $workspace = Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    fakeStripeBilling(
        $user->account,
        ['percent_off' => 50, 'amount_off' => null, 'currency' => null, 'duration' => 'repeating', 'duration_in_months' => 3],
        now()->addMonths(3)->timestamp,
        'visa',
        secondCardBrand: 'mastercard',
    );
    $this->actingAs($user->fresh());

    $page = visit(route('app.billing.index'));
    waitFor($page, '@billing-card-pm_fake_second');

    $page->assertSeeIn('@billing-current-plan-price', '$19')
        ->assertSeeIn('@billing-current-plan-discount', '50% off until')
        ->assertSeeIn('@billing-card-pm_fake', 'Visa')
        ->assertSeeIn('@billing-card-pm_fake', '•••• 4242')
        ->assertSeeIn('@billing-card-pm_fake', 'Default')
        ->assertSeeIn('@billing-card-pm_fake_second', 'Mastercard')
        ->assertSeeIn('@billing-card-pm_fake_second', '•••• 5454')
        ->assertDontSeeIn('@billing-card-pm_fake_second', 'Default')
        ->assertVisible('@billing-manage-stripe')
        ->assertNoJavaScriptErrors();

    $page->assertAttribute('@billing-card-logo-pm_fake', 'src', '/images/card-brands/visa.svg')
        ->assertAttribute('@billing-card-logo-pm_fake_second', 'src', '/images/card-brands/mastercard.svg');

    waitForState($page, '@billing-card-logo-pm_fake_second', 'element.complete && element.naturalWidth > 0');

    expect($page->script("[...document.querySelectorAll('[data-testid=\"billing-payment-methods\"] > li')].map((item) => item.dataset.testid)"))
        ->toBe(['billing-card-pm_fake', 'billing-card-pm_fake_second'])
        ->and($page->script("document.querySelector('[data-testid=\"billing-card-logo-pm_fake_second\"]').naturalWidth > 0"))->toBeTrue();
});

test('the billing page shows the generic card for a brand without its own logo', function () {
    config(['trypost.self_hosted' => false]);

    $user = User::factory()->create();
    $user->account->update(['plan_id' => Plan::query()->where('slug', Slug::Socials)->value('id')]);
    $workspace = Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    fakeStripeBilling($user->account, cardBrand: 'eftpos_au');
    $this->actingAs($user->fresh());

    $page = visit(route('app.billing.index'));
    waitFor($page, '@billing-card-label');

    $page->assertSeeIn('@billing-card-label', 'eftpos')
        ->assertAttribute('@billing-card-logo-pm_fake', 'src', '/images/card-brands/generic.svg')
        ->assertMissing('@billing-current-plan-discount')
        ->assertNoJavaScriptErrors();
});

test('the invoices tab lists the paid invoices and loads more on scroll', function () {
    config(['trypost.self_hosted' => false, 'app.pagination.default' => 10]);

    $user = billingPageOwner();
    $user->account->subscriptions()->delete();
    fakeStripeBilling($user->account, paidInvoices: 12);
    $this->actingAs($user->fresh());

    $page = visit(route('app.billing.index'));
    waitFor($page, '@billing-tab-invoices');

    $page->assertMissing('@billing-invoices')
        ->click('@billing-tab-invoices');
    waitFor($page, '@billing-invoice-in_fake_012');
    waitForCondition($page, "new URLSearchParams(location.search).get('tab') === 'invoices'");

    $page->assertSeeIn('@billing-invoice-in_fake_012', '$19.00')
        ->assertSeeIn('@billing-invoice-in_fake_012', 'Paid')
        ->assertVisible('@billing-invoice-download-in_fake_012');

    $page->script('window.scrollTo(0, document.body.scrollHeight)');
    waitFor($page, '@billing-invoice-in_fake_001');

    $page->assertVisible('@billing-invoice-in_fake_001')
        ->assertMissing('@billing-current-plan')
        ->assertNoJavaScriptErrors();
});

test('the invoices tab opens from the link and says when there are none yet', function () {
    config(['trypost.self_hosted' => false]);

    $user = billingPageOwner();
    $user->account->subscriptions()->delete();
    fakeStripeBilling($user->account);
    $this->actingAs($user->fresh());

    $page = visit(route('app.billing.index', ['tab' => 'invoices']));
    waitFor($page, '@billing-invoices-empty');

    $page->assertSeeIn('@billing-invoices-empty', 'No invoices yet')
        ->assertMissing('@billing-invoices')
        ->assertNoJavaScriptErrors();
});
