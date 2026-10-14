export type ChangelogTag = 'new' | 'beta';

export interface ChangelogEntry {
    id: string;
    slug: string;
    title: string;
    tags: ChangelogTag[];
    cover_url: string;
    published_on: string;
    excerpt: string;
    body_html: string;
    seen: boolean;
}

export interface SharedChangelog {
    latestUnseen: ChangelogEntry | null;
}

export interface ScrollChangelogEntries {
    data: ChangelogEntry[];
}
