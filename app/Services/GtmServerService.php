<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\Gtm\SendServerEvent;
use Illuminate\Support\Facades\Log;
use Throwable;

class GtmServerService
{
    public static function isEnabled(): bool
    {
        return (bool) config('services.gtm.backend.enabled')
            && (bool) config('services.gtm.backend.endpoint');
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function capture(string $event, array $properties = [], ?string $distinctId = null): void
    {
        if (! self::isEnabled()) {
            return;
        }

        try {
            SendServerEvent::dispatch($event, $distinctId, $properties);
        } catch (Throwable $e) {
            Log::warning('GtmServerService: failed to dispatch event', ['event' => $event, 'error' => $e->getMessage()]);
        }
    }
}
