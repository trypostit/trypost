<?php

declare(strict_types=1);

namespace App\Jobs\Gtm;

use App\Services\GtmServerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendServerEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 15;

    /**
     * @param  array<string, mixed>  $properties
     */
    public function __construct(
        public string $event,
        public ?string $distinctId,
        public array $properties = [],
    ) {
        $this->onQueue('gtm');
    }

    public function handle(): void
    {
        if (! GtmServerService::isEnabled()) {
            return;
        }

        $apiSecret = config('services.gtm.backend.api_secret');
        $request = Http::acceptJson();

        if ($apiSecret) {
            $request = $request->withToken((string) $apiSecret);
        }

        $response = $request->post((string) config('services.gtm.backend.endpoint'), [
            'event' => $this->event,
            'distinct_id' => $this->distinctId,
            'properties' => $this->properties,
        ]);

        if ($response->failed()) {
            Log::warning('GtmServerService: server container rejected event', [
                'event' => $this->event,
                'status' => $response->status(),
            ]);
        }
    }
}
