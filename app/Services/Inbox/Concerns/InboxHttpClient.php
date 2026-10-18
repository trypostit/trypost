<?php

declare(strict_types=1);

namespace App\Services\Inbox\Concerns;

use App\Services\Social\Concerns\HasSocialHttpClient;

/**
 * The social HTTP client with the inbox's shorter per-request timeout
 * (trypost.inbox.http_timeout_seconds), so one slow call cannot eat the
 * poll's budget.
 */
trait InboxHttpClient
{
    use HasSocialHttpClient;

    private bool $sendingHttp = false;

    /**
     * A copy for calls that deliver something (a reply, a direct message):
     * they keep the normal social timeout, so a slow send that succeeds is
     * never reported as failed and posted twice by a retry.
     */
    public function forSending(): static
    {
        $copy = clone $this;
        $copy->sendingHttp = true;

        return $copy;
    }

    protected function socialHttpTimeout(): int
    {
        if ($this->sendingHttp) {
            return self::SOCIAL_HTTP_TIMEOUT_SECONDS;
        }

        return (int) config('trypost.inbox.http_timeout_seconds');
    }
}
