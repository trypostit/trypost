<?php

declare(strict_types=1);

namespace App\Enums\Post;

enum CreatedVia: string
{
    case Web = 'web';
    case Mcp = 'mcp';
    case Api = 'api';
}
