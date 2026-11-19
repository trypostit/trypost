<?php

declare(strict_types=1);

namespace App\Jobs\Meta;

use App\Actions\SocialAccount\DisconnectSocialAccount;
use App\Actions\SocialAccount\ExpireMetaUserChannels;
use App\Enums\SocialAccount\Platform;
use App\Models\DataDeletionRequest;
use App\Models\SocialAccount;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Deletes what TryPost stored about a Meta user who asked Meta for it, in
 * every workspace. Encrypted because it carries the user's id, which nothing
 * else keeps.
 *
 * - An Instagram or Threads channel is that person's own account: it goes the
 *   way "Disconnect" removes it, posts and media first.
 * - A Facebook Page, or an Instagram account connected through one, belongs to
 *   the business, and the person was one of its admins. The channel and its
 *   posts stay with the workspace; the person's id and every token their login
 *   gave are erased, and the owner is asked to reconnect it.
 */
class DeleteMetaUserData implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public DataDeletionRequest $deletionRequest,
        public string $userId,
    ) {}

    /**
     * A channel whose queue is busy is tried again later; the channels already
     * done stay counted and are not found again.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(): void
    {
        $unlinked = new Collection;

        SocialAccount::query()
            ->authorizedByMetaUser($this->deletionRequest->app, $this->userId)
            ->pluck('id')
            ->each(function (string $id) use ($unlinked): void {
                $account = SocialAccount::query()->find($id);

                if ($account === null) {
                    return;
                }

                if (in_array($account->platform, [Platform::Facebook, Platform::InstagramFacebook], true)) {
                    $unlinked->push(self::forgetAdmin($account));
                } else {
                    DisconnectSocialAccount::execute($account);
                }

                $this->deletionRequest->increment('channels_affected');
            });

        ExpireMetaUserChannels::execute(
            $unlinked->load('workspace.owner'),
            'The person who connected this channel asked Meta to delete their data',
        );

        $this->deletionRequest->complete();
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Meta data deletion request could not be completed', [
            'data_deletion_request_id' => $this->deletionRequest->id,
            'error' => $exception?->getMessage(),
        ]);
    }

    /**
     * Erases the admin's id and the tokens their login gave. The status stays
     * as it was so ExpireMetaUserChannels can tell who still needs telling.
     */
    private static function forgetAdmin(SocialAccount $account): SocialAccount
    {
        $account->forceFill([
            'access_token' => '',
            'refresh_token' => null,
            'meta' => Arr::except($account->meta ?? [], ['user_id', 'user_token']),
        ])->save();

        return $account;
    }
}
