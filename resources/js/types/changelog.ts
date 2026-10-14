export type ChangelogTagValue = 'new' | 'improved' | 'fixed' | 'beta';

export interface ChangelogTag {
    value: ChangelogTagValue;
    label: string;
}

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
    latest: ChangelogEntry | null;
}

export interface ScrollChangelogEntries {
    data: ChangelogEntry[];
}
