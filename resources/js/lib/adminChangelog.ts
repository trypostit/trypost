import type { ChangelogReaction } from '@/types/changelog';

/** How the backoffice shows a user's answer to a What's New entry. */
export const CHANGELOG_REACTIONS: Record<ChangelogReaction, { emoji: string; label: string; verb: string }> = {
    like: { emoji: '👍', label: 'Liked', verb: 'liked' },
    dislike: { emoji: '👎', label: 'Disliked', verb: 'disliked' },
};
