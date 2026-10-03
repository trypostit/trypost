<?php

declare(strict_types=1);

use App\Enums\User\DefaultPostAction;
use App\Enums\User\Locale;
use App\Enums\User\Theme;
use App\Enums\User\TimeFormat;
use App\Enums\User\WeekStart;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;

function waitForPreferencesTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            const sel = '[data-testid="{$testId}"]';
            for (let i = 0; i < 150; i++) {
                const el = document.querySelector(sel);
                if (el && el.getBoundingClientRect().height > 0) return;
                await new Promise((r) => setTimeout(r, 50));
            }
        })();
    JS);
}

function preferencesUser(array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);

    return $user->fresh();
}

function choosePreference(mixed $page, string $testid, string $value, string $savedField): void
{
    waitForPreferencesTestId($page, "{$testid}-trigger");
    $page->click("@{$testid}-trigger");
    waitForPreferencesTestId($page, "{$testid}-option-{$value}");
    $page->click("@{$testid}-option-{$value}");
    waitForPreferencesTestId($page, "preferences-saved-{$savedField}");
}

test('the preferences page lists every preference and is linked from the settings sidebar', function () {
    $this->actingAs(preferencesUser());

    $page = visit(route('app.profile.edit'));
    waitForPreferencesTestId($page, 'settings-nav-preferences');
    $page->click('@settings-nav-preferences');
    waitForPreferencesTestId($page, 'preferences-page');

    $page->assertVisible('@preferences-theme-trigger')
        ->assertVisible('@preferences-language-trigger')
        ->assertVisible('@preferences-timezone-trigger')
        ->assertVisible('@preferences-time-format-trigger')
        ->assertVisible('@preferences-week-start-trigger')
        ->assertVisible('@preferences-default-post-action-trigger')
        ->assertNoJavaScriptErrors();
});

test('choosing dark applies it at once and the server renders it on the next load', function () {
    $user = preferencesUser();
    $this->actingAs($user);

    $page = visit(route('app.settings.preferences'))->inLightMode();
    choosePreference($page, 'preferences-theme', 'dark', 'theme');

    $page->assertScript("document.documentElement.classList.contains('dark')", true);
    expect($user->fresh()->theme)->toBe(Theme::Dark);

    $page = visit(route('app.settings.preferences'))->inLightMode();
    waitForPreferencesTestId($page, 'preferences-page');
    $page->assertScript("document.documentElement.classList.contains('dark')", true)
        ->assertNoJavaScriptErrors();
});

test('the system theme follows the operating system', function () {
    $this->actingAs(preferencesUser(['theme' => Theme::System]));

    $dark = visit(route('app.settings.preferences'))->inDarkMode();
    waitForPreferencesTestId($dark, 'preferences-page');
    $dark->assertScript("document.documentElement.classList.contains('dark')", true);

    $light = visit(route('app.settings.preferences'))->inLightMode();
    waitForPreferencesTestId($light, 'preferences-page');
    $light->assertScript("document.documentElement.classList.contains('dark')", false)
        ->assertNoJavaScriptErrors();
});

test('the language changes in place and stays the user locale', function () {
    $user = preferencesUser(['locale' => Locale::English]);
    $this->actingAs($user);

    $page = visit(route('app.settings.preferences'));
    choosePreference($page, 'preferences-language', 'pt-BR', 'locale');

    $title = __('settings.preferences.title', [], 'pt-BR');
    $page->script("(async () => { for (let i = 0; i < 150; i++) { if (document.body.innerText.includes('{$title}')) return; await new Promise((r) => setTimeout(r, 50)); } })();");

    $page->assertSeeIn('@header-title', $title)->assertNoJavaScriptErrors();
    expect($user->fresh()->locale)->toBe(Locale::PortugueseBrazil);
});

test('the language picker filters languages as you type', function () {
    $this->actingAs(preferencesUser(['locale' => Locale::English]));

    $page = visit(route('app.settings.preferences'));
    waitForPreferencesTestId($page, 'preferences-language-trigger');
    $page->click('@preferences-language-trigger');
    waitForPreferencesTestId($page, 'preferences-language-search');
    $page->type('@preferences-language-search', 'Portug');
    waitForPreferencesTestId($page, 'preferences-language-option-pt-BR');

    $page->assertVisible('@preferences-language-option-pt-BR')
        ->assertMissing('@preferences-language-option-de')
        ->assertNoJavaScriptErrors();
});

test('the time zone picker suggests the browser time zone first', function () {
    $this->actingAs(preferencesUser());

    $page = visit(route('app.settings.preferences'));
    waitForPreferencesTestId($page, 'preferences-timezone-trigger');
    $page->click('@preferences-timezone-trigger');
    waitForPreferencesTestId($page, 'preferences-timezone-detected');

    $page->assertVisible('@preferences-timezone-detected')->assertNoJavaScriptErrors();
});

test('a zone that is the user, browser and channel zone is suggested once with every reason', function () {
    $user = preferencesUser(['timezone' => 'Europe/Warsaw']);
    $warsawNames = ['Warsaw LinkedIn', 'Warsaw X', 'Warsaw Bluesky', 'Warsaw Threads'];
    collect($warsawNames)->each(fn (string $name) => SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $user->current_workspace_id,
        'timezone' => 'Europe/Warsaw',
        'display_name' => $name,
    ]));
    $tokyo = SocialAccount::factory()->x()->create([
        'workspace_id' => $user->current_workspace_id,
        'timezone' => 'Asia/Tokyo',
        'display_name' => 'Tokyo X',
    ]);
    $this->actingAs($user);

    $page = visit(route('app.settings.preferences'))->withTimezone('Europe/Warsaw');
    waitForPreferencesTestId($page, 'preferences-timezone-trigger');
    $page->click('@preferences-timezone-trigger');
    waitForPreferencesTestId($page, 'preferences-timezone-suggestion-reasons-Europe-Warsaw');

    $reasons = 'document.querySelector(\'[data-testid="preferences-timezone-suggestion-reasons-Europe-Warsaw"]\')';

    $page->assertScript('document.querySelectorAll(\'[data-testid="preferences-timezone-option-Europe-Warsaw"]\').length', 1)
        ->assertScript('[...document.querySelectorAll(\'[data-testid="preferences-timezone-suggestions"] [data-testid^="preferences-timezone-option-"]\')].map((el) => el.dataset.testid)', [
            'preferences-timezone-option-Europe-Warsaw',
            'preferences-timezone-option-Asia-Tokyo',
        ])
        ->assertScript("{$reasons}.querySelectorAll('[data-testid=\"preferences-timezone-yours\"], [data-testid=\"preferences-timezone-detected\"]').length", 2)
        ->assertScript("{$reasons}.querySelectorAll('[data-testid^=\"preferences-timezone-suggestion-channel-\"]').length", 2)
        ->assertScript("{$reasons}.lastElementChild.lastElementChild.innerText.trim()", '+2')
        ->assertScript("{$reasons}.querySelectorAll('[data-slot=\"avatar\"] ~ *').length", 0)
        ->assertVisible("@preferences-timezone-suggestion-channel-{$tokyo->id}")
        ->assertScript(<<<JS
            (() => {
                const reasons = {$reasons};
                const row = reasons.closest('button');
                const box = row.getBoundingClientRect();
                const limit = box.right - parseFloat(getComputedStyle(row).paddingRight);
                const marks = [...reasons.querySelectorAll('[data-slot="avatar"]'), reasons.lastElementChild.lastElementChild];

                return [...reasons.querySelectorAll('*')].every((el) => {
                    const rect = el.getBoundingClientRect();

                    return rect.right <= limit + 0.5 && rect.top >= box.top && rect.bottom <= box.bottom;
                }) && marks.every((el) => Math.round(el.getBoundingClientRect().height) === 20 && Math.round(el.getBoundingClientRect().width) >= 20 && getComputedStyle(el).borderRadius !== '50%');
            })()
        JS, true);

    $page->hover('@preferences-timezone-option-Europe-Warsaw');
    waitForPreferencesTestId($page, 'preferences-timezone-suggestion-tooltip');

    $page->assertScript('document.querySelector(\'[data-testid="preferences-timezone-suggestion-tooltip"]\').getBoundingClientRect().right <= document.querySelector(\'[data-testid="preferences-timezone-search"]\').closest(\'[data-slot="popover-content"]\').getBoundingClientRect().left', true);

    $tooltip = $page->script('document.querySelector(\'[data-testid="preferences-timezone-suggestion-tooltip"]\').innerText');

    expect($tooltip)->toContain('Your time zone', 'Detected in your browser', ...$warsawNames)
        ->not->toContain('Tokyo X');
    $page->assertNoJavaScriptErrors();
});

test('without channels the picker shows no empty group and search filters both groups', function () {
    $this->actingAs(preferencesUser(['timezone' => 'UTC']));

    $page = visit(route('app.settings.preferences'))->withTimezone('UTC');
    waitForPreferencesTestId($page, 'preferences-timezone-trigger');
    $page->click('@preferences-timezone-trigger');
    waitForPreferencesTestId($page, 'preferences-timezone-suggestions');

    $page->assertScript('document.querySelectorAll(\'[data-testid="preferences-timezone-suggestions"] [data-testid^="preferences-timezone-option-"]\').length', 1)
        ->assertScript('document.querySelectorAll(\'[data-testid^="preferences-timezone-suggestion-channel-"]\').length', 0);

    $page->type('@preferences-timezone-search', 'Auckland');
    waitForPreferencesTestId($page, 'preferences-timezone-option-Pacific-Auckland');

    $page->assertMissing('@preferences-timezone-suggestions')
        ->assertMissing('@preferences-timezone-option-UTC')
        ->assertNoJavaScriptErrors();
});

test('a 12-hour clock shows meridiem hours on the calendar', function () {
    $user = preferencesUser(['locale' => Locale::German, 'time_format' => TimeFormat::TwentyFourHour]);
    $this->actingAs($user);

    $page = visit(route('app.settings.preferences'));
    choosePreference($page, 'preferences-time-format', '12h', 'time_format');
    expect($user->fresh()->time_format)->toBe(TimeFormat::TwelveHour);

    $page = visit(route('app.calendar', ['view' => 'week']));
    waitForPreferencesTestId($page, 'calendar-slot-'.now()->startOfWeek()->format('Y-m-d').'-14');

    $page->assertScript("document.body.innerText.includes('2 PM')", true)
        ->assertNoJavaScriptErrors();
});

test('the week start and default posting action save', function () {
    $user = preferencesUser();
    $this->actingAs($user);

    $page = visit(route('app.settings.preferences'));
    choosePreference($page, 'preferences-week-start', 'sunday', 'week_starts_on');
    choosePreference($page, 'preferences-default-post-action', 'custom', 'default_post_action');

    $user->refresh();
    expect($user->week_starts_on)->toBe(WeekStart::Sunday)
        ->and($user->default_post_action)->toBe(DefaultPostAction::Custom);

    $page = visit(route('app.calendar', ['view' => 'month']));
    waitForPreferencesTestId($page, 'calendar-month-grid');
    $page->assertNoJavaScriptErrors();
});

test('the time format offers only two clocks and keeps its value after a language change', function () {
    $user = preferencesUser(['locale' => Locale::English, 'time_format' => TimeFormat::TwelveHour]);
    $this->actingAs($user);

    $page = visit(route('app.settings.preferences'));
    waitForPreferencesTestId($page, 'preferences-time-format-trigger');
    $page->click('@preferences-time-format-trigger');
    waitForPreferencesTestId($page, 'preferences-time-format-option-24h');

    expect($page->script('document.querySelectorAll(\'[data-testid^="preferences-time-format-option-"]\').length'))->toBe(2);
    $page->keys('@preferences-time-format-option-24h', 'Escape');
    $page->script('(async () => { for (let i = 0; i < 150; i++) { if (! document.querySelector(\'[data-testid^="preferences-time-format-option-"]\')) return; await new Promise((r) => setTimeout(r, 50)); } })();');

    choosePreference($page, 'preferences-language', 'de', 'locale');
    $label = __('settings.preferences.time_format.twelve_hour', [], 'de');
    $page->script("(async () => { for (let i = 0; i < 150; i++) { if (document.querySelector('[data-testid=\"preferences-time-format-value\"]')?.textContent.includes('{$label}')) return; await new Promise((r) => setTimeout(r, 50)); } })();");

    $page->assertSeeIn('@preferences-time-format-value', $label)->assertNoJavaScriptErrors();
    expect($user->fresh()->time_format)->toBe(TimeFormat::TwelveHour);
});

test('saving a preference shows no success toast', function () {
    $user = preferencesUser(['time_format' => TimeFormat::TwelveHour]);
    $this->actingAs($user);
    $toastAppears = "(async () => { for (let i = 0; i < 20; i++) { if (document.querySelector('[data-sonner-toast]')) return true; await new Promise((r) => setTimeout(r, 50)); } return false; })();";

    $page = visit(route('app.settings.preferences'));
    choosePreference($page, 'preferences-time-format', '24h', 'time_format');
    expect($page->script($toastAppears))->toBeFalse();

    choosePreference($page, 'preferences-language', 'de', 'locale');
    expect($page->script($toastAppears))->toBeFalse();

    $page->assertNoJavaScriptErrors();
    expect($user->fresh()->time_format)->toBe(TimeFormat::TwentyFourHour);
});

test('the language select suggests the language detected in the browser', function () {
    $user = preferencesUser();
    $this->actingAs($user);

    $page = visit(route('app.settings.preferences'))->withLocale('pt-PT');
    waitForPreferencesTestId($page, 'preferences-language-trigger');

    $page->click('@preferences-language-trigger');
    waitForPreferencesTestId($page, 'preferences-language-suggestions');

    $page->assertVisible('[data-testid="preferences-language-suggestions"] [data-testid="preferences-language-option-pt-BR"]')
        ->assertVisible('@preferences-language-detected')
        ->assertSeeIn('@preferences-language-suggestions', __('settings.preferences.language.suggestions'));

    expect($page->script("document.querySelectorAll('[data-testid=\"preferences-language-option-pt-BR\"]').length"))->toBe(1);

    $page->fill('@preferences-language-search', 'Deutsch');
    $page->assertMissing('@preferences-language-suggestions')
        ->assertVisible('@preferences-language-option-de')
        ->assertNoJavaScriptErrors();
});
