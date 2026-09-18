<?php

declare(strict_types=1);

namespace App\Console\Commands\Telegram;

use App\Actions\SocialAccount\RegisterTelegramWebhook;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('telegram:set-webhook')]
#[Description('Register the Telegram bot webhook with the configured URL and secret token')]
class SetWebhook extends Command
{
    public function handle(): int
    {
        try {
            $url = RegisterTelegramWebhook::execute();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
