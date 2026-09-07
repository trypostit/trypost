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
use Illuminate\Support\Str;
use Throwable;

class CaptionAdapter
{
    public function __construct(private readonly ContentSanitizer $sanitizer) {}

    public function adapt(Workspace $workspace, ?User $user, string $caption, Platform $platform): string
    {
        if ($this->fits($caption, $platform)) {
            return $caption;
        }

        return $this->shorten($workspace, $user, $caption, $platform)
            ?? $this->truncate($caption, $platform);
    }

    private function sent(string $caption, Platform $platform): string
    {
        return $this->sanitizer->displayText($caption, $platform);
    }

    private function fits(string $caption, Platform $platform): bool
    {
        return $platform->contentOverflow($this->sent($caption, $platform)) === 0;
    }

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

        return $shortened !== '' && $this->fits($shortened, $platform) ? $shortened : null;
    }

    private function truncate(string $caption, Platform $platform): string
    {
        while (! $this->fits($caption, $platform)) {
            $caption = $this->cutAtWord($caption, $this->fittingLength($caption, $platform));
        }

        return $caption;
    }

    private function fittingLength(string $caption, Platform $platform): int
    {
        $length = mb_strlen($caption);
        $scaled = $length * $platform->maxContentLength() / mb_strlen($this->sent($caption, $platform));

        return min((int) $scaled, $length - 1);
    }

    private function cutAtWord(string $caption, int $limit): string
    {
        $cut = rtrim(mb_substr($caption, 0, $limit));
        $boundary = rtrim(Str::beforeLast($cut, ' '));

        return $boundary === '' ? $cut : $boundary;
    }
}
