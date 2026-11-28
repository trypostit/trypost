import { router } from '@inertiajs/vue3';
import { readonly, ref, shallowReadonly, shallowRef } from 'vue';

import type { ChangelogEntry } from '@/types/changelog';

const isOpen = ref(false);
const entry = shallowRef<ChangelogEntry | null>(null);

const openList = (): void => {
    entry.value = null;
    isOpen.value = true;
};

const openEntry = (selected: ChangelogEntry): void => {
    entry.value = selected;
    isOpen.value = true;
};

const backToList = (): void => {
    entry.value = null;
};

const close = (): void => {
    isOpen.value = false;
    entry.value = null;
};

router.on('navigate', close);

export const useChangelogDialog = () => ({
    isOpen: readonly(isOpen),
    entry: shallowReadonly(entry),
    openList,
    openEntry,
    backToList,
    close,
});
