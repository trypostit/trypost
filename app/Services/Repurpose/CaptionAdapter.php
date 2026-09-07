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

        if ($shortened === '' || ! $this->fits($shortened, $platform)) {
            return null;
        }

        return $shortened;
    }

    private function truncate(string $caption, Platform $platform): string
    {
        $words = explode(' ', $caption);

        $whole = $this->longestFitting(
            count($words),
            fn (int $take): string => rtrim(implode(' ', array_slice($words, 0, $take))),
            $platform,
        );

        if ($whole !== '') {
            return $whole;
        }

        return $this->longestFitting(
            mb_strlen($caption),
            fn (int $take): string => rtrim(mb_substr($caption, 0, $take)),
            $platform,
        );
    }

    /**
     * @param  callable(int): string  $take
     */
    private function longestFitting(int $most, callable $take, Platform $platform): string
    {
        $low = 0;
        $high = $most;

        while ($low < $high) {
            $middle = intdiv($low + $high + 1, 2);

            if ($this->fits($take($middle), $platform)) {
                $low = $middle;
            } else {
                $high = $middle - 1;
            }
        }

        return $take($low);
    }
}
