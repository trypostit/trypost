<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';

import ConnectChannelFlow from '@/components/channels/ConnectChannelFlow.vue';
import PostingGoalFlow from '@/components/channels/PostingGoalFlow.vue';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Dialog, DialogScrollContent } from '@/components/ui/dialog';
import {
    useConnectChannelDialog,
    type ConnectChannelStep,
} from '@/composables/useConnectChannelDialog';
import { useOAuthPopup } from '@/composables/useOAuthPopup';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import { settings as channelSettings } from '@/routes/app/channels';
import { Platform } from '@/types/platform';
import type { AvailablePlatform } from '@/types/social-account';

const IN_PROGRESS_NETWORKS: Partial<Record<ConnectChannelStep, string>> = {
    instagram: Platform.Instagram,
    'instagram-facebook': Platform.Instagram,
    telegram: Platform.Telegram,
};

const page = usePage();
const platforms = computed<AvailablePlatform[]>(
    () => (page.props.connectablePlatforms as AvailablePlatform[]) ?? [],
);

const { state, close, openGoal } = useConnectChannelDialog();

const confirmingExit = ref(false);

const inProgressNetwork = computed(() =>
    state.value?.flow === 'connect'
        ? IN_PROGRESS_NETWORKS[state.value.step]
        : undefined,
);

const networkName = ref('');

const requestClose = (isOpen: boolean): void => {
    if (isOpen) return;

    if (inProgressNetwork.value) {
        networkName.value = getPlatformLabel(inProgressNetwork.value);
        confirmingExit.value = true;
        return;
    }

    close();
};

const exitConnection = (): void => {
    confirmingExit.value = false;
    close();
};

const focusDialogFrame = (event: Event): void => {
    event.preventDefault();
    (event.target as HTMLElement | null)?.focus({ preventScroll: true });
};

interface ConnectResult {
    accountId: string | null;
    created: boolean;
}

const handleConnected = (result: ConnectResult): void => {
    router.reload();

    if (result.created && result.accountId) {
        openGoal(result.accountId);
    }
};

const handleFlowConnected = (result: ConnectResult): void => {
    handleConnected(result);

    if (state.value?.flow !== 'goal') {
        close();
    }
};

useOAuthPopup((result) => {
    if (!result.success) {
        toast.error(result.message);
        return;
    }

    handleConnected(result);
});

const customize = (accountId: string): void => {
    close();
    router.visit(channelSettings.url(accountId));
};
</script>

<template>
    <Dialog
        :open="state !== null"
        @update:open="requestClose"
    >
        <DialogScrollContent
            v-if="state"
            :key="state.id"
            :aria-describedby="undefined"
            @open-auto-focus="focusDialogFrame"
            :class="
                state.flow === 'goal'
                    ? 'gap-0 overflow-hidden p-0 outline-none sm:max-w-[800px]'
                    : [
                          'flex flex-col gap-0 overflow-hidden px-0 pt-4 outline-none pb-0 max-sm:my-0 max-sm:h-dvh max-sm:max-w-full max-sm:rounded-none sm:my-6 sm:h-[min(700px,calc(100dvh-48px))] sm:max-w-[840px] sm:rounded-xl',
                          state.step === 'grid' || state.step === 'details'
                              ? 'bg-muted pt-0 sm:border-8 sm:border-card sm:bg-card'
                              : '',
                      ]
            "
            data-testid="connect-channel-dialog"
        >
            <PostingGoalFlow
                v-if="state.flow === 'goal' && state.goalAccountId"
                :account-id="state.goalAccountId"
                @done="close"
                @customize="customize(state.goalAccountId)"
            />
            <ConnectChannelFlow
                v-else
                :state="state"
                :platforms="platforms"
                @close="close"
                @connected="handleFlowConnected"
            />
            <AlertDialog v-model:open="confirmingExit">
                <AlertDialogContent
                    class="gap-0 px-0 pt-6 pb-0 sm:max-w-[512px]"
                    data-testid="connect-channel-exit-confirm"
                >
                    <AlertDialogHeader class="gap-3 px-6 pb-4 text-left">
                        <AlertDialogTitle class="font-sans text-base font-medium">
                            {{ $t('channels.dialog.exit_confirm.title', { network: networkName }) }}
                        </AlertDialogTitle>
                        <AlertDialogDescription class="text-sm text-foreground">
                            {{
                                $t('channels.dialog.exit_confirm.description', {
                                    network: networkName,
                                })
                            }}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter
                        class="flex-col border-t border-border px-6 py-4 sm:flex-row sm:justify-end"
                    >
                        <AlertDialogCancel
                            class="mt-0"
                            data-testid="connect-channel-exit-continue"
                        >
                            {{ $t('channels.dialog.exit_confirm.continue') }}
                        </AlertDialogCancel>
                        <Button
                            variant="destructive"
                            size="lg"
                            data-testid="connect-channel-exit-leave"
                            @click="exitConnection"
                        >
                            {{ $t('channels.dialog.exit_confirm.exit') }}
                        </Button>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </DialogScrollContent>
    </Dialog>
</template>
