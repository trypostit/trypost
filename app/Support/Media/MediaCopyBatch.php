<?php

declare(strict_types=1);

namespace App\Support\Media;

use App\Models\Media;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * One save's view of the media it moved and copied. File copies are the only
 * non-transactional step of a save, so a failed save deletes every copy it
 * made; moved rows roll back with the transaction.
 */
final class MediaCopyBatch
{
    /** @var array<string, true> */
    private array $moved = [];

    /** @var list<string> */
    private array $copies = [];

    /** @var array<string, array<string, true>> */
    private array $releasedOwners = [];

    /**
     * @template TResult
     *
     * @param  Closure(self): TResult  $work
     * @return TResult
     */
    public static function run(Closure $work): mixed
    {
        $batch = new self;

        try {
            return DB::transaction(fn (): mixed => $work($batch));
        } catch (Throwable $exception) {
            $batch->discardCopies();

            throw $exception;
        }
    }

    public function wasMoved(string $mediaId): bool
    {
        return isset($this->moved[$mediaId]);
    }

    /**
     * An owner this batch deletes before it commits: its rows may be moved
     * once, like temporary uploads, instead of copied.
     */
    public function releaseOwner(string $ownerColumn, string $ownerId): void
    {
        $this->releasedOwners[$ownerColumn][$ownerId] = true;
    }

    public function canMove(Media $row): bool
    {
        if ($this->wasMoved($row->id)) {
            return false;
        }

        if ($row->collection === Media::COLLECTION_UPLOADS) {
            return true;
        }

        foreach ($this->releasedOwners as $ownerColumn => $ownerIds) {
            if (isset($ownerIds[(string) $row->{$ownerColumn}])) {
                return true;
            }
        }

        return false;
    }

    public function rememberMove(string $mediaId): void
    {
        $this->moved[$mediaId] = true;
    }

    /**
     * The copy is also deleted if an enclosing transaction rolls back after
     * `run()` returned.
     */
    public function rememberCopy(string $path): void
    {
        $this->copies[] = $path;

        DB::afterRollBack(fn () => Storage::delete($path));
    }

    public function discardCopies(): void
    {
        if ($this->copies !== []) {
            Storage::delete($this->copies);
        }

        $this->copies = [];
    }
}
