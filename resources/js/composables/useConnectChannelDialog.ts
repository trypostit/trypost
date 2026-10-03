import { readonly, shallowRef } from 'vue';

export type ConnectChannelStep =
    | 'grid'
    | 'details'
    | 'instagram'
    | 'instagram-facebook'
    | 'telegram';

export interface ConnectChannelState {
    id: number;
    flow: 'connect' | 'goal';
    step: ConnectChannelStep;
    reconnectId?: string;
    goalAccountId?: string;
    detailsPlatform?: string;
    canGoBack: boolean;
    parentCanGoBack?: boolean;
}

let nextId = 0;

const state = shallowRef<ConnectChannelState | null>(null);

const open = (): void => {
    state.value = { id: ++nextId, flow: 'connect', step: 'grid', canGoBack: false };
};

const openAt = (
    step: Exclude<ConnectChannelStep, 'grid' | 'details'>,
    reconnectId?: string,
): void => {
    state.value = { id: ++nextId, flow: 'connect', step, reconnectId, canGoBack: false };
};

const openGoal = (accountId: string): void => {
    state.value = {
        id: ++nextId,
        flow: 'goal',
        step: 'grid',
        goalAccountId: accountId,
        canGoBack: false,
    };
};

const goTo = (step: Exclude<ConnectChannelStep, 'grid' | 'details'>): void => {
    if (!state.value) return;
    state.value = {
        ...state.value,
        step,
        canGoBack: true,
        parentCanGoBack: state.value.canGoBack,
    };
};

const showDetails = (platform: string): void => {
    if (!state.value) return;
    state.value = {
        ...state.value,
        step: 'details',
        detailsPlatform: platform,
        canGoBack: true,
    };
};

const back = (): void => {
    if (!state.value) return;

    if (state.value.step === 'instagram-facebook') {
        state.value = {
            ...state.value,
            step: 'instagram',
            canGoBack: state.value.parentCanGoBack ?? false,
        };
        return;
    }

    state.value = {
        ...state.value,
        step: 'grid',
        detailsPlatform: undefined,
        canGoBack: false,
    };
};

const close = (): void => {
    state.value = null;
};

export const useConnectChannelDialog = () => ({
    state: readonly(state),
    open,
    openAt,
    openGoal,
    goTo,
    showDetails,
    back,
    close,
});
