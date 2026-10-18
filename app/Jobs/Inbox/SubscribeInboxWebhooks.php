<?php

declare(strict_types=1);

namespace App\Jobs\Inbox;

use App\Enums\Inbox\ConversationKind;
use App\Enums\SocialAccount\Status;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\Social\SocialPublishException;
use App\Exceptions\TokenExpiredException;
use App\Models\InboxSyncState;
use App\Models\SocialAccount;
use App\Services\Inbox\Contracts\ReceivesInboxWebhooks;
use App\Services\Inbox\InboxDrivers;
use App\Support\Inbox\RedactedError;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

class SubscribeInboxWebhooks implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $uniqueFor = 3600;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public string $socialAccountId)
    {
        $this->onQueue('inbox');
    }

    public function uniqueId(): string
    {
        return $this->socialAccountId;
    }

    public function handle(): void
    {
        $account = SocialAccount::query()->with('workspace.account')->find($this->socialAccountId);
        $driver = $account ? app(InboxDrivers::class)->for($account->platform) : null;

        if (! $account
            || ! $driver instanceof ReceivesInboxWebhooks
            || $account->status !== Status::Connected
            || ! $account->platform->inboxEnabled()
            || ! $account->hasInboxAccess()
            || ! $account->workspace?->account?->hasAppAccess()) {
            return;
        }

        $state = InboxSyncState::query()->firstOrCreate(['social_account_id' => $account->id, 'kind' => ConversationKind::Comment]);

        try {
            $subscribed = $driver->subscribeWebhooks($account);
        } catch (TokenExpiredException $exception) {
            $account->markAsTokenExpired($exception->getMessage());

            return;
        } catch (PlatformUnavailableException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $state->forceFill(['last_error' => RedactedError::message($exception)])->save();

            if (! ($exception instanceof SocialPublishException && $exception->isLimit())) {
                RedactedError::report($exception);
            }

            return;
        }

        if ($subscribed) {
            $state->forceFill(['webhook_subscribed_at' => now(), 'last_error' => null])->save();
        }
    }
}
