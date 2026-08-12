<?php

declare(strict_types=1);

use App\Enums\PostHog\TrialEvent;
use App\Enums\User\Persona;
use App\Jobs\PostHog\SendEvent;
use App\Jobs\PostHog\TrackTrialStarted;
use App\Models\Account;
use App\Models\Plan;
use App\Models\User;
use App\Services\PostHogService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test_key']);

    $this->plan = Plan::where('slug', 'workspace')->firstOrFail();
    $this->plan->update([
        'stripe_monthly_price_id' => 'price_workspace_monthly',
        'stripe_yearly_price_id' => 'price_workspace_yearly',
    ]);

    $this->account = Account::factory()->create(['plan_id' => $this->plan->id]);
    $this->user = User::factory()->create(['account_id' => $this->account->id]);
    $this->account->update(['owner_id' => $this->user->id]);

    $this->trialEnd = now()->addDays(8);

    $this->payload = [
        'type' => 'customer.subscription.created',
        'data' => ['object' => [
            'id' => 'sub_test123',
            'customer' => 'cus_test123',
            'status' => 'trialing',
            'trial_end' => $this->trialEnd->timestamp,
            'items' => ['data' => [[
                'price' => ['id' => 'price_workspace_monthly'],
            ]]],
        ]],
    ];
});

test('job is queued on the posthog queue', function () {
    $job = new TrackTrialStarted((string) $this->account->id, $this->payload);

    expect($job->queue)->toBe('posthog');
});

test('handle captures trial.started with plan, interval and trial_ends_at', function () {
    Queue::fake();

    (new TrackTrialStarted((string) $this->account->id, $this->payload))
        ->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, function (SendEvent $job) {
        return $job->method === 'capture'
            && $job->payload['event'] === TrialEvent::Started->value
            && $job->payload['distinctId'] === (string) $this->user->id
            && $job->payload['properties']['$groups']['account'] === (string) $this->account->id
            && $job->payload['properties']['plan_name'] === $this->plan->name
            && $job->payload['properties']['interval'] === 'monthly'
            && $job->payload['properties']['trial_ends_at'] === $this->trialEnd->toIso8601String();
    });
});

test('handle never includes conversion properties — no charge has happened yet', function () {
    Queue::fake();

    (new TrackTrialStarted((string) $this->account->id, $this->payload))
        ->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, function (SendEvent $job) {
        $properties = $job->payload['properties'];

        return ! array_key_exists('conversion_value', $properties)
            && ! array_key_exists('conversion_currency', $properties)
            && ! array_key_exists('conversion_transaction_id', $properties);
    });
});

test('handle resolves the yearly interval from the price id', function () {
    $this->payload['data']['object']['items']['data'][0]['price']['id'] = 'price_workspace_yearly';
    Queue::fake();

    (new TrackTrialStarted((string) $this->account->id, $this->payload))
        ->handle(app(PostHogService::class));

    Queue::assertPushed(
        SendEvent::class,
        fn (SendEvent $job) => $job->payload['properties']['interval'] === 'yearly',
    );
});

test('handle does not forward persona — it is already an identified person property', function () {
    $this->user->update(['persona' => Persona::Agency->value]);
    Queue::fake();

    (new TrackTrialStarted((string) $this->account->id, $this->payload))
        ->handle(app(PostHogService::class));

    Queue::assertPushed(
        SendEvent::class,
        fn (SendEvent $job) => ! array_key_exists('persona', $job->payload['properties']),
    );
});

test('handle returns silently when account does not exist', function () {
    Queue::fake();

    (new TrackTrialStarted('00000000-0000-0000-0000-000000000000', $this->payload))
        ->handle(app(PostHogService::class));

    Queue::assertNothingPushed();
});

test('handle returns silently when account has no owner', function () {
    $this->account->update(['owner_id' => null]);
    Queue::fake();

    (new TrackTrialStarted((string) $this->account->id, $this->payload))
        ->handle(app(PostHogService::class));

    Queue::assertNothingPushed();
});

test('handle returns silently when account has no plan', function () {
    $this->account->update(['plan_id' => null]);
    Queue::fake();

    (new TrackTrialStarted((string) $this->account->id, $this->payload))
        ->handle(app(PostHogService::class));

    Queue::assertNothingPushed();
});

test('handle does not push a PostHog network call when api key is unset', function () {
    config(['services.posthog.api_key' => null]);
    Queue::fake();

    (new TrackTrialStarted((string) $this->account->id, $this->payload))
        ->handle(app(PostHogService::class));

    Queue::assertNotPushed(SendEvent::class);
});

test('handle does not push a PostHog network call when disabled in production', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['services.posthog.enabled' => false, 'services.posthog.api_key' => null]);
    Queue::fake();
    Log::shouldReceive('info')->never();

    (new TrackTrialStarted((string) $this->account->id, $this->payload))
        ->handle(app(PostHogService::class));

    Queue::assertNotPushed(SendEvent::class);
});

test('handle logs locally but still does not push a PostHog network call in the local environment when disabled', function () {
    app()->detectEnvironment(fn () => 'local');
    config(['services.posthog.enabled' => false, 'services.posthog.api_key' => null]);
    Queue::fake();

    Log::shouldReceive('info')->once()->withArgs(
        fn ($message) => $message === 'PostHogService: capture',
    );

    (new TrackTrialStarted((string) $this->account->id, $this->payload))
        ->handle(app(PostHogService::class));

    // shouldTrack() lets handle() run (and capture() logs locally, asserted
    // above), but the real PostHog dispatch stays gated on isEnabled() alone.
    Queue::assertNotPushed(SendEvent::class);
});
