<?php

declare(strict_types=1);

namespace App\Exceptions\Inbox;

use Illuminate\Validation\ValidationException;

/**
 * The network no longer accepts an answer in this direct message
 * conversation. A validation error keyed `text` on web, API and MCP.
 */
class ReplyWindowClosedException extends ValidationException {}
