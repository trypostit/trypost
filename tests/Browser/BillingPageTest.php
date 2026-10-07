<?php

declare(strict_types=1);

use App\Enums\Plan\Slug;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;

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
        ->assertSeeIn('@billing-current-plan-price', '$19/month')
        ->assertSeeIn('@billing-current-plan-status', 'Renews automatically')
        ->assertSeeIn('@billing-current-plan-usage', 'Workspaces: 1 of 1')
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
