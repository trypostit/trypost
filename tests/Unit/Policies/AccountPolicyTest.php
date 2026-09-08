<?php

declare(strict_types=1);

use App\Enums\Plan\Slug;
use App\Models\Account;
use App\Models\AiUsageLog;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Policies\AccountPolicy;

beforeEach(function () {
    $this->policy = new AccountPolicy;
    $this->account = Account::factory()->create([
        'plan_id' => Plan::where('slug', Slug::Workspace)->value('id'),
    ]);
    $this->owner = User::factory()->create(['account_id' => $this->account->id]);
    $this->account->update(['owner_id' => $this->owner->id]);
});

test('swapPlan allows the account owner', function () {
    $response = $this->policy->swapPlan($this->owner, $this->account);

    expect($response->allowed())->toBeTrue();
});

test('swapPlan denies a non-owner', function () {
    $member = User::factory()->create(['account_id' => $this->account->id]);

    $response = $this->policy->swapPlan($member, $this->account);

    expect($response->denied())->toBeTrue();
    expect($response->message())->toBe(__('billing.flash.cannot_manage'));
});

test('useAi allows when subscribed', function () {
    config()->set('trypost.self_hosted', false);
    Workspace::factory()->create([
        'account_id' => $this->account->id,
        'user_id' => $this->owner->id,
    ]);
    subscribeAccount($this->account);

    $response = $this->policy->useAi($this->owner, $this->account->fresh());

    expect($response->allowed())->toBeTrue();
});

test('useAi denies when there is no active subscription', function () {
    config()->set('trypost.self_hosted', false);
    Workspace::factory()->create([
        'account_id' => $this->account->id,
        'user_id' => $this->owner->id,
    ]);

    $response = $this->policy->useAi($this->owner, $this->account->fresh());

    expect($response->denied())->toBeTrue();
    expect($response->message())->toBe(__('billing.flash.subscription_required'));
});

test('useAi allows a subscribed account regardless of recorded AI usage', function () {
    config()->set('trypost.self_hosted', false);
    $workspace = Workspace::factory()->create([
        'account_id' => $this->account->id,
        'user_id' => $this->owner->id,
    ]);
    subscribeAccount($this->account);

    AiUsageLog::factory()->text(credits: 999999)->create([
        'account_id' => $this->account->id,
        'workspace_id' => $workspace->id,
    ]);

    $response = $this->policy->useAi($this->owner, $this->account->fresh());

    expect($response->allowed())->toBeTrue();
});

test('useAi always allows when self-hosted', function () {
    config()->set('trypost.self_hosted', true);

    $response = $this->policy->useAi($this->owner, $this->account);

    expect($response->allowed())->toBeTrue();
});
