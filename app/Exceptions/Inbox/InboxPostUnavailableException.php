<?php

declare(strict_types=1);

namespace App\Exceptions\Inbox;

use RuntimeException;
use Throwable;

/**
 * The post a comment is under could not be imported yet: the network did not
 * return it (deleted, private, a lost permission or a passing error), or
 * TryPost cannot tell it apart from a post it is still publishing. A post the
 * workspace removed from TryPost is never imported again, so its comments
 * stop waiting at once.
 */
class InboxPostUnavailableException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $dismissed = false, ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    public static function dismissed(string $remoteId): self
    {
        return new self("The post {$remoteId} was removed from TryPost.", dismissed: true);
    }
}
