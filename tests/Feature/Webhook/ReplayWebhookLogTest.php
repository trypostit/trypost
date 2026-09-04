<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Enums\Webhook\EventType;
use App\Jobs\DispatchWebhook;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookLog;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

test('authenticated users can replay a webhook log', function () {
    Queue::fake();

    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $log = WebhookLog::factory()->create([
        'webhook_id' => $webhook->id,
        'event_type' => EventType::PostPublished->value,
        'payload' => [
            'type' => EventType::PostPublished->value,
            'data' => ['id' => 'post-1'],
        ],
    ]);

    $this->actingAs($this->user)
        ->post(route('app.webhooks.replay', [$webhook, $log]))
        ->assertRedirect()
        ->assertSessionHas('flash.banner', __('webhooks.flash.replayed'));

    Queue::assertPushed(DispatchWebhook::class, function (DispatchWebhook $job) use ($webhook) {
        return $job->webhook->id === $webhook->id
            && $job->eventType === EventType::PostPublished->value
            && data_get($job->payload, 'id') === 'post-1'
            && $job->force;
    });
});

test('workspace members cannot replay a webhook log', function () {
    Queue::fake();

    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $this->workspace->id]);

    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $log = WebhookLog::factory()->create([
        'webhook_id' => $webhook->id,
    ]);

    $this->actingAs($member)
        ->post(route('app.webhooks.replay', [$webhook, $log]))
        ->assertForbidden();

    Queue::assertNothingPushed();
});

test('users cannot replay webhook logs from other workspaces', function () {
    $otherUser = User::factory()->create();
    $otherWorkspace = Workspace::factory()->create(['user_id' => $otherUser->id]);
    $webhook = Webhook::factory()->create([
        'workspace_id' => $otherWorkspace->id,
    ]);

    $log = WebhookLog::factory()->create([
        'webhook_id' => $webhook->id,
    ]);

    $this->actingAs($this->user)
        ->post(route('app.webhooks.replay', [$webhook, $log]))
        ->assertForbidden();
});

test('users cannot replay a webhook log belonging to a different webhook', function () {
    Queue::fake();

    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $otherWebhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $log = WebhookLog::factory()->create([
        'webhook_id' => $otherWebhook->id,
    ]);

    $this->actingAs($this->user)
        ->post(route('app.webhooks.replay', [$webhook, $log]))
        ->assertNotFound();

    Queue::assertNothingPushed();
});

test('guests cannot replay webhook logs', function () {
    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $log = WebhookLog::factory()->create([
        'webhook_id' => $webhook->id,
    ]);

    $this->post(route('app.webhooks.replay', [$webhook, $log]))
        ->assertRedirect(route('login'));
});
