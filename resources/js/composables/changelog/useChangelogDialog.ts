import { readonly, shallowRef } from 'vue';

import type { ChangelogEntry } from '@/types/changelog';

export interface ChangelogDialogState {
    id: number;
    entry: ChangelogEntry | null;
}

let nextId = 0;

const state = shallowRef<ChangelogDialogState | null>(null);

const openList = (): void => {
    state.value = { id: ++nextId, entry: null };
};

const openEntry = (entry: ChangelogEntry): void => {
    state.value = { id: state.value?.id ?? ++nextId, entry };
};

const backToList = (): void => {
    if (!state.value) return;

    state.value = { ...state.value, entry: null };
};

const close = (): void => {
    state.value = null;
};

export const useChangelogDialog = () => ({
    state: readonly(state),
    openList,
    openEntry,
    backToList,
    close,
});
