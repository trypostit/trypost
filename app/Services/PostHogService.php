<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\PostHog\SendEvent;
use App\Models\Account;
use Illuminate\Support\Facades\Log;
use Throwable;

class PostHogService
{
    public static function isEnabled(): bool
    {
        return (bool) config('services.posthog.enabled')
            && (bool) config('services.posthog.api_key');
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function capture(string $distinctId, string $event, array $properties = [], ?Account $account = null): void
    {
        $payload = [
            'distinctId' => $distinctId,
            'event' => $event,
            'properties' => $properties,
        ];

        if ($account) {
            $payload['properties']['$groups'] = ['account' => (string) $account->id];
            $payload['properties']['account_id'] = (string) $account->id;
            $payload['properties']['plan'] = $account->plan?->name;
        }

        $this->logLocally('capture', $payload);

        if (self::isEnabled()) {
            $this->dispatch('capture', $payload);
        }
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function identify(string $distinctId, array $properties = []): void
    {
        $payload = [
            'distinctId' => $distinctId,
            'properties' => $properties,
        ];

        $this->logLocally('identify', $payload);

        if (self::isEnabled()) {
            $this->dispatch('identify', $payload);
        }
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function groupIdentify(string $groupType, string $groupKey, array $properties = []): void
    {
        $payload = [
            'groupType' => $groupType,
            'groupKey' => $groupKey,
            'properties' => $properties,
        ];

        $this->logLocally('groupIdentify', $payload);

        if (self::isEnabled()) {
            $this->dispatch('groupIdentify', $payload);
        }
    }

    /**
     * Local-only visibility into what would be sent to PostHog, so events can
     * be verified from laravel.log without a real API key configured.
     *
     * @param  array<string, mixed>  $payload
     */
    private function logLocally(string $method, array $payload): void
    {
        if (! app()->environment('local')) {
            return;
        }

        Log::info("PostHogService: {$method}", $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatch(string $method, array $payload): void
    {
        try {
            SendEvent::dispatch($method, $payload);
        } catch (Throwable $e) {
            Log::warning('PostHogService: failed to dispatch event', ['method' => $method, 'error' => $e->getMessage()]);
        }
    }
}
