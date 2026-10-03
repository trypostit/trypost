<?php

declare(strict_types=1);

use App\Enums\User\Locale;
use App\Enums\Webhook\EventType;
use App\Jobs\DispatchWebhook;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookLog;
use App\Models\Workspace;
use App\Services\WebhookService;
use Illuminate\Support\Facades\Queue;

function waitForWebhookShowTestId(mixed $page, string $testId): void
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

function waitForWebhookShowCondition(mixed $page, string $condition): void
{
    $page->script(<<<JS
        (async () => {
            for (let i = 0; i < 150; i++) {
                if ({$condition}) return;
                await new Promise((r) => setTimeout(r, 50));
            }
        })();
    JS);
}

/**
 * @return array{0: User, 1: Webhook}
 */
function webhookShowFixture(array $attributes = []): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    $webhook = Webhook::factory()->create([
        'workspace_id' => $workspace->id,
        'endpoint' => 'https://hooks.example.com/trypost/inbound',
        'events' => [EventType::PostPublished->value, EventType::PostFailed->value],
        ...$attributes,
    ]);

    return [$user->fresh(), $webhook];
}

test('the header shows the host, the path and the status', function () {
    [$user, $webhook] = webhookShowFixture();
    $this->actingAs($user);

    $page = visit(route('app.webhooks.show', $webhook));
    waitForWebhookShowTestId($page, 'webhook-endpoint');

    expect($page->script(<<<'JS'
        (() => {
            const spans = document.querySelectorAll('[data-testid="webhook-endpoint"] > span');
            return [spans[0].textContent.trim(), spans[1].textContent.trim()];
        })()
    JS))->toBe(['hooks.example.com', '/trypost/inbound']);

    $page->assertAttribute('@webhook-endpoint', 'title', 'https://hooks.example.com/trypost/inbound')
        ->assertSeeIn('@webhook-status', __('webhooks.status.enabled'))
        ->assertVisible('@webhook-status-dot')
        ->assertVisible('@webhook-show-page')
        ->assertSeeIn('@webhook-events', __('webhooks.events.post_published'))
        ->assertSeeIn('@webhook-events', __('webhooks.events.post_failed'))
        ->assertAttribute('[data-testid="webhook-event-post.failed"]', 'title', __('webhooks.event_descriptions.post_failed'))
        ->assertVisible('@signing-secret')
        ->click('@webhook-back');

    waitForWebhookShowCondition($page, "location.pathname === '".route('app.webhooks.index', absolute: false)."'");

    expect($page->script('location.pathname'))->toBe(route('app.webhooks.index', absolute: false));
    $page->assertNoJavaScriptErrors();
});

test('the actions menu edits the endpoint and deletes the webhook after a plain confirmation', function () {
    [$user, $webhook] = webhookShowFixture();
    $this->actingAs($user);

    $page = visit(route('app.webhooks.show', $webhook));
    waitForWebhookShowTestId($page, 'webhook-actions-trigger');
    $page->click('@webhook-actions-trigger');
    waitForWebhookShowTestId($page, 'edit-webhook-button');

    $page->assertVisible('@toggle-webhook-status')
        ->assertMissing('@copy-id-button')
        ->assertVisible('@rotate-secret-menu-item')
        ->click('@edit-webhook-button');
    waitForWebhookShowTestId($page, 'edit-webhook-dialog');

    $page->assertValue('@edit-webhook-endpoint', 'https://hooks.example.com/trypost/inbound')
        ->click('@cancel-edit-webhook');
    waitForWebhookShowCondition($page, "!document.querySelector('[data-testid=\"edit-webhook-dialog\"]')");

    $page->click('@webhook-actions-trigger');
    waitForWebhookShowTestId($page, 'delete-webhook-button');
    $page->click('@delete-webhook-button');
    waitForWebhookShowTestId($page, 'confirm-delete-action');

    $page->assertMissing('@confirm-delete-input')
        ->click('@confirm-delete-action');

    $indexPath = route('app.webhooks.index', absolute: false);
    waitForWebhookShowCondition($page, "location.pathname === '{$indexPath}'");

    expect(Webhook::query()->whereKey($webhook->id)->exists())->toBeFalse()
        ->and($page->script('location.pathname'))->toBe($indexPath);
    $page->assertNoJavaScriptErrors();
});

test('the actions menu pauses an enabled webhook', function () {
    [$user, $webhook] = webhookShowFixture();
    $this->actingAs($user);

    $page = visit(route('app.webhooks.show', $webhook));
    waitForWebhookShowTestId($page, 'webhook-actions-trigger');
    $page->click('@webhook-actions-trigger');
    waitForWebhookShowTestId($page, 'toggle-webhook-status');
    $page->click('@toggle-webhook-status');

    $disabled = __('webhooks.status.disabled');
    waitForWebhookShowCondition($page, "document.querySelector('[data-testid=\"webhook-status\"]').textContent.includes('{$disabled}')");

    $page->assertSeeIn('@webhook-status', $disabled)
        ->assertNoJavaScriptErrors();
    expect($webhook->fresh()->status->value)->toBe('disabled');
});

test('send test event pings the endpoint', function () {
    [$user, $webhook] = webhookShowFixture();
    $this->mock(WebhookService::class)
        ->shouldReceive('ping')
        ->once()
        ->with('https://hooks.example.com/trypost/inbound', Mockery::type('string'));
    $this->actingAs($user);

    $page = visit(route('app.webhooks.show', $webhook));
    waitForWebhookShowTestId($page, 'send-test-webhook');
    $page->click('@send-test-webhook');
    waitForWebhookShowCondition($page, "document.querySelector('[data-sonner-toast]') !== null");

    $page->assertSee(__('webhooks.flash.tested'));

    expect($page->script(<<<'JS'
        (() => {
            const toast = document.querySelector('[data-sonner-toast]');
            return [toast.querySelector('[data-icon] svg')?.classList.contains('tabler-icon') ?? false, Math.round(toast.getBoundingClientRect().width) >= 356];
        })()
    JS))->toBe([true, true]);

    $page->assertNoJavaScriptErrors();
});

test('the newest delivery is selected by default and selecting a row shows its payload inline', function () {
    [$user, $webhook] = webhookShowFixture();
    $older = WebhookLog::factory()->create([
        'webhook_id' => $webhook->id,
        'created_at' => now()->subHour(),
        'payload' => ['type' => 'post.published', 'data' => ['marker' => 'older-delivery']],
    ]);
    $newest = WebhookLog::factory()->failed()->create([
        'webhook_id' => $webhook->id,
        'event_type' => EventType::PostFailed->value,
        'payload' => ['type' => 'post.failed', 'data' => ['marker' => 'newest-delivery']],
    ]);
    $this->actingAs($user);

    $page = visit(route('app.webhooks.show', $webhook));
    waitForWebhookShowTestId($page, 'webhook-log-detail');

    $page->assertSeeIn("@webhook-log-status-{$older->id}", '200')
        ->assertSeeIn("@webhook-log-status-{$newest->id}", '500')
        ->assertAttribute("@webhook-log-{$newest->id}", 'aria-current', 'true')
        ->assertSeeIn('@webhook-log-title', __('webhooks.events.post_failed'))
        ->assertSeeIn('@webhook-log-payload', 'newest-delivery')
        ->assertSeeIn('@webhook-log-meta', '500')
        ->assertMissing('@webhook-deliveries-empty');

    expect($page->script("document.querySelector('[data-testid=\"webhook-log-status-{$newest->id}\"]').className"))
        ->toContain('destructive');

    $page->click("@webhook-log-{$older->id}");
    waitForWebhookShowCondition($page, "document.querySelector('[data-testid=\"webhook-log-payload\"]').textContent.includes('older-delivery')");

    $page->assertSeeIn('@webhook-log-payload', 'older-delivery')
        ->assertSeeIn('@webhook-log-title', __('webhooks.events.post_published'))
        ->assertAttribute("@webhook-log-{$older->id}", 'aria-current', 'true')
        ->assertScript("document.querySelector('[role=\"dialog\"]') === null", true)
        ->assertNoJavaScriptErrors();
});

test('replay from the detail pane dispatches the selected delivery again', function () {
    Queue::fake();
    [$user, $webhook] = webhookShowFixture();
    WebhookLog::factory()->create(['webhook_id' => $webhook->id]);
    $this->actingAs($user);

    $page = visit(route('app.webhooks.show', $webhook));
    waitForWebhookShowTestId($page, 'replay-log');
    $page->click('@replay-log');
    waitForWebhookShowCondition($page, "document.querySelector('[data-sonner-toast]') !== null");

    $page->assertSee(__('webhooks.flash.replayed'))
        ->assertNoJavaScriptErrors();
    Queue::assertPushed(DispatchWebhook::class, 1);
});

test('a webhook without deliveries shows the empty state with a send test button', function () {
    [$user, $webhook] = webhookShowFixture();
    $this->mock(WebhookService::class)->shouldReceive('ping')->once();
    $this->actingAs($user);

    $page = visit(route('app.webhooks.show', $webhook));
    waitForWebhookShowTestId($page, 'webhook-deliveries-empty');

    $page->assertSeeIn('@webhook-deliveries-empty', __('webhooks.deliveries.empty_title'))
        ->assertSeeIn('@webhook-deliveries-empty', __('webhooks.deliveries.empty_description'))
        ->assertVisible('@webhooks-empty-illustration')
        ->assertMissing('@webhook-log-list')
        ->click('@webhook-deliveries-send-test');
    waitForWebhookShowCondition($page, "document.querySelector('[data-sonner-toast]') !== null");

    $page->assertNoJavaScriptErrors();
});

test('header and section buttons and menu items stay on one line in every language', function () {
    [$user, $webhook] = webhookShowFixture();
    $wrapped = [];

    foreach (Locale::cases() as $locale) {
        $user->update(['locale' => $locale]);
        $this->actingAs($user->fresh());

        $page = visit(route('app.webhooks.show', $webhook));
        waitForWebhookShowTestId($page, 'webhook-actions-trigger');
        $page->click('@webhook-actions-trigger');
        waitForWebhookShowTestId($page, 'delete-webhook-button');

        $lines = $page->script(<<<'JS'
            [...document.querySelectorAll('[data-testid="send-test-webhook"], [data-testid="edit-webhook-events"], [data-testid="rotate-secret-button"], [data-testid="webhook-back"], [data-testid="webhook-deliveries-send-test"], [role="menuitem"]')]
                .map((element) => {
                    const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
                    const tops = new Set();
                    while (walker.nextNode()) {
                        const range = document.createRange();
                        range.selectNodeContents(walker.currentNode);
                        [...range.getClientRects()].filter((rect) => rect.width > 0).forEach((rect) => tops.add(Math.round(rect.top)));
                    }
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

test('a live delivery update never shows the same delivery twice', function () {
    [$user, $webhook] = webhookShowFixture();
    WebhookLog::factory()->count(2)->create(['webhook_id' => $webhook->id, 'created_at' => now()->subHour()]);
    $this->actingAs($user);

    $page = visit(route('app.webhooks.show', $webhook));
    waitForWebhookShowTestId($page, 'webhook-actions-trigger');

    $log = WebhookLog::factory()->create(['webhook_id' => $webhook->id]);
    $payload = json_encode([
        'id' => $log->id,
        'event_type' => $log->event_type,
        'response_status' => $log->response_status,
        'delivered_at' => $log->delivered_at?->toIso8601String(),
        'failed_at' => $log->failed_at?->toIso8601String(),
        'attempts' => $log->attempts,
        'created_at' => $log->created_at->toIso8601String(),
    ]);

    $page->script(<<<JS
        (async () => {
            const channel = window.Pusher.instances[0].channels.channels['private-webhook.{$webhook->id}.logs'];
            for (let i = 0; i < 3; i++) {
                channel.emit('webhook.log.updated', {$payload});
                await new Promise((resolve) => setTimeout(resolve, 400));
            }
        })()
    JS);
    waitForWebhookShowCondition($page, "document.querySelectorAll('[data-testid^=\"webhook-log-status-\"]').length >= 3");
    $page->script('new Promise((resolve) => setTimeout(resolve, 800))');

    expect($page->script(<<<'JS'
        (() => {
            const ids = [...document.querySelectorAll('[data-testid^="webhook-log-"]')]
                .map((row) => row.dataset.testid)
                .filter((id) => /^webhook-log-[0-9a-f-]{36}$/.test(id));
            return [ids.length, new Set(ids).size, document.querySelectorAll('[aria-current="true"][data-testid^="webhook-log-"]').length];
        })()
    JS))->toBe([3, 3, 1]);

    $page->assertNoJavaScriptErrors();
});
