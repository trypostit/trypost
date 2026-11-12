<?php

declare(strict_types=1);

use App\Enums\Webhook\EventType;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookLog;
use App\Models\Workspace;
use App\Services\WebhookService;

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
    waitFor($page, '@webhook-endpoint');

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

    waitForPath($page, route('app.webhooks.index', absolute: false));

    expect($page->script('location.pathname'))->toBe(route('app.webhooks.index', absolute: false));
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
    waitFor($page, '@webhook-log-detail');

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
    waitForState($page, '@webhook-log-payload', "element.textContent.includes('older-delivery')");

    $page->assertSeeIn('@webhook-log-payload', 'older-delivery')
        ->assertSeeIn('@webhook-log-title', __('webhooks.events.post_published'))
        ->assertAttribute("@webhook-log-{$older->id}", 'aria-current', 'true')
        ->assertScript("document.querySelector('[role=\"dialog\"]') === null", true)
        ->assertNoJavaScriptErrors();
});

test('the actions menu pauses, edits and deletes the webhook after a plain confirmation', function () {
    [$user, $webhook] = webhookShowFixture();
    $this->actingAs($user);

    $page = visit(route('app.webhooks.show', $webhook));
    waitFor($page, '@webhook-actions-trigger');
    $page->click('@webhook-actions-trigger');
    waitFor($page, '@toggle-webhook-status');

    $page->assertMissing('@copy-id-button')
        ->assertVisible('@rotate-secret-menu-item')
        ->click('@toggle-webhook-status');

    $disabled = __('webhooks.status.disabled');
    waitForState($page, '@webhook-status', "element.textContent.includes('{$disabled}')");

    $page->assertSeeIn('@webhook-status', $disabled);
    expect($webhook->fresh()->status->value)->toBe('disabled');

    waitForGone($page, '@toggle-webhook-status');
    $page->click('@webhook-actions-trigger');
    waitFor($page, '@edit-webhook-button');
    $page->click('@edit-webhook-button');
    waitFor($page, '@edit-webhook-dialog');

    $page->assertValue('@edit-webhook-endpoint', 'https://hooks.example.com/trypost/inbound')
        ->click('@cancel-edit-webhook');
    waitForGone($page, '@edit-webhook-dialog');

    $page->click('@webhook-actions-trigger');
    waitFor($page, '@delete-webhook-button');
    $page->click('@delete-webhook-button');
    waitFor($page, '@confirm-delete-action');

    $page->assertMissing('@confirm-delete-input')
        ->click('@confirm-delete-action');

    $indexPath = route('app.webhooks.index', absolute: false);
    waitForPath($page, $indexPath);

    expect(Webhook::query()->whereKey($webhook->id)->exists())->toBeFalse()
        ->and($page->script('location.pathname'))->toBe($indexPath);
    $page->assertNoJavaScriptErrors();
});

test('send test event pings the endpoint from the header and from the empty deliveries state', function () {
    [$user, $webhook] = webhookShowFixture();
    $pings = 0;
    $this->mock(WebhookService::class)
        ->shouldReceive('ping')
        ->twice()
        ->with('https://hooks.example.com/trypost/inbound', Mockery::type('string'))
        ->andReturnUsing(function () use (&$pings): void {
            $pings++;
        });
    $this->actingAs($user);

    $page = visit(route('app.webhooks.show', $webhook));
    waitFor($page, '@webhook-deliveries-empty');

    $page->assertSeeIn('@webhook-deliveries-empty', __('webhooks.deliveries.empty_title'))
        ->assertSeeIn('@webhook-deliveries-empty', __('webhooks.deliveries.empty_description'))
        ->assertVisible('@webhooks-empty-illustration')
        ->assertMissing('@webhook-log-list')
        ->click('@webhook-deliveries-send-test');
    waitForState($page, '[data-sonner-toast]', 'true');

    $page->assertSee(__('webhooks.flash.tested'));

    expect($page->script(<<<'JS'
        (() => {
            const toast = document.querySelector('[data-sonner-toast]');
            return [toast.querySelector('[data-icon] svg')?.classList.contains('tabler-icon') ?? false, Math.round(toast.getBoundingClientRect().width) >= 356];
        })()
    JS))->toBe([true, true]);

    $page->click('@send-test-webhook');
    waitForDatabase($page, fn (): bool => $pings === 2);

    $page->assertSee(__('webhooks.flash.tested'))->assertNoJavaScriptErrors();
    expect($pings)->toBe(2);
});

test('a live delivery update never shows the same delivery twice', function () {
    [$user, $webhook] = webhookShowFixture();
    WebhookLog::factory()->count(2)->create(['webhook_id' => $webhook->id, 'created_at' => now()->subHour()]);
    $this->actingAs($user);

    $page = visit(route('app.webhooks.show', $webhook));
    waitFor($page, '@webhook-actions-trigger');

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
    waitForCount($page, '[data-testid^="webhook-log-status-"]', 3, '>=');
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
