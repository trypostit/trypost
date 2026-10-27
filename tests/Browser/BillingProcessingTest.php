<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;

function billingProcessingSubscriber(): User
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $user->account->update(['plan_id' => Plan::query()->value('id') ?? Plan::factory()->create()->id]);

    return $user;
}

test('once the subscription and plan are ready the processing page sends the user to the MCP step', function () {
    config(['trypost.self_hosted' => false]);

    $this->actingAs(billingProcessingSubscriber());

    $mcpPath = parse_url(route('app.welcome.mcp'), PHP_URL_PATH);

    $page = visit(route('app.billing.processing'));
    waitForPath($page, $mcpPath);

    $page->assertPathIs($mcpPath)
        ->assertNoJavaScriptErrors();
});

test('a subscription started from billing returns to billing, not the onboarding', function () {
    config(['trypost.self_hosted' => false]);

    $user = billingProcessingSubscriber();
    Plan::query()->whereKey($user->account->plan_id)->update(['stripe_monthly_price_id' => 'price_123']);
    $this->actingAs($user->fresh());

    $billingPath = parse_url(route('app.billing.index'), PHP_URL_PATH);

    $page = visit(route('app.billing.processing', ['from' => 'billing']));
    waitForPath($page, $billingPath);

    $page->assertPathIs($billingPath)
        ->assertNoJavaScriptErrors();
});

test('on a phone the processing page skips the MCP step and opens the schedule', function () {
    config(['trypost.self_hosted' => false]);

    $this->actingAs(billingProcessingSubscriber());

    $schedulePath = parse_url(route('app.posts.index'), PHP_URL_PATH);

    $page = visit(route('app.billing.processing'))->on()->mobile();
    waitForPath($page, $schedulePath);

    $page->assertPathIs($schedulePath)
        ->assertNoJavaScriptErrors();
});

test('while checkout settles the processing page uses the neutral auth layout, not the welcome one', function () {
    config(['trypost.self_hosted' => false]);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);

    $this->actingAs($user);

    $page = visit(route('app.billing.processing'));
    waitFor($page, '@billing-processing');

    $page->assertSee('Processing your subscription')
        ->assertVisible('@billing-processing')
        ->assertVisible('@auth-logo')
        ->assertMissing('@welcome-back')
        ->assertMissing('@welcome-step-plan')
        ->assertNoJavaScriptErrors();
});
