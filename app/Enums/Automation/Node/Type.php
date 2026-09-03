<?php

declare(strict_types=1);

namespace App\Enums\Automation\Node;

enum Type: string
{
    case Trigger = 'trigger';
    case Generate = 'generate';
    case Delay = 'delay';
    case Condition = 'condition';
    case Publish = 'publish';
    case End = 'end';
    case FetchRss = 'fetch_rss';
    case HttpRequest = 'http_request';
}
