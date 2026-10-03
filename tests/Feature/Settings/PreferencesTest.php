<?php

declare(strict_types=1);

use App\Enums\User\DefaultPostAction;
use App\Enums\User\Locale;
use App\Enums\User\Theme;
use App\Enums\User\TimeFormat;
use App\Enums\User\WeekStart;
use App\Jobs\PostHog\SyncUser;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

test('the preferences page renders with the time zone options', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('app.settings.preferences'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/profile/Preferences')
            ->has('timezones'));
});

test('guests cannot open the preferences page', function () {
    $this->get(route('app.settings.preferences'))->assertRedirect(route('login'));
});

test('new users get the default preferences', function () {
    $user = User::factory()->create()->fresh();

    expect($user->theme)->toBe(Theme::System)
        ->and($user->time_format)->toBe(TimeFormat::DEFAULT)
        ->and($user->week_starts_on)->toBe(WeekStart::Monday)
        ->and($user->default_post_action)->toBe(DefaultPostAction::Next);
});

test('each preference saves on its own', function (string $field, string $value, mixed $expected) {
    $user = User::factory()->create(['timezone' => 'Europe/Warsaw']);

    $this->actingAs($user)
        ->from(route('app.settings.preferences'))
        ->patch(route('app.settings.preferences.update'), [$field => $value])
        ->assertRedirect(route('app.settings.preferences'))
        ->assertSessionHasNoErrors();

    $user->refresh();

    expect($user->{$field})->toBe($expected)
        ->and($user->timezone)->toBe($field === 'timezone' ? $expected : 'Europe/Warsaw');
})->with([
    'theme' => ['theme', 'dark', Theme::Dark],
    'time format' => ['time_format', '24h', TimeFormat::TwentyFourHour],
    'week start' => ['week_starts_on', 'sunday', WeekStart::Sunday],
    'default post action' => ['default_post_action', 'top', DefaultPostAction::Top],
    'time zone' => ['timezone', 'America/Sao_Paulo', 'America/Sao_Paulo'],
]);

test('a JSON request saves one preference and answers with no content', function () {
    $user = User::factory()->create(['theme' => Theme::Dark]);

    $this->actingAs($user)
        ->patchJson(route('app.settings.preferences.update'), ['default_post_action' => 'now'])
        ->assertNoContent();

    $user->refresh();

    expect($user->default_post_action)->toBe(DefaultPostAction::Now)
        ->and($user->theme)->toBe(Theme::Dark);
});

test('a JSON request with an invalid value gets a 422', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patchJson(route('app.settings.preferences.update'), ['default_post_action' => 'later'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('default_post_action');

    expect($user->refresh()->default_post_action)->toBe(DefaultPostAction::Next);
});

test('invalid preference values are rejected', function (string $field, string $value) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('app.settings.preferences.update'), [$field => $value])
        ->assertSessionHasErrors($field);
})->with([
    ['theme', 'sepia'],
    ['time_format', '36h'],
    ['week_starts_on', 'wednesday'],
    ['default_post_action', 'later'],
    ['timezone', 'Nowhere/Land'],
    ['theme', ''],
]);

test('a new account reads the clock of its language', function (string $locale, string $email, TimeFormat $expected) {
    config()->set('trypost.self_hosted', false);

    $this->post(route('register.store'), [
        'name' => 'Ana',
        'email' => $email,
        'password' => 'Password123!',
        'locale' => $locale,
    ])->assertSessionHasNoErrors();

    expect(User::query()->where('email', $email)->sole()->time_format)->toBe($expected);
})->with([
    'english' => ['en', 'ana-en@example.com', TimeFormat::TwelveHour],
    'german' => ['de', 'ana-de@example.com', TimeFormat::TwentyFourHour],
    'portuguese' => ['pt-BR', 'ana-pt@example.com', TimeFormat::TwentyFourHour],
]);

test('the shared time format is the stored one whatever the language', function () {
    $user = User::factory()->timeFormat(TimeFormat::TwentyFourHour)->create(['locale' => Locale::English]);

    $this->actingAs($user)
        ->get(route('app.settings.preferences'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.user.time_format', '24h'));
});

test('the shared auth user exposes the preferences', function () {
    $user = User::factory()
        ->theme(Theme::Dark)
        ->weekStartsOn(WeekStart::Sunday)
        ->defaultPostAction(DefaultPostAction::Custom)
        ->create(['locale' => Locale::English]);

    $this->actingAs($user)
        ->get(route('app.settings.preferences'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.user.theme', 'dark')
            ->where('auth.user.time_format', '12h')
            ->where('auth.user.week_starts_on', 'sunday')
            ->where('auth.user.default_post_action', 'custom'));
});

test('the root view renders the dark class only for the dark theme', function (Theme $theme, bool $dark) {
    $user = User::factory()->theme($theme)->create();

    $html = $this->actingAs($user)->get(route('app.settings.preferences'))->getContent();

    expect($html)->toContain("data-theme=\"{$theme->value}\"");
    expect((bool) preg_match('/<html[^>]*class="dark"/', $html))->toBe($dark);
})->with([
    [Theme::Light, false],
    [Theme::Dark, true],
    [Theme::System, false],
]);

test('changing the language from preferences still syncs PostHog', function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test_key']);
    Queue::fake();

    $user = User::factory()->create(['locale' => Locale::English]);

    $this->actingAs($user)
        ->from(route('app.settings.preferences'))
        ->put(route('app.profile.language'), ['locale' => 'pt-BR'])
        ->assertRedirect(route('app.settings.preferences'));

    expect($user->fresh()->locale)->toBe(Locale::PortugueseBrazil);
    Queue::assertPushed(SyncUser::class, fn (SyncUser $job) => $job->userId === (string) $user->id);
});
