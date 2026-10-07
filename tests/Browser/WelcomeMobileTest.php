<?php

declare(strict_types=1);

use App\Enums\User\Goal;
use App\Enums\User\Persona;
use App\Enums\User\ReferralSource;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;

function waitForWelcomeMobileTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            const selector = '[data-testid="{$testId}"]';
            for (let attempt = 0; attempt < 200; attempt++) {
                const element = document.querySelector(selector);
                if (element && element.getBoundingClientRect().height > 0) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function welcomeMobileOwner(array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);

    SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $workspace->id,
    ]);

    return $user;
}

/**
 * @return array{problems: list<string>, stepsAboveTitle: bool}
 */
function welcomeMobileHeaderProblems(mixed $page): array
{
    return $page->script(<<<'JS'
        (() => {
            const header = document.querySelector('header');
            const items = [...header.querySelectorAll('[data-testid="welcome-back"], [data-testid="app-logo"], [data-testid="welcome-language-trigger"], [data-testid="theme-toggle-cycle"]')]
                .filter((element) => element.getClientRects().length > 0)
                .map((element) => ({ id: element.dataset.testid, rect: element.getBoundingClientRect() }));
            const problems = [];
            const width = window.innerWidth;
            for (const item of items) {
                if (item.rect.left < 0 || item.rect.right > width || item.rect.top < 0) {
                    problems.push(item.id + ' is cut');
                }
                if (item.rect.width === 0 || item.rect.height === 0) {
                    problems.push(item.id + ' is empty');
                }
            }
            for (let i = 0; i < items.length; i++) {
                for (let j = i + 1; j < items.length; j++) {
                    const a = items[i].rect;
                    const b = items[j].rect;
                    if (a.left < b.right && b.left < a.right && a.top < b.bottom && b.top < a.bottom) {
                        problems.push(items[i].id + ' overlaps ' + items[j].id);
                    }
                }
            }
            if (!['app-logo', 'welcome-language-trigger', 'theme-toggle-cycle'].every((id) => items.some((item) => item.id === id))) {
                problems.push('missing header items: ' + items.map((item) => item.id).join(','));
            }
            if (header.querySelector('[data-testid^="welcome-step-"]')) {
                problems.push('step dots still in the header');
            }
            const step = document.querySelector('[data-testid^="welcome-step-"]');
            const title = document.querySelector('main h1');
            const stepsAboveTitle = Boolean(step && title && step.getBoundingClientRect().bottom <= title.getBoundingClientRect().top
                && Math.abs((step.closest('nav').getBoundingClientRect().left + step.closest('nav').getBoundingClientRect().right) / 2 - width / 2) < 2);

            return { problems, stepsAboveTitle };
        })()
    JS);
}

/**
 * @return array{visible: bool, lastClear: bool}
 */
function welcomeMobileContinueFits(mixed $page, string $testId, string $optionPrefix): array
{
    return $page->script(<<<JS
        (async () => {
            window.scrollTo({ top: 0, behavior: 'instant' });
            const button = document.querySelector('[data-testid="{$testId}"]');
            const footer = document.querySelector('[data-testid="welcome-actions"]');
            const rect = button.getBoundingClientRect();
            const hit = document.elementFromPoint(rect.left + rect.width / 2, rect.top + rect.height / 2);
            const visible = rect.top >= 0 && rect.bottom <= window.innerHeight && footer.contains(hit);
            window.scrollTo({ top: document.documentElement.scrollHeight, behavior: 'instant' });
            await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
            const options = [...document.querySelectorAll('[data-testid^="{$optionPrefix}"]')]
                .filter((element) => !footer.contains(element));
            const last = options[options.length - 1].getBoundingClientRect();

            return { visible, lastClear: last.bottom <= footer.getBoundingClientRect().top };
        })()
    JS);
}

test('the welcome header fits a phone and the continue button stays on screen', function (string $route, array $attributes, ?string $continue, ?string $optionPrefix, bool $hasBack) {
    config(['trypost.self_hosted' => false]);

    $this->actingAs(welcomeMobileOwner($attributes));

    $page = visit(route($route))->resize(390, 844);
    waitForWelcomeMobileTestId($page, 'theme-toggle-cycle');
    waitForWelcomeMobileTestId($page, $hasBack ? 'welcome-back' : 'welcome-step-persona');

    $result = welcomeMobileHeaderProblems($page);

    expect($result['problems'])->toBe([])
        ->and($result['stepsAboveTitle'])->toBeTrue();

    if ($continue !== null) {
        $fits = welcomeMobileContinueFits($page, $continue, $optionPrefix);

        expect($fits['visible'])->toBeTrue()
            ->and($fits['lastClear'])->toBeTrue();
    }

    if ($hasBack) {
        $page->assertVisible('@welcome-back');
    }

    $page->assertMissing('@theme-toggle')
        ->assertNoJavaScriptErrors();
})->with([
    'persona' => ['app.welcome.persona', [], 'welcome-persona-continue', 'welcome-persona-', false],
    'goals' => ['app.welcome.goals', ['persona' => Persona::Agency->value], 'welcome-goals-continue', 'welcome-goal-', true],
    'plan' => ['app.welcome.plan', [
        'persona' => Persona::Agency->value,
        'goals' => [Goal::SaveTime->value],
        'referral_source' => ReferralSource::ProductHunt->value,
    ], null, null, true],
]);

test('the plan step lists the features once on a phone and per plan on desktop', function () {
    config(['trypost.self_hosted' => false]);

    $this->actingAs(welcomeMobileOwner([
        'persona' => Persona::Agency->value,
        'goals' => [Goal::SaveTime->value],
        'referral_source' => ReferralSource::ProductHunt->value,
    ]));

    $countLists = <<<'JS'
        [...document.querySelectorAll('[data-testid^="plan-feature-list-"]')]
            .filter((element) => element.getClientRects().length > 0)
            .map((element) => element.dataset.testid)
    JS;

    $page = visit(route('app.welcome.plan'))->resize(390, 844);
    waitForWelcomeMobileTestId($page, 'plan-feature-list-all');

    expect($page->script($countLists))->toBe(['plan-feature-list-all']);

    $page->assertVisible('@plan-select-socials')
        ->assertVisible('@plan-highlight-workspaces')
        ->assertNoJavaScriptErrors();

    $page->resize(1280, 900);
    waitForWelcomeMobileTestId($page, 'plan-feature-list-socials');

    expect($page->script($countLists))->toBe(['plan-feature-list-socials', 'plan-feature-list-workspaces']);

    $page->assertVisible('@theme-toggle')
        ->assertMissing('@theme-toggle-cycle')
        ->assertNoJavaScriptErrors();
});
