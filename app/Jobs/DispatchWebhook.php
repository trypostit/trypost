<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Webhook\Status;
use App\Events\Webhook\LogUpdated;
use App\Mail\WebhookPausedMail;
use App\Models\Webhook;
use App\Models\WebhookLog;
use App\Services\Http\SafeHttpFetcher;
use App\Services\WebhookService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class DispatchWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const int RESPONSE_BODY_BYTES = 2000;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 3;

    public int $timeout = 30;

    public int $backoff = 60;

    public string $logId;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public Webhook $webhook,
        public string $eventType,
        public array $payload,
        public bool $force = false,
    ) {
        $this->onQueue('webhooks');
        $this->logId = (string) Str::uuid();
    }

    public function handle(SafeHttpFetcher $safeHttp, WebhookService $webhooks): void
    {
        $this->webhook->refresh();

        if (! $this->force && $this->webhook->status !== Status::Enabled) {
            return;
        }

        $body = [
            'id' => $this->logId,
            'type' => $this->eventType,
            'data' => $this->payload,
            'created_at' => now()->toIso8601String(),
        ];

        $signature = $webhooks->signature($body, $this->webhook->signing_secret);

        $log = WebhookLog::query()->find($this->logId);

        if ($log) {
            $log->update([
                'payload' => $body,
                'attempts' => $this->attempts(),
                'failed_at' => null,
                'response_status' => null,
                'response_body' => null,
                'delivered_at' => null,
            ]);
        } else {
            $log = new WebhookLog([
                'webhook_id' => $this->webhook->id,
                'event_type' => $this->eventType,
                'payload' => $body,
                'attempts' => $this->attempts(),
            ]);
            $log->id = $this->logId;
            $log->save();
        }

        try {
            $response = $safeHttp->guardedRequest($this->webhook->endpoint, followRedirects: false)
                ->timeout(10)
                ->withUserAgent(config('trypost.user_agent'))
                ->asJson()
                ->withHeaders([
                    'X-Webhook-Signature' => $signature,
                ])
                ->withOptions(['stream' => true])
                ->post($this->webhook->endpoint, $body);
        } catch (ConnectionException|RuntimeException $e) {
            $log->update([
                'failed_at' => now(),
                'response_body' => $e->getMessage(),
            ]);

            $log->refresh();
            LogUpdated::dispatch($log);

            throw $e;
        }

        $responseBody = mb_scrub(str_replace("\0", '', $safeHttp->bodyPrefix($response, self::RESPONSE_BODY_BYTES)), 'UTF-8');

        if ($response->successful()) {
            $log->update([
                'response_status' => $response->status(),
                'response_body' => $responseBody,
                'delivered_at' => now(),
            ]);

            $this->webhook->update(['last_sent_at' => now()]);
            $this->webhook->resetConsecutiveFailures();

            $log->refresh();
            LogUpdated::dispatch($log);

            return;
        }

        $log->update([
            'response_status' => $response->status(),
            'response_body' => $responseBody,
            'failed_at' => now(),
        ]);

        $log->refresh();
        LogUpdated::dispatch($log);

        throw new RuntimeException("Webhook delivery failed with status: {$response->status()}");
    }

    public function failed(?Throwable $exception): void
    {
        $this->webhook->refresh();

        if ($this->webhook->status !== Status::Enabled) {
            return;
        }

        $this->webhook->increment('consecutive_failures');
        $this->webhook->refresh();

        if ($this->webhook->consecutive_failures >= 5) {
            $this->webhook->pause();

            $owner = $this->webhook->workspace?->account?->owner;

            if ($owner?->email) {
                Mail::to($owner)->send(new WebhookPausedMail($this->webhook));
            }
        }
    }
}
