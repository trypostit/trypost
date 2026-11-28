/** The workspace's weeks in a row with a post, shared on every page for the sidebar badge (`ShowPostingStreak`). */
export interface PostingStreak {
    weeks: number;
    posted_this_week: boolean;
    /** The smallest "Top X%" the streak reaches among every workspace, or null below the last one. */
    top_percent: number | null;
}
