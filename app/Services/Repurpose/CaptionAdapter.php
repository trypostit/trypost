<?php

declare(strict_types=1);

namespace App\Services\Repurpose;

use App\Ai\Agents\PostContentShortener;
use App\Enums\SocialAccount\Platform;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Ai\RecordAiUsage;
use App\Services\Social\ContentSanitizer;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fits a caption written for one network into another network's hard limit.
 *
 * The caption is left alone whenever it already fits, so the author's own
 * words survive in the common case. AI is only spent on a real overflow, and
 * a workspace without AI access still publishes: it falls back to a clean cut
 * rather than failing the post.
 */
class CaptionAdapter
{
    public function __construct(private readonly ContentSanitizer $sanitizer) {}

    public function adapt(
        Workspace $workspace,
        ?User $user,
        string $caption,
        Platform $platform,
        ?string $postId,
    ): string {
        $overflow = $platform->contentOverflow($this->sanitizer->displayText($caption, $platform));

        if ($overflow === 0) {
            return $caption;
        }

        $limit = mb_strlen($caption) - $overflow;

        if (! $this->canUseAi($workspace, $user)) {
            return $this->truncate($caption, $limit);
        }

        return $this->shorten($workspace, $user, $caption, $platform, $postId, $limit)
            ?? $this->truncate($caption, $limit);
    }

    private function shorten(
        Workspace $workspace,
        ?User $user,
        string $caption,
        Platform $platform,
        ?string $postId,
        int $limit,
    ): ?string {
        try {
            $agent = new PostContentShortener(
                workspace: $workspace,
                platformLabel: $platform->label(),
                limit: $limit,
            );

            $result = $agent->prompt($caption);

            RecordAiUsage::recordText(
                workspace: $workspace,
                promptTokens: $result->usage->promptTokens,
                completionTokens: $result->usage->completionTokens,
                provider: (string) $result->meta->provider,
                model: (string) $result->meta->model,
                userId: $user?->id,
                postId: $postId,
                metadata: ['agent' => 'post_shortener'],
            );

            $shortened = trim((string) $result->text);

            if ($shortened === '' || $platform->contentOverflow($this->sanitizer->displayText($shortened, $platform)) > 0) {
                return null;
            }

            return $shortened;
        } catch (Throwable $exception) {
            Log::warning('Caption shortening failed, falling back to truncation', [
                'workspace_id' => $workspace->id,
                'platform' => $platform->value,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function canUseAi(Workspace $workspace, ?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return Gate::forUser($user)->allows('useAi', $workspace->account);
    }

    /**
     * Cuts on the last word boundary that fits, so the caption never ends
     * mid-word.
     */
    private function truncate(string $caption, int $limit): string
    {
        $trimmed = rtrim(mb_substr($caption, 0, $limit));

        $lastSpace = mb_strrpos($trimmed, ' ');

        if ($lastSpace !== false && $lastSpace > 0) {
            $trimmed = mb_substr($trimmed, 0, $lastSpace);
        }

        return rtrim($trimmed);
    }
}
