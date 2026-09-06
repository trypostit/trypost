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

class CaptionAdapter
{
    public function __construct(private readonly ContentSanitizer $sanitizer) {}

    public function adapt(Workspace $workspace, ?User $user, string $caption, Platform $platform): string
    {
        if ($this->overflow($caption, $platform) === 0) {
            return $caption;
        }

        return $this->shorten($workspace, $user, $caption, $platform)
            ?? $this->truncate($caption, $platform);
    }

    /**
     * How far past the limit the text the publisher actually sends is. Sanitizing
     * moves the length both ways — HTML comes off, X rewrites every dot of a host
     * — so the raw caption is never the thing to measure.
     */
    private function overflow(string $caption, Platform $platform): int
    {
        return $platform->contentOverflow($this->sanitizer->displayText($caption, $platform));
    }

    /**
     * Null whenever the workspace cannot buy a rewrite or the model does not
     * deliver one that fits, which sends the caller to plain truncation.
     */
    private function shorten(Workspace $workspace, ?User $user, string $caption, Platform $platform): ?string
    {
        if ($user === null || Gate::forUser($user)->denies('useAi', $workspace->account)) {
            return null;
        }

        try {
            $result = (new PostContentShortener(
                workspace: $workspace,
                platformLabel: $platform->label(),
                limit: $platform->maxContentLength(),
            ))->prompt($caption);

            RecordAiUsage::recordText(
                workspace: $workspace,
                promptTokens: $result->usage->promptTokens,
                completionTokens: $result->usage->completionTokens,
                provider: (string) $result->meta->provider,
                model: (string) $result->meta->model,
                userId: $user->id,
                metadata: ['agent' => 'post_shortener'],
            );
        } catch (Throwable $exception) {
            Log::warning('Caption shortening failed, falling back to truncation', [
                'workspace_id' => $workspace->id,
                'platform' => $platform->value,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }

        $shortened = trim((string) $result->text);

        return $shortened !== '' && $this->overflow($shortened, $platform) === 0 ? $shortened : null;
    }

    /**
     * Shrinks the caption until the sanitized form fits, rescaling each pass by
     * how far it still overshoots. Cutting to the raw limit would miss by exactly
     * the amount sanitizing changes.
     */
    private function truncate(string $caption, Platform $platform): string
    {
        $trimmed = $caption;

        while ($trimmed !== '') {
            $sanitized = $this->sanitizer->displayText($trimmed, $platform);

            if ($platform->contentOverflow($sanitized) === 0) {
                return $trimmed;
            }

            $length = mb_strlen($trimmed);
            $target = (int) floor($length * $platform->maxContentLength() / mb_strlen($sanitized));

            $trimmed = $this->cutAtWord($trimmed, min($target, $length - 1));
        }

        return '';
    }

    private function cutAtWord(string $caption, int $limit): string
    {
        $trimmed = rtrim(mb_substr($caption, 0, max(1, $limit)));
        $lastSpace = mb_strrpos($trimmed, ' ');

        return $lastSpace > 0 ? rtrim(mb_substr($trimmed, 0, $lastSpace)) : $trimmed;
    }
}
