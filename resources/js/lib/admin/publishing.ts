import { filled } from '@/lib/helpers';
import type { PostCreatedVia } from '@/types/admin/posts';

/** A failure rate, in percent, at or above which a network or an origin reads as in trouble. */
export const ALARMING_FAILURE_RATE = 10;

/** Where a post was made, as the admin names it; keyed by text so the stats' plain origin keys read it too. */
const ORIGIN_LABELS: Record<string, string> = {
    web: 'Web app',
    api: 'REST API',
    mcp: 'AI apps (MCP)',
    repurpose: 'Repurpose',
} satisfies Record<PostCreatedVia, string>;

/** An origin's name; one added on the server later shows under its own key. */
export const originLabel = (origin: string): string => {
    const label = ORIGIN_LABELS[origin];

    if (filled(label)) {
        return label;
    }

    return origin;
};
