<?php

declare(strict_types=1);

namespace App\Services\Inbox\Contracts;

use App\Models\SocialAccount;

interface ReceivesInboxWebhooks
{
    public function subscribeWebhooks(SocialAccount $account): bool;
}
