<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Plan;
use App\Models\User;
use App\Support\StripeSubscriptionConversion;

beforeEach(function () {
    $this->plan = Plan::where('slug', 'workspace')->firstOrFail();
    $this->plan->update([
        'stripe_monthly_price_id' => 'price_workspace_monthly',
        'stripe_yearly_price_id' => 'price_workspace_yearly',
    ]);

    $this->account = Account::factory()->create(['plan_id' => $this->plan->id]);
    $this->user = User::factory()->create(['account_id' => $this->account->id]);
    $this->account->update(['owner_id' => $this->user->id]);
    $this->account->load(['plan', 'owner']);
});

test('baseProperties includes plan name and interval but no persona or conversion data', function () {
    $payload = [
        'data' => ['object' => [
            'items' => ['data' => [['price' => ['id' => 'price_workspace_monthly']]]],
        ]],
    ];

    $properties = StripeSubscriptionConversion::baseProperties($this->account, $payload);

    expect($properties)->toBe([
        'plan_name' => $this->plan->name,
        'interval' => 'monthly',
    ]);
});

test('baseProperties defaults to monthly, not yearly, when neither the payload price id nor the plan yearly price id is set', function () {
    $this->plan->update(['stripe_yearly_price_id' => null]);
    $payload = [
        'data' => ['object' => [
            'items' => ['data' => [['price' => []]]],
        ]],
    ];

    $properties = StripeSubscriptionConversion::baseProperties($this->account->fresh(['plan']), $payload);

    expect($properties['interval'])->toBe('monthly');
});

test('propertiesFor includes plan name, interval and conversion data', function () {
    $payload = [
        'data' => ['object' => [
            'id' => 'sub_123',
            'items' => ['data' => [[
                'price' => [
                    'id' => 'price_workspace_monthly',
                    'unit_amount' => 2900,
                    'currency' => 'usd',
                ],
            ]]],
        ]],
    ];

    $properties = StripeSubscriptionConversion::propertiesFor($this->account, $payload);

    expect($properties)->toBe([
        'plan_name' => $this->plan->name,
        'interval' => 'monthly',
        'conversion_value' => 29.0,
        'conversion_currency' => 'USD',
        'conversion_transaction_id' => 'sub_123',
    ]);
});

test('propertiesFor resolves the yearly interval from the price id', function () {
    $payload = [
        'data' => ['object' => [
            'id' => 'sub_123',
            'items' => ['data' => [['price' => ['id' => 'price_workspace_yearly']]]],
        ]],
    ];

    $properties = StripeSubscriptionConversion::propertiesFor($this->account, $payload);

    expect($properties['interval'])->toBe('yearly');
});

test('propertiesFor omits conversion fields when there is no price amount', function () {
    $payload = [
        'data' => ['object' => [
            'id' => 'sub_123',
            'items' => ['data' => [['price' => ['id' => 'price_workspace_monthly']]]],
        ]],
    ];

    $properties = StripeSubscriptionConversion::propertiesFor($this->account, $payload);

    expect($properties)->not->toHaveKeys(['conversion_value', 'conversion_currency', 'conversion_transaction_id']);
});
