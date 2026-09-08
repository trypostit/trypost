<?php

declare(strict_types=1);

use App\Enums\User\Locale;
use App\Models\User;
use Illuminate\Support\Facades\View;

test('a request renders in the authenticated user locale', function (Locale $locale, string $direction) {
    $this->actingAs(User::factory()->create(['locale' => $locale]))
        ->get(route('app.profile.edit'))
        ->assertOk();

    expect(app()->getLocale())->toBe($locale->value)
        ->and(View::shared('htmlDir'))->toBe($direction);
})->with([
    [Locale::Japanese, 'ltr'],
    [Locale::PortugueseBrazil, 'ltr'],
    [Locale::Arabic, 'rtl'],
]);

test('a guest request renders in the default locale', function () {
    $this->get(route('login'))->assertOk();

    expect(app()->getLocale())->toBe(Locale::DEFAULT->value)
        ->and(View::shared('htmlDir'))->toBe('ltr');
});

test('a guest cannot change the rendered locale by submitting one', function () {
    $this->get(route('login', ['locale' => 'ja']))->assertOk();

    expect(app()->getLocale())->toBe(Locale::DEFAULT->value);
});

test('no locale cookie is written', function () {
    $this->get(route('login'))->assertCookieMissing('locale');
});
