import { useResizeObserver } from '@vueuse/core';
import { nextTick, readonly, ref, type Ref } from 'vue';

/**
 * Collapses a toolbar while its content no longer fits. `missingWidth`
 * returns how many pixels the content lacks; the toolbar remembers the
 * width it needed and expands again once it is that wide.
 */
export const useCollapseWhenTruncated = (
    container: Readonly<Ref<HTMLElement | null>>,
    missingWidth: () => number,
): { collapsed: Readonly<Ref<boolean>>; expand: () => void } => {
    const collapsed = ref(false);
    let widthToExpand: number | null = null;

    const fit = (): void => {
        const element = container.value;

        if (!element) {
            return;
        }

        if (collapsed.value) {
            if (widthToExpand !== null && element.clientWidth >= widthToExpand) {
                expand();
            }

            return;
        }

        const missing = missingWidth();

        if (missing > 0) {
            widthToExpand = element.clientWidth + missing;
            collapsed.value = true;
        }
    };

    const expand = (): void => {
        collapsed.value = false;
        nextTick(fit);
    };

    useResizeObserver(container, fit);

    return { collapsed: readonly(collapsed), expand };
};
