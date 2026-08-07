<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\PostHog\SendEvent;
use App\Models\Account;
use Illuminate\Support\Facades\Cache;
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
        if (! self::isEnabled()) {
            return;
        }

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

        $this->dispatch('capture', $payload);
    }

    /**
     * Capture at most once per dedupe key. Skips the cache claim when PostHog
     * is off (or `$when` is false) so enabling later can still fire.
     *
     * @param  array<string, mixed>  $properties
     */
    public function captureOnce(
        string $dedupeKey,
        string $distinctId,
        string $event,
        array $properties = [],
        ?Account $account = null,
        bool $when = true,
    ): void {
        if (! $when || ! self::isEnabled() || ! Cache::add($dedupeKey, true, now()->addYear())) {
            return;
        }

        try {
            $this->capture($distinctId, $event, $properties, $account);
        } catch (Throwable $exception) {
            Cache::forget($dedupeKey);

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function identify(string $distinctId, array $properties = []): void
    {
        if (! self::isEnabled()) {
            return;
        }

        $this->dispatch('identify', [
            'distinctId' => $distinctId,
            'properties' => $properties,
        ]);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function groupIdentify(string $groupType, string $groupKey, array $properties = []): void
    {
        if (! self::isEnabled()) {
            return;
        }

        $this->dispatch('groupIdentify', [
            'groupType' => $groupType,
            'groupKey' => $groupKey,
            'properties' => $properties,
        ]);
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
