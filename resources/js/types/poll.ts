/**
 * A network's poll limits, served by `Platform::publishConfig()`
 * (`publishConfig.poll`, from the server's `Poll::limits()`). The length is
 * null on a network that sets it itself (Threads), and the question cap null
 * where the account's text limit applies.
 */
export interface PollLimits {
    minOptions: number;
    maxOptions: number;
    optionMaxLength: number;
    minDuration: number | null;
    maxDuration: number | null;
    defaultDuration: number | null;
    questionMaxLength: number | null;
    /** Mastodon: the author may let a voter pick several choices. */
    multipleChoice: boolean;
    /** Mastodon: the author may hide the votes until the poll ends. */
    hideTotals: boolean;
}

/** A poll's length split into the units the editor picks. */
export interface PollDuration {
    days: number;
    hours: number;
    minutes: number;
}

/** A poll as `meta.poll` (or a thread reply's `poll`) stores it; each network keeps only what it takes. */
export interface Poll {
    options: string[];
    duration_minutes?: number;
    multiple?: boolean;
    hide_totals?: boolean;
}
