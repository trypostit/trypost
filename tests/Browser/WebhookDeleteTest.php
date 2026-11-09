<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Webhook;
use App\Models\Workspace;

test('the webhook list shows the host and status, deletes without typing and opens the details from the menu', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $doomed = Webhook::factory()->create(['workspace_id' => $workspace->id]);
    $webhook = Webhook::factory()->create([
        'workspace_id' => $workspace->id,
        'endpoint' => 'https://hooks.example.com/trypost/inbound?token=abc',
    ]);
    $this->actingAs($user);

    $page = visit(route('app.webhooks.index'));
    waitFor($page, "@webhook-row-{$webhook->id}");

    $page->assertVisible('@create-webhook-button')
        ->assertVisible('@header-title')
        ->assertMissing('@webhooks-empty')
        ->assertMissing('@settings-centered');

    expect($page->script(<<<JS
        (() => {
            const spans = document.querySelectorAll('[data-testid="webhook-endpoint-{$webhook->id}"] span');
            return [spans[0].textContent.trim(), spans[1].textContent.trim()];
        })()
    JS))->toBe(['hooks.example.com', '/trypost/inbound?token=abc']);

    $page->assertSeeIn("@webhook-status-{$webhook->id}", __("webhooks.status.{$webhook->status->value}"))
        ->assertVisible("@webhook-status-dot-{$webhook->id}")
        ->click("[data-testid=\"webhook-row-{$doomed->id}\"] [data-testid=\"row-actions-trigger\"]");
    waitFor($page, '@delete-webhook-button');

    expect($page->script('location.pathname'))->toBe(route('app.webhooks.index', absolute: false));

    $page->click('@delete-webhook-button');
    waitFor($page, '@confirm-delete-action');

    $page->assertMissing('@confirm-delete-input')
        ->click('@confirm-delete-action');

    waitForGone($page, "@webhook-row-{$doomed->id}");

    expect(Webhook::query()->whereKey($doomed->id)->exists())->toBeFalse();

    $page->click("[data-testid=\"webhook-row-{$webhook->id}\"] [data-testid=\"row-actions-trigger\"]");
    waitFor($page, "@view-webhook-{$webhook->id}");

    $page->click("@view-webhook-{$webhook->id}");
    waitForCondition($page, "location.pathname.includes('/webhooks/')");

    expect($page->script('location.pathname'))->toBe(route('app.webhooks.show', $webhook, absolute: false));
    $page->assertNoJavaScriptErrors();
});
