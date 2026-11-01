<?php

declare(strict_types=1);

use App\Enums\PostHog\McpEvent;
use App\Jobs\PostHog\CaptureAccountEvent;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['trypost.self_hosted' => false]);

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    subscribeAccount($this->user->account);
});

test('a user inside the app without an AI connected is invited to connect one, right after signing up', function () {
    $this->actingAs($this->user->fresh())
        ->get(route('app.calendar'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('mcpPrompt.mcp_url', route('mcp.trypost')));
});

test('the invitation is not shared once an AI is connected or before the workspace can use the app', function (Closure $setup) {
    $setup($this->user, $this->workspace);

    $this->actingAs($this->user->fresh())
        ->followingRedirects()
        ->get(route('app.calendar'))
        ->assertInertia(fn ($page) => $page->where('mcpPrompt', null));
})->with([
    'connected' => [fn (User $user, Workspace $workspace) => mcpAccessToken($user, mcpOauthClient('Claude'), $workspace)],
    'no app access' => [fn (User $user) => $user->account->subscriptions()->delete()],
]);

test('a revoked connection invites the user again', function () {
    $token = mcpAccessToken($this->user, mcpOauthClient('Claude'), $this->workspace);
    $token->forceFill(['revoked' => true])->save();

    $this->actingAs($this->user->fresh())
        ->get(route('app.calendar'))
        ->assertInertia(fn ($page) => $page->where('mcpPrompt.mcp_url', route('mcp.trypost')));
});

test('a member of the account is invited too, and self-hosted needs no subscription', function (Closure $actor) {
    $user = $actor($this->workspace);

    $this->actingAs($user->fresh())
        ->get(route('app.calendar'))
        ->assertInertia(fn ($page) => $page->where('mcpPrompt.mcp_url', route('mcp.trypost')));
})->with([
    'member of a subscribed workspace' => [function (Workspace $workspace): User {
        $member = User::factory()->create(['account_id' => $workspace->account_id]);
        $workspace->members()->attach($member->id, membershipPivot('member'));
        $member->update(['current_workspace_id' => $workspace->id]);

        return $member;
    }],
    'self-hosted without a subscription' => [function (Workspace $workspace): User {
        config(['trypost.self_hosted' => true]);
        $workspace->account->subscriptions()->delete();

        return $workspace->owner;
    }],
]);

test('each answer to the invitation is tracked for the workspace account, with the app picked', function (array $payload, McpEvent $event, array $properties) {
    enablePostHog();
    Queue::fake([CaptureAccountEvent::class]);

    $this->actingAs($this->user)
        ->post(route('app.mcp.prompt.store'), $payload)
        ->assertNoContent();

    Queue::assertPushedOn('posthog', CaptureAccountEvent::class, fn (CaptureAccountEvent $job): bool => $job->event === $event
        && $job->distinctId === (string) $this->user->id
        && $job->accountId === (string) $this->workspace->account_id
        && $job->properties === ['workspace_id' => $this->workspace->id, ...$properties]);
})->with([
    'Claude' => [['answer' => 'connect', 'client' => 'claude'], McpEvent::PromptClicked, ['client' => 'claude']],
    'ChatGPT' => [['answer' => 'connect', 'client' => 'chatgpt'], McpEvent::PromptClicked, ['client' => 'chatgpt']],
    'another app' => [['answer' => 'connect', 'client' => 'other'], McpEvent::PromptClicked, ['client' => 'other']],
    'not now' => [['answer' => 'dismiss', 'client' => 'claude'], McpEvent::PromptDismissed, []],
]);

test('an unknown answer, or connecting without a known app, is refused and tracks nothing', function (array $payload, string $field) {
    enablePostHog();
    Queue::fake([CaptureAccountEvent::class]);

    $this->actingAs($this->user)
        ->postJson(route('app.mcp.prompt.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    Queue::assertNotPushed(CaptureAccountEvent::class);
})->with([
    'unknown answer' => [['answer' => 'later'], 'answer'],
    'connect without an app' => [['answer' => 'connect'], 'client'],
    'connect with an unknown app' => [['answer' => 'connect', 'client' => 'gemini'], 'client'],
]);
