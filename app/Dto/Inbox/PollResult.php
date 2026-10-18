<?php

declare(strict_types=1);

namespace App\Dto\Inbox;

use Carbon\CarbonInterface;

final readonly class PollResult
{
    /**
     * @param  list<IncomingMessage>  $messages
     * @param  CarbonInterface|null  $retryAt  when a poll stopped at the network's limit, when that limit lifts
     * @param  bool  $partial  the poll stopped at its budget and the cursor resumes where it stopped
     */
    public function __construct(
        public array $messages,
        public ?string $cursor,
        public ?CarbonInterface $retryAt = null,
        public bool $partial = false,
    ) {}
}
