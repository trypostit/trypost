<?php

declare(strict_types=1);

namespace App\Actions\Inbox;

use App\Actions\Media\DeleteOwnedMedia;
use App\Dto\ImportedFile;
use App\Dto\RemoteFile;
use App\Events\InboxUpdated;
use App\Jobs\Inbox\StoreInboxMediaJob;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\Media;
use App\Models\Workspace;
use App\Services\Media\RemoteMediaImporter;
use App\Support\Inbox\InboxMediaSlots;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Copies every file an inbox row links on a network into TryPost's
 * storage. Each copy is downloaded as a temporary upload, then
 * re-pointed to its row (the message, or the conversation for its subject and
 * participant), which owns it from then on.
 */
class StoreInboxMedia
{
    public static function dispatchFor(InboxMessage $message): void
    {
        if (InboxMediaSlots::pending($message) !== []) {
            StoreInboxMediaJob::dispatch(StoreInboxMediaJob::MESSAGE, $message->id)->afterCommit();
        }

        $conversation = $message->loadMissing('conversation')->conversation;

        if ($conversation && InboxMediaSlots::pending($conversation) !== []) {
            StoreInboxMediaJob::dispatch(StoreInboxMediaJob::CONVERSATION, $conversation->id)->afterCommit();
        }
    }

    /**
     * A file the network refuses for good (too large, a type TryPost does
     * not store, an address the SSRF guard refuses) is marked unstored and
     * its slot stays empty.
     * An unreachable one waits for the next attempt, or is marked unstored on
     * the last. Returns whether a file waits for another attempt.
     */
    public static function execute(InboxMessage|InboxConversation $target, bool $lastAttempt, int $timeoutSeconds): bool
    {
        $pending = InboxMediaSlots::pending($target);
        $workspace = $pending === [] ? null : Workspace::query()->find(self::workspaceId($target));

        if (! $workspace) {
            return false;
        }

        $importer = app(RemoteMediaImporter::class);
        $deadline = now()->addSeconds(intdiv($timeoutSeconds, 2));
        $results = [];
        $retry = false;

        foreach ($pending as $slot => $url) {
            $imported = $importer->import($workspace, self::remoteFile($url), InboxMediaSlots::allowedTypes($target, $slot), $timeoutSeconds, $deadline, followRedirects: true);

            if (! $imported->succeeded() && $imported->failure === ImportedFile::UNREACHABLE && ! $lastAttempt) {
                $retry = true;

                continue;
            }

            $results[] = ['slot' => $slot, 'url' => $url, 'stored' => $imported->media ?? InboxMediaSlots::UNSTORED];
        }

        self::write($target, $results);

        return $retry;
    }

    /**
     * Marks every pending file unstored, for a job that will not run again.
     */
    public static function giveUp(InboxMessage|InboxConversation $target): void
    {
        self::write($target, collect(InboxMediaSlots::pending($target))
            ->map(fn (string $url, string $slot): array => ['slot' => $slot, 'url' => $url, 'stored' => InboxMediaSlots::UNSTORED])
            ->values()
            ->all());
    }

    /**
     * A slot whose pending link changed since the download (a newer read,
     * another job) keeps waiting for the new one, and the copy made for it is
     * deleted. A stored slot holds the copy's path.
     *
     * @param  list<array{slot: string, url: string, stored: Media|string}>  $results
     */
    private static function write(InboxMessage|InboxConversation $target, array $results): void
    {
        if ($results === []) {
            return;
        }

        $downloaded = collect($results)->pluck('stored')->filter(fn (Media|string $stored): bool => $stored instanceof Media)->map(fn (Media $media): string => $media->id)->values()->all();

        try {
            $adopted = DB::transaction(function () use ($target, $results, $downloaded): int {
                $locked = $target::query()->whereKey($target->getKey())->lockForUpdate()->first();

                if (! $locked) {
                    DeleteOwnedMedia::forRows($downloaded);

                    return 0;
                }

                $source = InboxMediaSlots::source($locked);
                $discarded = [];
                $adopted = 0;

                foreach ($results as $result) {
                    $slot = data_get($result, 'slot');
                    $stored = data_get($result, 'stored');

                    if (InboxMediaSlots::pendingLinkOf($source, $slot) !== data_get($result, 'url') || filled(InboxMediaSlots::markerOf($source, $slot))) {
                        if ($stored instanceof Media) {
                            $discarded[] = $stored->id;
                        }

                        continue;
                    }

                    if ($stored instanceof Media) {
                        $stored->update([
                            $locked instanceof InboxMessage ? 'inbox_message_id' : 'inbox_conversation_id' => $locked->getKey(),
                            'mediable_type' => null,
                            'mediable_id' => null,
                            'collection' => Media::COLLECTION_MEDIA,
                            'upload_token' => null,
                        ]);
                        data_set($source, $slot, $stored->path);
                        $adopted++;
                    }

                    $source = InboxMediaSlots::withMarker($source, $slot, $stored instanceof Media ? $stored->id : $stored);
                }

                DeleteOwnedMedia::forRows($discarded);

                $locked->timestamps = false;
                $locked->forceFill($source)->save();

                return $adopted;
            });
        } catch (Throwable $exception) {
            DB::transaction(fn () => DeleteOwnedMedia::forRows($downloaded));

            throw $exception;
        }

        if ($adopted > 0) {
            InboxUpdated::dispatch((string) self::workspaceId($target), $target instanceof InboxMessage ? $target->inbox_conversation_id : $target->id);
        }
    }

    private static function workspaceId(InboxMessage|InboxConversation $target): ?string
    {
        return $target instanceof InboxConversation
            ? $target->workspace_id
            : InboxConversation::query()->whereKey($target->inbox_conversation_id)->value('workspace_id');
    }

    /**
     * Network links often carry no file name (`/ig_messaging_cdn/?asset_id=…`),
     * so a name without an extension is left for the importer to derive from
     * the file's type.
     */
    private static function remoteFile(string $url): RemoteFile
    {
        $name = basename((string) parse_url($url, PHP_URL_PATH));

        return new RemoteFile($url, pathinfo($name, PATHINFO_EXTENSION) === '' ? '' : $name);
    }
}
