import { router, usePage } from '@inertiajs/vue3';
import { type Ref, shallowRef } from 'vue';

import { filled } from '@/lib/helpers';

interface UrlTabOptions<Tab extends string> {
    /** The tabs in order; the first opens when the URL names none. */
    tabs: readonly Tab[];
    /** The open tab as the server validated it; read from the URL when the server sends none. */
    initial?: Tab;
    /** The query key that carries the tab. */
    param?: string;
    /** The page's URL with `mergeQuery` merged into the current query (`index.url({ mergeQuery })`). */
    url?: (mergeQuery: Record<string, string>) => string;
    /** Replaces the URL update, for a tab that reloads data of its own. */
    onSelect?: (tab: Tab) => void;
}

/**
 * A page's open tab kept in its URL, so a reload or a shared link opens the
 * same one: picking a tab replaces the URL without a new history entry.
 */
export const useUrlTab = <Tab extends string>(
    options: UrlTabOptions<Tab>,
): { tab: Ref<Tab>; selectTab: (value: string | number) => void } => {
    const { tabs, initial, param = 'tab', url, onSelect } = options;
    const [fallback] = tabs;
    const page = usePage();

    const toTab = (value: unknown): Tab => {
        const known = tabs.find((tab) => tab === value);

        if (filled(known)) {
            return known;
        }

        return fallback;
    };

    const fromUrl = (): Tab => toTab(new URL(page.url, window.location.origin).searchParams.get(param));

    const initialTab = (): Tab => {
        if (filled(initial)) {
            return toTab(initial);
        }

        return fromUrl();
    };

    const tab: Ref<Tab> = shallowRef(initialTab());

    const selectTab = (value: string | number): void => {
        tab.value = toTab(value);

        if (filled(onSelect)) {
            onSelect(tab.value);

            return;
        }

        if (filled(url)) {
            router.replace({
                url: url({ [param]: tab.value }),
                preserveScroll: true,
                preserveState: true,
            });
        }
    };

    return { tab, selectTab };
};
