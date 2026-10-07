<?php

declare(strict_types=1);

use App\Enums\User\ReferralSource;
use App\Models\User;
use App\Models\Workspace;

function waitForReferralSurveyCondition(mixed $page, string $condition): void
{
    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 200; attempt++) {
                if ({$condition}) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

test('a user who has not said where they found us answers from the in-app card', function () {
    config(['trypost.self_hosted' => false]);

    $user = User::factory()->create(['referral_source' => null]);
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    $this->actingAs($user->fresh());

    $page = visit(route('app.calendar'))->resize(1280, 900);
    waitForReferralSurveyCondition($page, "document.querySelector('[data-testid=\"referral-source-survey\"]')");

    expect($page->script("document.querySelector('[data-testid=\"referral-source-submit\"]').disabled"))->toBeTrue();

    $page->click('@referral-source-option-'.ReferralSource::ProductHunt->value)
        ->click('@referral-source-submit');
    waitForReferralSurveyCondition($page, "!document.querySelector('[data-testid=\"referral-source-survey\"]')");

    $page->assertMissing('@referral-source-survey')
        ->assertNoJavaScriptErrors();

    expect($user->fresh()->referral_source)->toBe(ReferralSource::ProductHunt);
});

test('on a phone the survey slides up as a bottom sheet and can be answered', function () {
    config(['trypost.self_hosted' => false]);

    $user = User::factory()->create(['referral_source' => null]);
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    $this->actingAs($user);

    $page = visit(route('app.calendar'))->resize(390, 844);
    waitForReferralSurveyCondition($page, "(() => { const sheet = document.querySelector('[data-testid=\"referral-source-survey\"]'); return sheet && Math.abs(window.innerHeight - sheet.getBoundingClientRect().bottom) < 1; })()");

    $layout = $page->script(<<<'JS'
        (() => {
            const sheet = document.querySelector('[data-testid="referral-source-survey"]').getBoundingClientRect();
            const submit = document.querySelector('[data-testid="referral-source-submit"]').getBoundingClientRect();
            const body = document.querySelector('[data-testid="referral-source-option-google"]').closest('[role="radiogroup"]').getBoundingClientRect();
            return { left: Math.round(sheet.left), right: Math.round(window.innerWidth - sheet.right), bottom: Math.abs(window.innerHeight - sheet.bottom) <= 1 ? 0 : Math.round(window.innerHeight - sheet.bottom), fullWidthSubmit: Math.abs(submit.width - (body.width - 8)) <= 1 };
        })()
    JS);

    expect($layout)->toBe(['left' => 0, 'right' => 0, 'bottom' => 0, 'fullWidthSubmit' => true]);

    $page->click('@referral-source-option-google')
        ->click('@referral-source-submit');
    waitForReferralSurveyCondition($page, "!document.querySelector('[data-testid=\"referral-source-survey\"]')");

    expect($user->fresh()->referral_source)->toBe(ReferralSource::Google);
    $page->assertNoJavaScriptErrors();
});
