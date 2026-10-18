<?php

declare(strict_types=1);

namespace App\Dto\Inbox;

use Carbon\CarbonImmutable;

/**
 * A conversation poll's place: the time it reads back to and, when it
 * stopped at its budget, the page to resume from, the newest time seen so
 * far and the items of that page already read. Stored as a plain time once
 * the poll caught up.
 */
final readonly class ResumableCursor
{
    public function __construct(
        public ?CarbonImmutable $watermark,
        public ?string $after = null,
        public ?CarbonImmutable $newest = null,
        /** @var list<string> */
        public array $done = [],
    ) {}

    public static function from(?string $cursor): self
    {
        $decoded = json_decode((string) $cursor, true);

        if (is_array($decoded)) {
            return new self(
                filled(data_get($decoded, 'watermark')) ? CarbonImmutable::parse((string) data_get($decoded, 'watermark')) : null,
                transform(data_get($decoded, 'after'), fn (mixed $after): string => (string) $after),
                filled(data_get($decoded, 'newest')) ? CarbonImmutable::parse((string) data_get($decoded, 'newest')) : null,
                array_values(array_map(strval(...), (array) data_get($decoded, 'done', []))),
            );
        }

        return new self(filled($cursor) ? CarbonImmutable::parse((string) $cursor) : null);
    }

    public function stopAt(CarbonImmutable $since): CarbonImmutable
    {
        return $this->watermark ?? $since;
    }

    /**
     * Where the next poll starts: the page it stopped at (skipping the items
     * of it already read), or the newest time once every page was read.
     *
     * @param  list<string>  $done
     */
    public function next(?string $after, ?CarbonImmutable $newest, array $done = []): ?string
    {
        if (filled($after) || $done !== []) {
            $place = [
                'watermark' => $this->watermark?->toIso8601String(),
                'after' => $after,
                'newest' => $newest?->toIso8601String(),
            ];

            return (string) json_encode($done === [] ? $place : [...$place, 'done' => $done]);
        }

        return ($newest ?? $this->watermark)?->toIso8601String();
    }
}
