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

    public function adapt(
        Workspace $workspace,
        ?User $user,
        string $caption,
        Platform $platform,
    ): string {
        if ($platform->contentOverflow($this->sanitizer->displayText($caption, $platform)) === 0) {
            return $caption;
        }

        if (! $this->canUseAi($workspace, $user)) {
            return $this->truncate($caption, $platform);
        }

        return $this->shorten($workspace, $user, $caption, $platform, $platform->maxContentLength())
            ?? $this->truncate($caption, $platform);
    }

    private function shorten(
        Workspace $workspace,
        ?User $user,
        string $caption,
        Platform $platform,
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
     * Cuts the caption down until the text the publisher actually sends fits.
     * The limit cannot be applied to the raw caption: sanitizing shrinks it
     * (HTML comes off) or grows it (X rewrites every dot of a host), so each
     * pass rescales the cut by how far the sanitized form still overshoots.
     */
    private function truncate(string $caption, Platform $platform): string
    {
        $trimmed = $caption;

        while ($trimmed !== '') {
            $sanitized = $this->sanitizer->displayText($trimmed, $platform);
            $overflow = $platform->contentOverflow($sanitized);

            if ($overflow === 0) {
                return $trimmed;
            }

            $length = mb_strlen($trimmed);
            $ratio = ($sanitized === '') ? 0.0 : ($platform->maxContentLength() / mb_strlen($sanitized));

            $trimmed = $this->cutAtWord($trimmed, min((int) floor($length * $ratio), $length - 1));
        }

        return '';
    }

    private function cutAtWord(string $caption, int $limit): string
    {
        $trimmed = rtrim(mb_substr($caption, 0, max(1, $limit)));

        $lastSpace = mb_strrpos($trimmed, ' ');

        if ($lastSpace !== false && $lastSpace > 0) {
            $trimmed = mb_substr($trimmed, 0, $lastSpace);
        }

        return rtrim($trimmed);
    }
}
