<?php

declare(strict_types=1);

use App\Http\Resources\App\HandleInertiaRequests\AuthUserResource;
use App\Models\Offer;
use App\Models\User;

beforeEach(function () {
    config()->set('trypost.admin.emails', ['admin@trypost.test']);
    $this->admin = User::factory()->create(['email' => 'admin@trypost.test']);
});

test('a self-hosted install has no backoffice: every page answers 404, even to an admin', function () {
    config()->set('trypost.self_hosted', true);
    $offer = Offer::factory()->create();
    $this->actingAs($this->admin);

    $this->get(route('admin.dashboard'))->assertNotFound();
    $this->get(route('admin.users.index'))->assertNotFound();
    $this->get(route('admin.users.show', $this->admin))->assertNotFound();
    $this->get(route('admin.networks.index'))->assertNotFound();
    $this->get(route('admin.billing.subscriptions.index'))->assertNotFound();
    $this->post(route('admin.billing.offers.store'))->assertNotFound();
    $this->delete(route('admin.billing.offers.destroy', $offer))->assertNotFound();
});

test('a self-hosted install never offers the backoffice in the app menu', function (bool $selfHosted) {
    config()->set('trypost.self_hosted', $selfHosted);

    expect(AuthUserResource::make($this->admin)['is_backoffice_admin'])->toBe(! $selfHosted);
})->with([
    'self-hosted' => [true],
    'TryPost Cloud' => [false],
]);
