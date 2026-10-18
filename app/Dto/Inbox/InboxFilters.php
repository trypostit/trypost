<?php

declare(strict_types=1);

namespace App\Dto\Inbox;

use App\Enums\Inbox\DateRange;
use App\Enums\Inbox\InboxTab;
use App\Enums\Inbox\PostsFilter;
use App\Enums\Inbox\Sort;
use App\Enums\Inbox\StatusFilter;

final readonly class InboxFilters
{
    /**
     * @param  list<string>  $channelIds  the channels to keep; empty means every channel
     * @param  string|null  $scopedChannelId  the channel whose own inbox page this is (channels/{account}/inbox)
     */
    public function __construct(
        public array $channelIds,
        public StatusFilter $status,
        public Sort $sort,
        public DateRange $range,
        public InboxTab $tab = InboxTab::DEFAULT,
        public PostsFilter $posts = PostsFilter::DEFAULT,
        public ?string $scopedChannelId = null,
    ) {}

    /** The same filters on one channel's own inbox page, where the URL holds the channel. */
    public function forChannel(string $channelId): self
    {
        return new self([$channelId], $this->status, $this->sort, $this->range, $this->tab, $this->posts, $channelId);
    }

    /**
     * "Show all posts" lists the posts of the Comments tab, including those
     * nobody commented on yet; it means nothing on the other tabs.
     */
    public function listsAllPosts(): bool
    {
        return $this->posts === PostsFilter::All && $this->tab === InboxTab::Comments;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        return new self(
            channelIds: collect((array) data_get($validated, 'channels', []))->filter(fn (mixed $id): bool => filled($id))->map(fn (mixed $id): string => (string) $id)->unique()->values()->all(),
            status: StatusFilter::tryFrom((string) data_get($validated, 'status')) ?? StatusFilter::DEFAULT,
            sort: Sort::tryFrom((string) data_get($validated, 'sort')) ?? Sort::DEFAULT,
            range: DateRange::tryFrom((string) data_get($validated, 'range')) ?? DateRange::DEFAULT,
            tab: InboxTab::tryFrom((string) data_get($validated, 'tab')) ?? InboxTab::DEFAULT,
            posts: PostsFilter::tryFrom((string) data_get($validated, 'posts')) ?? PostsFilter::DEFAULT,
        );
    }
}
