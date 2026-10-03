<?php

declare(strict_types=1);

namespace App\Support;

class RandomMinute
{
    public function __invoke(): int
    {
        return random_int(0, 59);
    }
}
