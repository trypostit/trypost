<?php

declare(strict_types=1);

use App\Enums\User\Locale;
use App\Models\User;
use App\Models\Webhook;
use App\Models\Workspace;

function waitForWebhookCreateTestId(mixed $page, string $testId): void
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

function waitForWebhookCreateTestIdGone(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            const sel = '[data-testid="{$testId}"]';
            for (let i = 0; i < 150; i++) {
                if (! document.querySelector(sel)) return;
                await new Promise((r) => setTimeout(r, 50));
            }
        })();
    JS);
}

function webhookCreateDialogAdmin(): User
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);

    return $user->fresh();
}

test('creating a webhook happens in a centered dialog with cancel before the primary action', function () {
    $this->actingAs(webhookCreateDialogAdmin());

    $page = visit(route('app.webhooks.index'));
    waitForWebhookCreateTestId($page, 'webhooks-empty-create');
    $page->click('@webhooks-empty-create');
    waitForWebhookCreateTestId($page, 'create-webhook-dialog');

    $page->assertVisible('@create-webhook-endpoint')
        ->assertVisible('@create-webhook-events-post-created')
        ->assertScript("document.querySelector('[data-testid=\"create-webhook-dialog\"]').getAttribute('role') === 'dialog'", true)
        ->assertScript("(() => { const dialog = document.querySelector('[data-testid=\"create-webhook-dialog\"]').getBoundingClientRect(); return Math.abs((dialog.left + dialog.right) / 2 - window.innerWidth / 2) < 2; })()", true)
        ->assertScript("(() => { const cancel = document.querySelector('[data-testid=\"cancel-create-webhook\"]'); const submit = document.querySelector('[data-testid=\"create-webhook-submit\"]'); return Boolean(cancel.compareDocumentPosition(submit) & Node.DOCUMENT_POSITION_FOLLOWING) && cancel.getBoundingClientRect().left < submit.getBoundingClientRect().left; })()", true)
        ->assertScript("document.querySelector('[data-testid=\"create-webhook-dialog\"] [required]') === null", true)
        ->assertDisabled('@create-webhook-submit')
        ->fill('@create-webhook-endpoint', 'https://example.com/hooks')
        ->assertDisabled('@create-webhook-submit')
        ->click('@create-webhook-events-post-created')
        ->assertEnabled('@create-webhook-submit')
        ->click('@create-webhook-events-post-created')
        ->assertDisabled('@create-webhook-submit')
        ->assertNoJavaScriptErrors();
});

test('the events picker selects all, deselects all and toggles a row while counting the selection', function () {
    $this->actingAs(webhookCreateDialogAdmin());

    $page = visit(route('app.webhooks.index'));
    waitForWebhookCreateTestId($page, 'webhooks-empty-create');
    $page->click('@webhooks-empty-create');
    waitForWebhookCreateTestId($page, 'create-webhook-events-toggle-all');

    $checkedCount = "document.querySelectorAll('[data-testid=\"create-webhook-events\"] [role=\"checkbox\"][data-state=\"checked\"]').length";

    $page->assertSeeIn('@create-webhook-events-count', '0 of 7 selected')
        ->assertSeeIn('@create-webhook-events-toggle-all', __('posts.composer.select_all'))
        ->assertSeeIn('@create-webhook-events-post-published-description', __('webhooks.event_descriptions.post_published'))
        ->assertSeeIn('@create-webhook-endpoint-help', __('webhooks.create.endpoint_help'))
        ->click('@create-webhook-events-toggle-all')
        ->assertScript($checkedCount, 7)
        ->assertSeeIn('@create-webhook-events-count', '7 of 7 selected')
        ->assertSeeIn('@create-webhook-events-toggle-all', __('posts.composer.deselect_all'))
        ->click('@create-webhook-events-toggle-all')
        ->assertScript($checkedCount, 0)
        ->assertSeeIn('@create-webhook-events-count', '0 of 7 selected')
        ->assertSeeIn('@create-webhook-events-toggle-all', __('posts.composer.select_all'))
        ->click('@create-webhook-events-post-failed-description')
        ->assertScript("document.querySelector('[data-testid=\"create-webhook-events-post-failed-checkbox\"]').getAttribute('data-state')", 'checked')
        ->assertSeeIn('@create-webhook-events-count', '1 of 7 selected')
        ->click('@create-webhook-events-post-failed-checkbox')
        ->assertScript("document.querySelector('[data-testid=\"create-webhook-events-post-failed-checkbox\"]').getAttribute('data-state')", 'unchecked')
        ->assertSeeIn('@create-webhook-events-count', '0 of 7 selected')
        ->assertNoJavaScriptErrors();
});

test('the create button stays disabled until an endpoint and an event are set', function () {
    $this->actingAs(webhookCreateDialogAdmin());

    $page = visit(route('app.webhooks.index'));
    waitForWebhookCreateTestId($page, 'webhooks-empty-create');
    $page->click('@webhooks-empty-create');
    waitForWebhookCreateTestId($page, 'create-webhook-endpoint');

    $page->click('@create-webhook-events-toggle-all')
        ->assertDisabled('@create-webhook-submit')
        ->fill('@create-webhook-endpoint', 'https://example.com/hooks')
        ->assertEnabled('@create-webhook-submit')
        ->click('@create-webhook-events-toggle-all')
        ->assertDisabled('@create-webhook-submit')
        ->assertNoJavaScriptErrors();
});

test('the endpoint helper and event descriptions stay on one line in every language', function () {
    $user = webhookCreateDialogAdmin();
    $wrapped = [];

    foreach (Locale::cases() as $locale) {
        $user->update(['locale' => $locale]);
        $this->actingAs($user->fresh());

        $page = visit(route('app.webhooks.index'));
        waitForWebhookCreateTestId($page, 'webhooks-empty-create');
        $page->click('@webhooks-empty-create');
        waitForWebhookCreateTestId($page, 'create-webhook-events-post-created-description');

        $lines = $page->script(<<<'JS'
            [...document.querySelectorAll('[data-testid="create-webhook-endpoint-help"], [data-testid="create-webhook-events-count"], [data-testid="create-webhook-events-toggle-all"], [data-testid$="-description"]')]
                .map((element) => {
                    const range = document.createRange();
                    range.selectNodeContents(element);
                    const tops = new Set([...range.getClientRects()].filter((rect) => rect.width > 0).map((rect) => Math.round(rect.top)));
                    return [element.textContent.trim(), tops.size];
                })
                .filter(([, lineCount]) => lineCount > 1)
                .map(([text]) => text)
        JS);

        foreach ($lines as $text) {
            $wrapped[] = "{$locale->value}: {$text}";
        }
    }

    expect($wrapped)->toBe([]);
});

test('an invalid endpoint shows an inline error and a valid one opens the new webhook page', function () {
    $user = webhookCreateDialogAdmin();
    $this->actingAs($user);

    $page = visit(route('app.webhooks.index'));
    waitForWebhookCreateTestId($page, 'webhooks-empty-create');
    $page->click('@webhooks-empty-create');
    waitForWebhookCreateTestId($page, 'create-webhook-endpoint');

    $page->fill('@create-webhook-endpoint', 'not-a-url')
        ->click('@create-webhook-events-post-published')
        ->click('@create-webhook-submit');

    $page->assertSee(__('validation.url', ['attribute' => __('webhooks.create.endpoint')]))
        ->assertVisible('@create-webhook-dialog');

    expect(Webhook::query()->count())->toBe(0);

    $page->fill('@create-webhook-endpoint', 'https://example.com/hooks')
        ->click('@create-webhook-submit');
    waitForWebhookCreateTestIdGone($page, 'create-webhook-dialog');

    $webhook = Webhook::query()->where('workspace_id', $user->current_workspace_id)->sole();
    $showPath = parse_url(route('app.webhooks.show', $webhook), PHP_URL_PATH);
    $page->script(<<<JS
        (async () => {
            for (let i = 0; i < 100; i++) {
                if (location.pathname === '{$showPath}' && document.body.innerText.includes('example.com/hooks')) return;
                await new Promise((r) => setTimeout(r, 50));
            }
        })();
    JS);

    $page->assertMissing('@create-webhook-dialog')
        ->assertUrlIs(route('app.webhooks.show', $webhook))
        ->assertSee('example.com/hooks')
        ->assertScript("document.querySelector('[data-sonner-toast]') === null", true)
        ->assertNoJavaScriptErrors();
});

test('cancel closes the dialog without creating a webhook', function () {
    $this->actingAs(webhookCreateDialogAdmin());

    $page = visit(route('app.webhooks.index'));
    waitForWebhookCreateTestId($page, 'webhooks-empty-create');
    $page->click('@webhooks-empty-create');
    waitForWebhookCreateTestId($page, 'create-webhook-endpoint');

    $page->fill('@create-webhook-endpoint', 'https://example.com/hooks')
        ->click('@create-webhook-events-post-published')
        ->click('@cancel-create-webhook');
    waitForWebhookCreateTestIdGone($page, 'create-webhook-dialog');

    $page->assertMissing('@create-webhook-dialog')
        ->assertNoJavaScriptErrors();

    expect(Webhook::query()->count())->toBe(0);
});

test('a workspace without webhooks shows only the centered illustration with a create button', function () {
    $this->actingAs(webhookCreateDialogAdmin());

    $page = visit(route('app.webhooks.index'));
    waitForWebhookCreateTestId($page, 'webhooks-empty');

    $page->assertVisible('@webhooks-empty-illustration')
        ->assertSeeIn('@webhooks-empty', __('webhooks.empty_title'))
        ->assertMissing('@header-title')
        ->assertMissing('@create-webhook-button');

    expect($page->script(<<<'JS'
        (() => {
            const scroller = document.querySelector('[data-testid="app-layout-scroller"]').getBoundingClientRect();
            const empty = document.querySelector('[data-testid="webhooks-empty"]').getBoundingClientRect();
            return Math.abs((empty.top + empty.height / 2) - (scroller.top + scroller.height / 2)) <= 24
                && Math.abs((empty.left + empty.width / 2) - (scroller.left + scroller.width / 2)) <= 24;
        })()
    JS))->toBeTrue();

    $page->assertNoJavaScriptErrors();
});

test('a workspace with webhooks keeps the header and the list at the top', function () {
    $user = webhookCreateDialogAdmin();
    Webhook::factory()->create(['workspace_id' => $user->current_workspace_id]);
    $this->actingAs($user);

    $page = visit(route('app.webhooks.index'));
    waitForWebhookCreateTestId($page, 'header-title');

    $page->assertVisible('@create-webhook-button')
        ->assertMissing('@webhooks-empty')
        ->assertMissing('@settings-centered')
        ->assertNoJavaScriptErrors();
});
