<?php

declare(strict_types=1);

namespace App\Jobs\Ai;

use App\Ai\Agents\PostContentStreamer;
use App\Models\Workspace;
use App\Services\Ai\RecordAiUsage;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\StreamedAgentResponse;

class StreamPostContent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $workspaceId,
        public string $userId,
        public string $generationId,
        public string $prompt,
        public ?string $currentContent,
    ) {
        $this->onQueue('ai');
    }

    public function handle(): void
    {
        $workspace = Workspace::findOrFail($this->workspaceId);

        $agent = new PostContentStreamer(
            workspace: $workspace,
            currentContent: $this->currentContent,
        );

        $channel = new PrivateChannel("user.{$this->userId}.ai-gen.{$this->generationId}");

        try {
            /** @var Meta|null $meta */
            $meta = null;

            $response = $agent->broadcast($this->prompt, $channel, now: true)
                ->then(function (StreamedAgentResponse $streamed) use (&$meta): void {
                    $meta = $streamed->meta;
                });

            RecordAiUsage::recordText(
                workspace: $workspace,
                promptTokens: $response->usage?->promptTokens ?? 0,
                completionTokens: $response->usage?->completionTokens ?? 0,
                provider: (string) $meta?->provider,
                model: (string) $meta?->model,
                userId: $this->userId,
                metadata: ['agent' => 'post_streamer'],
            );
        } catch (\Throwable $e) {
            Log::error('PostContentGenerator stream failed', [
                'generation_id' => $this->generationId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
