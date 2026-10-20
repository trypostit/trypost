<?php

declare(strict_types=1);

namespace App\Jobs\PostHog;

use App\Enums\AccessToken\McpClient;
use App\Enums\PostHog\McpEvent;
use App\Models\AccessToken;
use App\Models\Workspace;
use App\Services\PostHogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TrackMcpToolCalled implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public string $userId,
        public string $workspaceId,
        public ?string $accessTokenId,
        public ?string $tool,
    ) {
        $this->onQueue('posthog');
    }

    public function handle(PostHogService $postHog): void
    {
        if (! PostHogService::isEnabled()) {
            return;
        }

        $account = Workspace::query()->with('account.plan')->find($this->workspaceId)?->account;

        if (blank($account)) {
            return;
        }

        $client = filled($this->accessTokenId)
            ? AccessToken::query()->with('client')->find($this->accessTokenId)?->client
            : null;

        $postHog->capture(
            $this->userId,
            McpEvent::ToolCalled->value,
            [
                'tool' => $this->tool,
                'client' => McpClient::fromClientName((string) $client?->name)->value,
                'workspace_id' => $this->workspaceId,
            ],
            $account,
        );
    }
}
