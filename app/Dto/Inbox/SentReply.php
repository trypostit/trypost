<?php

declare(strict_types=1);

namespace App\Dto\Inbox;

use App\Enums\Inbox\ReviewReplyState;
use Carbon\CarbonImmutable;

final readonly class SentReply
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $platformMessageId,
        public ?string $platformUrl,
        public CarbonImmutable $platformCreatedAt,
        public array $meta = [],
        public ?ReviewReplyState $reviewReplyState = null,
    ) {}
}
