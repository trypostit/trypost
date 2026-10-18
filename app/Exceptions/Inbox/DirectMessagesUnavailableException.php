<?php

declare(strict_types=1);

namespace App\Exceptions\Inbox;

use RuntimeException;

/**
 * A queued direct message whose network has messages turned off, or whose
 * channel lost access to them, before it was sent.
 */
class DirectMessagesUnavailableException extends RuntimeException {}
