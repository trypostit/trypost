<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Actions\Media\DeleteOrphanedMediaFiles;

/**
 * Result of settling stranded members: media paths to remove from storage.
 * Flush outside any held lock so filesystem I/O is not serialized under it.
 */
final readonly class StrandedSettlement
{
    /**
     * @param  list<string>  $mediaPaths
     */
    public function __construct(
        public array $mediaPaths = [],
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public function merge(self $other): self
    {
        return new self(
            mediaPaths: [...$this->mediaPaths, ...$other->mediaPaths],
        );
    }

    public function flush(): void
    {
        DeleteOrphanedMediaFiles::execute($this->mediaPaths);
    }
}
