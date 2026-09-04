<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\MediaItem;
use App\Enums\Media\Type;
use App\Enums\Webhook\EventType as WebhookEvent;
use App\Jobs\DispatchWebhook;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Webhook;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use App\Services\Brand\SafeHttpFetcher;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class WebhookService
{
    public function __construct(private SafeHttpFetcher $safeHttp) {}

    /**
     * @param  array<string, mixed>  $body
     */
    public function signature(array $body, string $secret): string
    {
        return hash_hmac('sha256', json_encode($body), $secret);
    }

    /**
     * @throws RuntimeException
     */
    public function assertEndpointAllowed(string $endpoint): void
    {
        try {
            $this->safeHttp->guardAgainstSsrf($endpoint);
        } catch (RuntimeException) {
            throw new RuntimeException(__('webhooks.errors.endpoint_not_allowed'));
        }
    }

    /**
     * @throws RuntimeException
     */
    public function ping(string $endpoint, string $signingSecret): void
    {
        $this->assertEndpointAllowed($endpoint);

        $body = [
            'id' => (string) Str::uuid(),
            'type' => 'webhook.test',
            'data' => (object) [],
            'created_at' => now()->toIso8601String(),
        ];

        try {
            $response = Http::timeout(5)
                ->withUserAgent(config('trypost.user_agent'))
                ->withOptions(['allow_redirects' => false])
                ->asJson()
                ->withHeaders([
                    'X-Webhook-Signature' => $this->signature($body, $signingSecret),
                ])
                ->post($endpoint, $body);
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
            if (in_array($event->value, $webhook->events ?? [], true)) {
                DispatchWebhook::dispatch($webhook, $event->value, $payload);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function postPayload(Post $post): array
    {
        $post->load(['user', 'workspace', 'labels', 'postPlatforms.socialAccount']);

        return [
            'id' => $post->id,
            'workspace_id' => $post->workspace_id,
            'user_id' => $post->user_id,
            'status' => $post->status->value,
            'created_via' => $post->created_via?->value,
            'content' => $post->content,
            'scheduled_at' => $post->scheduled_at?->toIso8601String(),
            'published_at' => $post->published_at?->toIso8601String(),
            'created_at' => $post->created_at?->toIso8601String(),
            'updated_at' => $post->updated_at?->toIso8601String(),
            'author' => $this->authorPayload($post->user),
            'workspace' => $this->workspacePayload($post),
            'labels' => $post->labels
                ->map(fn (WorkspaceLabel $label): array => [
                    'id' => $label->id,
                    'name' => $label->name,
                    'color' => $label->color,
                ])
                ->values()
                ->all(),
            'media' => $this->mediaPayload($post),
            'platforms' => $post->postPlatforms
                ->map(fn (PostPlatform $platform): array => $this->platformPayload($platform))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{id: string, name: string}|null
     */
    private function authorPayload(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
        ];
    }

    /**
     * @return array{id: string, name: string|null}
     */
    private function workspacePayload(Post $post): array
    {
        return [
            'id' => $post->workspace_id,
            'name' => $post->workspace?->name,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mediaPayload(Post $post): array
    {
        return collect($post->media ?? [])
            ->map(function (mixed $item): array {
                $media = MediaItem::fromArray(is_array($item) ? $item : []);

                return [
                    'id' => $media->id,
                    'path' => $media->path,
                    'url' => $media->url,
                    'type' => Type::classify($media->mime_type, $media->path)?->value,
                    'mime_type' => $media->mime_type,
                    'original_filename' => $media->original_filename,
                    'source' => $media->source?->value,
                    'source_meta' => $media->source_meta,
                    'meta' => $media->meta,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function platformPayload(PostPlatform $platform): array
    {
        return [
            'id' => $platform->id,
            'social_account_id' => $platform->social_account_id,
            'platform' => $platform->platform?->value,
            'content_type' => $platform->content_type?->value,
            'enabled' => $platform->enabled,
            'status' => $platform->status?->value,
            'platform_post_id' => $platform->platform_post_id,
            'platform_url' => $platform->platform_url,
            'published_at' => $platform->published_at?->toIso8601String(),
            'error_message' => $platform->error_message,
            'error_context' => $platform->error_context,
            'display_name' => $platform->display_name,
            'display_username' => $platform->display_username,
            'display_avatar' => $platform->display_avatar,
            'meta' => $platform->meta ?? [],
            'social_account' => $this->socialAccountPayload($platform->socialAccount),
        ];
    }

    /**
     * @return array{id: string, platform: string|null, display_name: string|null, username: string|null, is_active: bool, status: string|null}|null
     */
    private function socialAccountPayload(?SocialAccount $account): ?array
    {
        if ($account === null) {
            return null;
        }

        return [
            'id' => $account->id,
            'platform' => $account->platform?->value,
            'display_name' => $account->display_name,
            'username' => $account->username,
            'is_active' => $account->is_active,
            'status' => $account->status?->value,
        ];
    }
}
