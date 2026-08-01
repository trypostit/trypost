<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Actions\Account\DeleteEmptyOwnedAccounts;
use App\Actions\Media\DeleteOrphanedMediaFiles;

/**
 * Result of settling a stranded member (or a batch of them).
 * Flush outside any held account lock so Stripe cancel is not serialized under it.
 */
final readonly class StrandedSettlement
{
    /**
     * @param  list<string>  $mediaPaths
     * @param  list<string>  $emptyAccountIds
     */
    public function __construct(
        public array $mediaPaths = [],
        public array $emptyAccountIds = [],
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public function merge(self $other): self
    {
        return new self(
            mediaPaths: [...$this->mediaPaths, ...$other->mediaPaths],
            emptyAccountIds: array_values(array_unique([
                ...$this->emptyAccountIds,
                ...$other->emptyAccountIds,
            ])),
        );
    }

    /**
     * Cancel/delete empty personal account shells, then remove orphaned media files.
     */
    public function flush(): void
    {
        DeleteEmptyOwnedAccounts::executeByIds($this->emptyAccountIds);
        DeleteOrphanedMediaFiles::execute($this->mediaPaths);
    }
}
