<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Webhook;
use App\Models\Workspace;

function waitForSettingsListRowTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            const sel = '[data-testid="{$testId}"]';
            for (let i = 0; i < 100; i++) {
                const el = document.querySelector(sel);
                if (el && el.getBoundingClientRect().height > 0) return;
                await new Promise((r) => setTimeout(r, 50));
            }
        })();
    JS);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $this->actingAs($this->user);
});

test('api key rows render through the shared settings list row', function () {
    passportToken($this->user, $this->workspace);
    $token = $this->user->tokens()->firstOrFail();

    $row = "[data-testid=\"api-key-row-{$token->id}\"]";

    $page = visit(route('app.api-keys.index'));
    waitForSettingsListRowTestId($page, "api-key-row-{$token->id}");

    $page
        ->assertVisible($row)
        ->assertSeeIn($row, 'Test')
        ->assertScript("document.querySelector('{$row}').tagName", 'LI')
        ->assertScript("document.querySelectorAll('{$row} > span svg').length", 1)
        ->assertVisible("{$row} button[aria-label=\"Test\"]")
        ->assertNoJavaScriptErrors();
});

test('webhook rows open the webhook when clicked', function () {
    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
        'endpoint' => 'https://example.com/hooks/trypost',
    ]);

    $page = visit(route('app.webhooks.index'));
    waitForSettingsListRowTestId($page, "webhook-row-{$webhook->id}");

    $page
        ->assertSeeIn("@webhook-row-{$webhook->id}", 'example.com/hooks/trypost')
        ->click("@webhook-row-{$webhook->id}")
        ->assertPathIs(parse_url(route('app.webhooks.show', $webhook), PHP_URL_PATH))
        ->assertNoJavaScriptErrors();
});
