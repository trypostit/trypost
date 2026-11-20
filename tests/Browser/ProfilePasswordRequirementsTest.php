<?php

declare(strict_types=1);

use App\Models\User;

test('setting or changing a password shows the same requirements as sign up', function (bool $hasPassword) {
    $user = User::factory()->create($hasPassword ? [] : ['password' => null]);
    $this->actingAs($user);

    $page = visit(route('app.profile.edit', ['tab' => 'login']));
    waitFor($page, '@password-requirement-min[data-met="false"]');

    $page->assertSeeIn('@password-requirement-min', 'At least 12 characters')
        ->assertAttribute('@password-requirement-mixed_case', 'data-met', 'false')
        ->assertAttribute('@password-requirement-number', 'data-met', 'false')
        ->assertAttribute('@password-requirement-symbol', 'data-met', 'false');

    $page->type('@profile-new-password', 'abcD1!abcdef');
    waitFor($page, '@password-requirement-min[data-met="true"]');

    $page->assertAttribute('@password-requirement-min', 'data-met', 'true')
        ->assertAttribute('@password-requirement-mixed_case', 'data-met', 'true')
        ->assertAttribute('@password-requirement-number', 'data-met', 'true')
        ->assertAttribute('@password-requirement-symbol', 'data-met', 'true')
        ->assertNoJavaScriptErrors();
})->with(['with a password' => true, 'signed up with Google or GitHub' => false]);
