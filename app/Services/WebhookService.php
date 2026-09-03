<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Webhook\EventType as WebhookEvent;
use App\Jobs\DispatchWebhook;
use App\Models\Post;
use App\Models\Webhook;
use App\Models\Workspace;
use App\Services\Brand\SafeHttpFetcher;
use Exception;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WebhookService
{
    public function __construct(private SafeHttpFetcher $safeHttp) {}

    /**
     * @throws RuntimeException
     */
    public function ping(string $endpoint): void
    {
        try {
            $this->safeHttp->guardAgainstSsrf($endpoint);
        } catch (RuntimeException) {
            throw new RuntimeException(__('webhooks.errors.endpoint_not_allowed'));
        }

        try {
            $response = Http::timeout(5)
                ->withUserAgent(config('trypost.user_agent'))
                ->withOptions(['allow_redirects' => false])
                ->post($endpoint, [
                    'type' => 'webhook.test',
                    'data' => [],
                ]);
        } catch (Exception) {
            throw new RuntimeException(__('webhooks.errors.endpoint_unreachable'));
        }

        if (! $response->successful()) {
            throw new RuntimeException(__('webhooks.errors.endpoint_http_status', [
                'status' => $response->status(),
            ]));
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(Workspace $workspace, WebhookEvent $event, array $payload): void
    {
        $webhooks = Webhook::query()
            ->where('workspace_id', $workspace->id)
            ->enabled()
            ->get();

        foreach ($webhooks as $webhook) {
            $events = $webhook->events ?? [];

            if (in_array($event->value, $events, true)) {
                DispatchWebhook::dispatch($webhook, $event->value, $payload);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function postPayload(Post $post): array
    {
        return [
            'id' => $post->id,
            'workspace_id' => $post->workspace_id,
            'status' => $post->status->value,
            'content' => $post->content,
            'scheduled_at' => $post->scheduled_at?->toIso8601String(),
            'published_at' => $post->published_at?->toIso8601String(),
        ];
    }
}
