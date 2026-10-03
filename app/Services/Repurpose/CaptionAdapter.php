<?php

declare(strict_types=1);

namespace App\Services\Repurpose;

use App\Ai\Agents\PostContentShortener;
use App\Enums\SocialAccount\Platform;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\ContentSanitizer;
use App\Support\Hashtags;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class CaptionAdapter
{
    /** @var array<string, string|null> */
    private array $shortened = [];

    public function __construct(private readonly ContentSanitizer $sanitizer) {}

    /**
     * Hashtags past the network's cap are dropped before the length is fitted.
     *
     * @param  int|null  $limit  The account's own cap; the platform's when null.
     */
    public function adapt(Workspace $workspace, ?User $user, string $caption, Platform $platform, ?int $limit = null): string
    {
        $limit ??= $platform->maxContentLength();
        $caption = $platform->maxHashtags() === null ? $caption : Hashtags::keepFirst($caption, $platform->maxHashtags());

        if ($this->fits($caption, $platform, $limit)) {
            return $caption;
        }

        return $this->shorten($workspace, $user, $caption, $platform, $limit)
            ?? $this->truncate($caption, $platform, $limit);
    }

    private function sent(string $caption, Platform $platform): string
    {
        return $this->sanitizer->displayText($caption, $platform);
    }

    private function fits(string $caption, Platform $platform, int $limit): bool
    {
        return mb_strlen($this->sent($caption, $platform)) <= $limit;
    }

    private function shorten(Workspace $workspace, ?User $user, string $caption, Platform $platform, int $limit): ?string
    {
        if ($user === null || Gate::forUser($user)->denies('useAi', $workspace->account)) {
            return null;
        }

        $key = "{$limit}:".md5($caption);

        $shortened = $this->shortened[$key] ??= rescue(
            fn (): string => $this->ask($caption, $platform, $limit),
        );

        return filled($shortened) && $this->fits($shortened, $platform, $limit) ? $shortened : null;
    }

    private function ask(string $caption, Platform $platform, int $limit): string
    {
        $result = (new PostContentShortener(
            platformLabel: $platform->label(),
            limit: $limit,
        ))->prompt($caption);

        return trim((string) $result->text);
    }

    private function truncate(string $caption, Platform $platform, int $limit): string
    {
        $candidate = $caption;

        while (! $this->fits($candidate, $platform, $limit) && str_contains($candidate, ' ')) {
            $candidate = rtrim(Str::beforeLast($candidate, ' '));
        }

        return $this->fits($candidate, $platform, $limit)
            ? $candidate
            : Str::limit($caption, $limit, '');
    }
}
