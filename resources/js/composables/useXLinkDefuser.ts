import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

import { contentForPlatform } from '@/lib/defuseXLinks';

/**
 * X publishes links defused (`acme(.)com`), so the editor has to count characters
 * and preview against that text rather than the draft.
 *
 * The TLD set arrives as a page prop from `App\Support\LinkTlds` instead of being
 * duplicated in the bundle, and the controller sends it only when defusing is on.
 * An empty set therefore means the feature is off and every platform gets its text
 * back untouched — without the list a bare host cannot be told from `Node.js`, so
 * defusing half of them would be worse than leaving them alone.
 */
export const useXLinkDefuser = () => {
    const page = usePage();

    const tlds = computed<ReadonlySet<string>>(
        () => new Set((page.props.xLinkTlds as string[] | undefined) ?? []),
    );

    const contentFor = (content: string, platform: string): string =>
        contentForPlatform(content, platform, tlds.value);

    return { contentFor };
};
