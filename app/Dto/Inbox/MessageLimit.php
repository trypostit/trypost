<?php

declare(strict_types=1);

namespace App\Dto\Inbox;

use App\Enums\Inbox\ReplyLengthUnit;

final readonly class MessageLimit
{
    public function __construct(
        public int $max,
        public ReplyLengthUnit $unit,
    ) {}

    public function measure(string $text): int
    {
        return $this->unit->measure($text);
    }

    public function fits(string $text): bool
    {
        return $this->measure($text) <= $this->max;
    }
}
