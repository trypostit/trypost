export interface RssFeed {
    id: string;
    display_title: string;
    url: string;
    site_url: string | null;
    icon_url: string | null;
    rss_feed_collection_id: string | null;
    last_succeeded_at: string | null;
    last_error: string | null;
    consecutive_failures: number;
}

export interface RssFeedCollection {
    id: string;
    name: string;
    feeds_count?: number;
}

export interface RssFeedItem {
    id: string;
    title: string;
    url: string | null;
    excerpt: string | null;
    image_url: string | null;
    published_at: string;
    feed: {
        id: string;
        display_title: string;
        icon_url: string | null;
    };
}

export interface RssFeedItemPage {
    data: RssFeedItem[];
}

export type RssFeedsScope =
    | { kind: 'all' }
    | { kind: 'feed'; feed: RssFeed }
    | { kind: 'collection'; collection: RssFeedCollection };


export interface RssFeedsLimits {
    max_feeds: number;
    feeds_count: number;
}

export interface RssFeedDirectoryEntry {
    name: string;
    url: string;
    icon_url: string | null;
    subscribed: boolean;
    feed_id: string | null;
}

export type RssFeedDirectoryCategoryKey =
    | 'favorites'
    | 'tech'
    | 'news'
    | 'business'
    | 'art_media'
    | 'entertainment'
    | 'science';

export interface RssFeedDirectoryCategory {
    key: RssFeedDirectoryCategoryKey;
    entries: RssFeedDirectoryEntry[];
}
