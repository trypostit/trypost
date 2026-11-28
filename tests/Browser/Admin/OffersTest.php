<?php

declare(strict_types=1);

use App\Models\Offer;

test('an admin creates, edits, ends and deletes an offer', function () {
    $this->actingAs(admin());

    $page = visit(route('admin.billing.offers.index'));

    waitFor($page, '@admin-offer-new');
    $page->click('@admin-offer-new');
    waitForOpen($page, '@admin-offer-dialog');
    $page->fill('@admin-offer-slug', 'spring-trial')
        ->fill('@admin-offer-trial', '8');
    $page->click('@admin-offer-submit');

    waitForGone($page, '@admin-offer-dialog');
    waitFor($page, '@admin-offer-spring-trial');

    $offer = Offer::query()->where('slug', 'spring-trial')->sole();
    expect($offer->trial_days)->toBe(8)
        ->and($offer->stripe_coupon_id)->toBeNull();

    $page->click('@admin-offer-actions-spring-trial');
    $page->click('@admin-offer-edit-spring-trial');
    waitForOpen($page, '@admin-offer-dialog');
    $page->fill('@admin-offer-limit', '50');
    $page->click('@admin-offer-submit');
    waitForGone($page, '@admin-offer-dialog');

    expect($offer->refresh()->max_redemptions)->toBe(50);

    $page->click('@admin-offer-actions-spring-trial');
    $page->click('@admin-offer-end-spring-trial');
    waitForOpen($page, '@admin-offer-confirm');
    $page->click('@admin-offer-confirm-submit');
    waitForGone($page, '@admin-offer-confirm');

    expect($offer->refresh()->ends_at?->isPast())->toBeTrue();

    $page->click('@admin-offer-actions-spring-trial');
    $page->click('@admin-offer-delete-spring-trial');
    waitForOpen($page, '@admin-offer-confirm');
    $page->click('@admin-offer-confirm-submit');
    waitForGone($page, '@admin-offer-spring-trial');

    expect(Offer::query()->where('slug', 'spring-trial')->exists())->toBeFalse();
    $page->assertNoJavaScriptErrors();
});

test('the offers tabs list the offers in each state', function () {
    $this->actingAs(admin());
    Offer::factory()->create(['slug' => 'running']);
    Offer::factory()->upcoming()->create(['slug' => 'later']);

    $page = visit(route('admin.billing.offers.index'));

    waitFor($page, '@admin-offer-running');
    $page->click('@admin-offers-tab-scheduled');
    waitForGone($page, '@admin-offer-running');
    waitFor($page, '@admin-offer-later');
    $page->assertNoJavaScriptErrors();
});
