<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;

beforeEach(function () {
    $this->account = Account::factory()->create();
    $this->user = User::factory()->create([
        'account_id' => $this->account->id,
    ]);
    $this->account->update(['owner_id' => $this->user->id]);
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->account->id,
        'user_id' => $this->user->id,
    ]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

test('account edit shows account settings with billing email when not self hosted', function () {
    config(['trypost.self_hosted' => false]);

    $this->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);

    $response = $this->actingAs($this->user)->get(route('app.account.edit'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('settings/account/Account', false)
        ->where('selfHosted', false)
        ->has('account.billing_email')
    );
});

test('account edit shows self hosted flag when self hosted', function () {
    config(['trypost.self_hosted' => true]);

    $response = $this->actingAs($this->user)->get(route('app.account.edit'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('settings/account/Account', false)
        ->where('selfHosted', true)
    );
});

test('account update does not require billing email when self hosted', function () {
    config(['trypost.self_hosted' => true]);

    $response = $this->actingAs($this->user)->put(route('app.account.update'), [
        'name' => 'Updated Account',
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('accounts', [
        'id' => $this->account->id,
        'name' => 'Updated Account',
    ]);
});

test('account update requires billing email when not self hosted', function () {
    config(['trypost.self_hosted' => false]);

    $this->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);

    $response = $this->actingAs($this->user)->put(route('app.account.update'), [
        'name' => 'Updated Account',
    ]);

    $response->assertSessionHasErrors('billing_email');
});
