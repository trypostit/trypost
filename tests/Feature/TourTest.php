<?php

declare(strict_types=1);

use App\Enums\PostHog\TourEvent;
use App\Jobs\PostHog\CaptureAccountEvent;
use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PostHogService;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    subscribeAccount($this->user->account);
});

test('how a tour ended is tracked for the workspace account, with the step reached', function (string $outcome, TourEvent $event, int $step) {
    enablePostHog();
    Queue::fake([CaptureAccountEvent::class]);

    $this->actingAs($this->user)
        ->post(route('app.tours.store'), ['tour' => 'publish.views', 'outcome' => $outcome, 'step' => $step, 'steps' => 3])
        ->assertNoContent();

    Queue::assertPushedOn('posthog', CaptureAccountEvent::class, fn (CaptureAccountEvent $job): bool => $job->event === $event
        && $job->distinctId === (string) $this->user->id
        && $job->accountId === (string) $this->workspace->account_id
        && $job->properties === ['tour' => 'publish.views', 'step' => $step, 'steps' => 3, 'workspace_id' => $this->workspace->id]);
})->with([
    'completed' => ['completed', TourEvent::Completed, 3],
    'skipped' => ['skipped', TourEvent::Skipped, 2],
]);

test('an unknown tour or outcome, or a step past the last, is refused and tracks nothing', function (array $payload, string $field) {
    enablePostHog();
    Queue::fake([CaptureAccountEvent::class]);

    $this->actingAs($this->user)
        ->postJson(route('app.tours.store'), [...['tour' => 'publish.views', 'outcome' => 'completed', 'step' => 1, 'steps' => 1], ...$payload])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    Queue::assertNotPushed(CaptureAccountEvent::class);
})->with([
    'unknown tour' => [['tour' => 'publish.unknown'], 'tour'],
    'unknown outcome' => [['outcome' => 'closed'], 'outcome'],
    'step past the last' => [['step' => 3, 'steps' => 2], 'step'],
    'no step' => [['step' => 0], 'step'],
]);

test('the queued job hands the end of a tour to PostHog for the user and their account', function (string $outcome, string $event, int $step) {
    enablePostHog();

    $this->mock(PostHogService::class)
        ->shouldReceive('capture')
        ->once()
        ->withArgs(fn (string $distinctId, string $sent, array $properties, ?Account $account): bool => $distinctId === (string) $this->user->id
            && $sent === $event
            && $properties === ['tour' => 'insights.filters', 'step' => $step, 'steps' => 3, 'workspace_id' => $this->workspace->id]
            && $account?->is($this->user->account) === true);

    $this->actingAs($this->user)
        ->post(route('app.tours.store'), ['tour' => 'insights.filters', 'outcome' => $outcome, 'step' => $step, 'steps' => 3])
        ->assertNoContent();
})->with([
    'completed' => ['completed', 'tour.completed', 3],
    'skipped' => ['skipped', 'tour.skipped', 2],
]);
