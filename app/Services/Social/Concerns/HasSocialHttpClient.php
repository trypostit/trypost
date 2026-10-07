<?php

declare(strict_types=1);

namespace App\Services\Social\Concerns;

use App\Exceptions\Social\ContentLimitException;
use App\Models\PostPlatform;
use App\Services\Social\ContentSanitizer;
use App\Services\Social\TokenRedactor;
use App\Support\ThreadReplies;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

trait HasSocialHttpClient
{
    /**
     * Measures the sanitized content — the exact string the publisher sends —
     * rather than the stored draft. The two differ by more than markup: X
     * defusing rewrites URLs, Telegram escapes entities, and every platform
     * strips HTML the editor stored. Checking the raw draft both rejected
     * posts that would have fit and let through posts the network rejects.
     * A captionless content type sends no text and is not measured. The
     * hashtag cap is a save-time rule only, so a post stored before it still
     * publishes.
     */
    protected function validateContentLength(PostPlatform $postPlatform): void
    {
        if ($postPlatform->content_type?->isCaptionless()) {
            return;
        }

        $raw = $postPlatform->post->content ?? '';
        $content = app(ContentSanitizer::class)->displayText($raw, $postPlatform->platform);

        $threadViolation = ThreadReplies::violation($postPlatform->socialAccount ?? $postPlatform->platform, $postPlatform->meta);

        if ($threadViolation !== null) {
            throw new ContentLimitException($postPlatform->platform, $threadViolation[1]);
        }

        $reserved = $postPlatform->platform->reservedLength($postPlatform->meta);
        $overflow = $postPlatform->socialAccount?->contentOverflow($content, $reserved) ?? $postPlatform->platform->contentOverflow($content, $reserved);

        if ($overflow === 0) {
            return;
        }

        $maxLength = $postPlatform->socialAccount?->maxContentLength() ?? $postPlatform->platform->maxContentLength();

        $contentLength = mb_strlen($content) + $reserved;

        throw ContentLimitException::exceeds($postPlatform->platform, $maxLength, $contentLength);
    }

    private bool $interactiveHttp = false;

    /**
     * A copy for reads a person is waiting on (the composer): each call gives
     * up after a few seconds and a 429 is not retried, so one slow or
     * rate-limited network cannot hold the page.
     */
    public function interactive(): static
    {
        $copy = clone $this;
        $copy->interactiveHttp = true;

        return $copy;
    }

    protected function socialHttp(): PendingRequest
    {
        if ($this->interactiveHttp) {
            return Http::connectTimeout(3)->timeout(5);
        }

        return Http::retry(
            times: 3,
            sleepMilliseconds: 5000,
            when: fn ($exception, $request) => ($exception->response ?? null)?->status() === 429,
            throw: false,
        )->timeout(120);
    }

    protected function redactResponseBody(string $body): string
    {
        return TokenRedactor::redact($body);
    }

    /**
     * Reclaim memory between chunks of a streaming upload. Each request's body
     * and the HTTP client's request/response objects are held alive by reference
     * cycles the PHP runtime only frees when the cycle collector runs, so a
     * long chunked upload accumulates the whole file in memory without this.
     * Callers must `unset()` the chunk and response first.
     */
    protected function freeChunkMemory(): void
    {
        gc_collect_cycles();
    }
}
