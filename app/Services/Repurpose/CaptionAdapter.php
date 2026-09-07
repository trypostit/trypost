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
use Illuminate\Support\Str;

class CaptionAdapter
{
    /** @var array<string, string|null> */
    private array $shortened = [];

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

        $key = $platform->maxContentLength().':'.md5($caption);

        $shortened = $this->shortened[$key] ??= rescue(
            fn (): string => $this->ask($workspace, $user, $caption, $platform),
        );

        return filled($shortened) && $this->fits($shortened, $platform) ? $shortened : null;
    }

    private function ask(Workspace $workspace, User $user, string $caption, Platform $platform): string
    {
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

        return trim((string) $result->text);
    }

    private function truncate(string $caption, Platform $platform): string
    {
        $candidate = $caption;

        while (! $this->fits($candidate, $platform) && str_contains($candidate, ' ')) {
            $candidate = rtrim(Str::beforeLast($candidate, ' '));
        }

        return $this->fits($candidate, $platform)
            ? $candidate
            : Str::limit($caption, $platform->maxContentLength(), '');
    }
}
